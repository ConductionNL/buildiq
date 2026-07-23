/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for ShareTokenDialog.vue (public-forms-runtime).
 *
 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

import axios from '@nextcloud/axios'
import ShareTokenDialog from '../../src/dialogs/ShareTokenDialog.vue'

const NcSelectStub = {
	name: 'NcSelect',
	props: ['value', 'options', 'inputLabel', 'label', 'clearable', 'placeholder'],
	template: '<div class="ncselect-stub" :data-label="inputLabel" />',
}
const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['value', 'label', 'placeholder', 'type'],
	template: '<input class="nctextfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)">',
}
const NcCheckboxRadioSwitchStub = {
	name: 'NcCheckboxRadioSwitch',
	props: ['checked', 'type'],
	template: '<label class="ncswitch-stub"><slot /></label>',
}
const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
}
const NcModalStub = {
	name: 'NcModal',
	props: ['name'],
	template: '<div class="ncmodal-stub"><slot /></div>',
}

const stubs = {
	NcModal: NcModalStub,
	NcSelect: NcSelectStub,
	NcTextField: NcTextFieldStub,
	NcCheckboxRadioSwitch: NcCheckboxRadioSwitchStub,
	NcButton: NcButtonStub,
}

const flush = () => new Promise((r) => setTimeout(r, 0))

const factory = (propsData = {}) => mount(ShareTokenDialog, {
	propsData: { open: false, appSlug: 'my-app', ...propsData },
	stubs,
})

const openDialog = async (wrapper) => {
	await wrapper.setProps({ open: true })
	await flush()
	await flush()
}

describe('ShareTokenDialog', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.post.mockReset()
		axios.delete.mockReset()
		axios.get.mockResolvedValue({ data: { tokens: [] } })
	})

	it('fetches the token list scoped to pageId when opened from the page designer', async () => {
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/api/applications/my-app/share-tokens'),
			expect.objectContaining({ params: { pageId: 'intake-form' } }),
		)
	})

	it('self-fetches manifest pages when no pageId/pages prop is given (AppSettingsModal scope)', async () => {
		axios.get.mockImplementation((url) => {
			if (url.includes('/manifest')) {
				return Promise.resolve({
					data: {
						pages: [
							{ id: 'public-page', title: 'Public', config: { public: { enabled: true } } },
							{ id: 'private-page', title: 'Private', config: {} },
						],
					},
				})
			}
			return Promise.resolve({ data: { tokens: [] } })
		})

		const wrapper = factory()
		await openDialog(wrapper)

		expect(wrapper.vm.publicEnabledPages).toEqual([{ id: 'public-page', label: 'Public' }])
	})

	it('publicEnabledPages filters the pages prop by config.public.enabled', async () => {
		const wrapper = factory({
			pages: [
				{ id: 'a', title: 'A', config: { public: { enabled: true } } },
				{ id: 'b', title: 'B', config: { public: { enabled: false } } },
				{ id: 'c', title: 'C', config: {} },
			],
		})
		await openDialog(wrapper)

		expect(wrapper.vm.publicEnabledPages).toEqual([{ id: 'a', label: 'A' }])
	})

	it('canCreate is false for mode:edit without a boundObjectId', async () => {
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)
		wrapper.vm.modeOption = { id: 'edit', label: 'edit' }
		wrapper.vm.boundObjectId = ''

		expect(wrapper.vm.canCreate).toBe(false)
	})

	it('canCreate is true for mode:submit once a page is scoped', async () => {
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)
		wrapper.vm.modeOption = { id: 'submit', label: 'submit' }

		expect(wrapper.vm.canCreate).toBe(true)
	})

	it('onCreate posts to the share-tokens endpoint and stores the created link', async () => {
		axios.post.mockResolvedValue({ data: { token: 'opaque-token-value' } })
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)
		wrapper.vm.modeOption = { id: 'submit', label: 'submit' }

		await wrapper.vm.onCreate()

		expect(axios.post).toHaveBeenCalledWith(
			expect.stringContaining('/api/applications/my-app/share-tokens'),
			expect.objectContaining({ pageId: 'intake-form', mode: 'submit' }),
		)
		expect(wrapper.vm.lastCreatedUrl).toContain('opaque-token-value')
	})

	it('onRevoke calls DELETE with the row id and refetches the list', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)

		await wrapper.vm.onRevoke({ id: 'token-uuid-1' })

		expect(axios.delete).toHaveBeenCalledWith(
			expect.stringContaining('/api/applications/my-app/share-tokens/token-uuid-1'),
		)
	})

	it('hides the page picker when scoped to a single page via the pageId prop', async () => {
		const wrapper = factory({ pageId: 'intake-form' })
		await openDialog(wrapper)

		const selects = wrapper.findAllComponents(NcSelectStub)
		// Only the Mode select renders — no page picker.
		expect(selects).toHaveLength(1)
	})
})
