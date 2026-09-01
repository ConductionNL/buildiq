import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ExternalFormAccessDialog.vue.
 *
 * Spec: external-form-provisioning (REQ-EFP-002, REQ-EFP-003, REQ-EFP-004, REQ-EFP-005).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const service = vi.hoisted(() => ({
	enablePublicCreate: vi.fn(),
	revokePublicCreate: vi.fn(),
	provisionPortalPage: vi.fn(),
	draftPortalPage: vi.fn(),
}))
vi.mock('../../src/services/externalFormProvisioningService.js', () => service)
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import ExternalFormAccessDialog from '../../src/dialogs/ExternalFormAccessDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label'],
		template:
			'<input class="text-stub" @input="$emit(\'update:value\', $event.target.value)" >',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note-stub"><slot /></div>',
	},
}

function factory(props = {}) {
	return mount(ExternalFormAccessDialog, {
		propsData: {
			open: false,
			register: 'intake',
			schema: 'report',
			pageId: 'page-1',
			...props,
		},
		stubs,
	})
}

describe('ExternalFormAccessDialog', () => {
	beforeEach(() => {
		Object.values(service).forEach((fn) => fn.mockReset())
	})

	it('enabling provisions OR authorization + a portalPage and emits the resolved entry', async () => {
		service.enablePublicCreate.mockResolvedValue({})
		service.provisionPortalPage.mockResolvedValue({
			objectId: 'pp-1',
			portalPath: '/portal',
			unavailable: false,
		})
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		wrapper.vm.enabled = true
		await wrapper.vm.onSave()
		expect(service.enablePublicCreate).toHaveBeenCalledWith({
			schema: 'report',
			publicRead: false,
		})
		expect(service.provisionPortalPage).toHaveBeenCalledWith({
			register: 'intake',
			schema: 'report',
			objectId: null,
		})
		const saved = wrapper.emitted().save[0][0]
		expect(saved).toMatchObject({
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
			portalPage: { objectId: 'pp-1', portalPath: '/portal' },
		})
		expect(saved.id).toMatch(/^ef-/)
	})

	it('degrades gracefully when Portaliq is unavailable — save still completes with portalPage null + hint', async () => {
		service.enablePublicCreate.mockResolvedValue({})
		service.provisionPortalPage.mockResolvedValue({
			objectId: null,
			portalPath: null,
			unavailable: true,
		})
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		wrapper.vm.enabled = true
		await wrapper.vm.onSave()
		expect(service.enablePublicCreate).toHaveBeenCalled()
		const saved = wrapper.emitted().save[0][0]
		expect(saved.status).toBe('enabled')
		expect(saved.portalPage).toBeNull()
		expect(wrapper.vm.portalHint).toBe(true)
	})

	it('a repeat save updates the SAME portalPage object (matched by stored objectId)', async () => {
		service.enablePublicCreate.mockResolvedValue({})
		service.provisionPortalPage.mockResolvedValue({
			objectId: 'pp-1',
			portalPath: '/portal',
			unavailable: false,
		})
		const entry = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
			publicRead: false,
			organisationScope: null,
			portalPage: { objectId: 'pp-1', portalPath: '/portal' },
			trackLinkAction: { enabled: false },
		}
		const wrapper = factory({ entry })
		await wrapper.setProps({ open: true })
		await wrapper.vm.onSave()
		expect(service.provisionPortalPage).toHaveBeenCalledWith({
			register: 'intake',
			schema: 'report',
			objectId: 'pp-1',
		})
		const saved = wrapper.emitted().save[0][0]
		expect(saved.id).toBe('ef-1')
	})

	it('disabling revokes authorization and drafts the linked portalPage, never deleting it', async () => {
		service.revokePublicCreate.mockResolvedValue({})
		service.draftPortalPage.mockResolvedValue({})
		const entry = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
			publicRead: true,
			organisationScope: null,
			portalPage: { objectId: 'pp-1', portalPath: '/portal' },
			trackLinkAction: { enabled: false },
		}
		const wrapper = factory({ entry })
		await wrapper.setProps({ open: true })
		await wrapper.vm.onDisable()
		expect(service.revokePublicCreate).toHaveBeenCalledWith({
			schema: 'report',
			removeRead: true,
		})
		expect(service.draftPortalPage).toHaveBeenCalledWith('pp-1')
		const saved = wrapper.emitted().save[0][0]
		expect(saved.status).toBe('disabled')
		// portalPage entry is preserved (draftPortalPage sets status server-side, not deleted).
		expect(saved.portalPage).toEqual({ objectId: 'pp-1', portalPath: '/portal' })
	})

	it('disabling with no linked portalPage is a no-op for the Portaliq leg', async () => {
		service.revokePublicCreate.mockResolvedValue({})
		const entry = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
			publicRead: false,
			organisationScope: null,
			portalPage: null,
			trackLinkAction: { enabled: false },
		}
		const wrapper = factory({ entry })
		await wrapper.setProps({ open: true })
		await wrapper.vm.onDisable()
		expect(service.draftPortalPage).not.toHaveBeenCalled()
	})

	it('surfaces an error message and does not emit save on failure', async () => {
		service.enablePublicCreate.mockRejectedValue(new Error('403'))
		const wrapper = factory()
		await wrapper.setProps({ open: true })
		wrapper.vm.enabled = true
		await wrapper.vm.onSave()
		// The stubbed t() in tests/vitest/setup.js returns the raw i18n key
		// without interpolating placeholders (see PageDesigner.spec.js note).
		expect(wrapper.vm.errorMessage).toContain(
			'Could not provision external access',
		)
		expect(wrapper.emitted().save).toBeUndefined()
	})
})
