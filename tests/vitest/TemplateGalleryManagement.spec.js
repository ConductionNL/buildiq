/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the save-as-template GALLERY MANAGEMENT additions
 * in `src/views/TemplateGallery.vue` (REQ-SAT-005).
 *
 * Covers:
 *   - Org-local (isSeeded:false) templates render the "Organisation template"
 *     badge; seeded templates do not.
 *   - Edit/Delete actions render only for org-local templates the caller may
 *     write (OR per-object rights — ownership guard); seeded cards stay
 *     read-only (REQ-OBTC-008 regression).
 *   - Delete confirm removes only the template record via OR REST and refreshes.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: { name: 'CloneTemplateDialog', props: ['open', 'template'], render() { return null } },
}))
vi.mock('../../src/dialogs/EditTemplateMetadataDialog.vue', () => ({
	default: { name: 'EditTemplateMetadataDialog', props: ['open', 'template'], render() { return null } },
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const templates = [
	{ uuid: 'seed-1', slug: 'permit-tracker', title: 'Permit Tracker', category: 'government-services', isSeeded: true },
	{ uuid: 'org-1', slug: 'permit-pack', title: 'Permit Pack', category: 'government-services', isSeeded: false, '@self': { id: 'org-1', canWrite: true } },
	{ uuid: 'org-2', slug: 'readonly-pack', title: 'Readonly Pack', category: 'field-work', isSeeded: false, '@self': { id: 'org-2', canWrite: false } },
]

const STUBS = {
	NcButton: { name: 'NcButton', props: ['type'], template: '<button class="nc-button-stub" @click="$emit(\'click\')"><slot /></button>' },
	NcTextField: { name: 'NcTextField', props: ['value'], template: '<input />' },
	NcSelect: { name: 'NcSelect', props: ['value', 'options'], template: '<select />' },
	NcLoadingIcon: true,
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div />' },
	NcDialog: { name: 'NcDialog', props: ['open', 'name'], template: '<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>' },
}

/**
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountGallery() {
	axiosMock.get.mockResolvedValueOnce({ data: { results: templates } })
	const wrapper = mount(TemplateGallery, {
		mocks: { $router: { resolve: vi.fn(), push: vi.fn() } },
		stubs: STUBS,
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('TemplateGallery.vue — org-local management (REQ-SAT-005)', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.delete.mockReset()
	})

	it('renders the Organisation template badge only on org-local cards', async () => {
		const wrapper = await mountGallery()
		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(3)
		const badges = wrapper.findAll('.template-card__badge')
		// permit-pack + readonly-pack are org-local → 2 badges.
		expect(badges.length).toBe(2)
	})

	it('isOrgLocal / canManage gate management actions on writability', async () => {
		const wrapper = await mountGallery()
		const vm = wrapper.vm
		expect(vm.isOrgLocal(templates[0])).toBe(false) // seeded
		expect(vm.isOrgLocal(templates[1])).toBe(true)
		expect(vm.canManage(templates[0])).toBe(false) // seeded never manageable
		expect(vm.canManage(templates[1])).toBe(true) // writable org-local
		expect(vm.canManage(templates[2])).toBe(false) // non-writable org-local
	})

	it('keeps seeded cards read-only — no Edit/Delete (REQ-OBTC-008 regression)', async () => {
		const wrapper = await mountGallery()
		const seededCard = wrapper.findAll('.template-card').at(0)
		const buttons = seededCard.findAll('.nc-button-stub').wrappers.map((b) => b.text())
		expect(buttons).toEqual(['Use this template'])
	})

	it('writable org-local card exposes Edit + Delete + Use', async () => {
		const wrapper = await mountGallery()
		const orgCard = wrapper.findAll('.template-card').at(1)
		const buttons = orgCard.findAll('.nc-button-stub').wrappers.map((b) => b.text())
		expect(buttons).toEqual(expect.arrayContaining(['Edit', 'Delete', 'Use this template']))
	})

	it('confirmDelete removes only the template record via OR REST and refreshes', async () => {
		const wrapper = await mountGallery()
		axiosMock.delete.mockResolvedValueOnce({ data: {} })
		axiosMock.get.mockResolvedValueOnce({ data: { results: [templates[0]] } })

		wrapper.vm.openDelete(templates[1])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.deleteOpen).toBe(true)

		await wrapper.vm.confirmDelete()

		expect(axiosMock.delete).toHaveBeenCalledTimes(1)
		expect(axiosMock.delete.mock.calls[0][0]).toContain('/application-template/org-1')
		// Gallery re-fetched after delete.
		expect(axiosMock.get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.deleteOpen).toBe(false)
	})
})
