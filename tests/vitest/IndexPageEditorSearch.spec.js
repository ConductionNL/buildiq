/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the list page editor's Search block
 * (capability-requirements-backfill T05, REQ-BQCV-005): the maker shows the
 * search box above the list and sets its placeholder, and the editor writes
 * `config.inlineSearch` and `config.searchPlaceholder`, the keys
 * nextcloud-vue's CnIndexPage reads, leaving every other key alone.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/composables/useEditorRegisterPicker.js', () => ({
	useEditorRegisterPicker: () => ({
		picker: {
			fetchRegisters: async () => [],
			fetchSchemas: async () => [],
			fetchSchemaProperties: async () => [],
		},
	}),
}))

const IndexPageEditor = (await import('../../src/components/page-editor/IndexPageEditor.vue')).default

/**
 * Mount the editor on a config.
 *
 * @param {object} config The page config.
 * @return {object} The wrapper.
 */
function editor(config) {
	return shallowMount(IndexPageEditor, { props: { config } })
}

describe('IndexPageEditor search block', () => {
	it('starts with the search box in the sidebar and no placeholder', () => {
		const wrapper = editor({ register: 'vergunningen', schema: 'permit' })
		expect(wrapper.find('[data-testid="index-page-inline-search"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="index-page-search-placeholder"]').element.value).toBe('')
	})

	it('shows what the config holds', () => {
		const wrapper = editor({ inlineSearch: true, searchPlaceholder: 'Zoek een vergunning' })
		expect(wrapper.find('[data-testid="index-page-inline-search"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="index-page-search-placeholder"]').element.value).toBe('Zoek een vergunning')
	})

	it('writes the placeholder and keeps the other keys', async () => {
		const wrapper = editor({ register: 'vergunningen', schema: 'permit', columns: ['title'] })
		await wrapper.find('[data-testid="index-page-search-placeholder"]').setValue('Zoek op adres')
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			register: 'vergunningen',
			schema: 'permit',
			columns: ['title'],
			searchPlaceholder: 'Zoek op adres',
		})
	})

	it('removes the placeholder when it is cleared', async () => {
		const wrapper = editor({ schema: 'permit', searchPlaceholder: 'Zoek' })
		await wrapper.find('[data-testid="index-page-search-placeholder"]').setValue('')
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ schema: 'permit' })
	})

	it('turns the search box above the list on and off', async () => {
		const wrapper = editor({ schema: 'permit' })
		await wrapper.find('[data-testid="index-page-inline-search"]').setValue(true)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ schema: 'permit', inlineSearch: true })

		await wrapper.setProps({ config: { schema: 'permit', inlineSearch: true } })
		await wrapper.find('[data-testid="index-page-inline-search"]').setValue(false)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ schema: 'permit' })
	})
})
