/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the list page's Export action
 * (capability-requirements-backfill T04, REQ-BQCV-004): a new list page
 * offers Export (`config.allowExport: true`), and the maker switches it off
 * per page in the list page editor. nextcloud-vue's CnIndexPage reads
 * `allowExport` and draws the CSV / Excel menu that downloads through
 * OpenRegister's export leaf; the download itself is the library's and is
 * not tested again here.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */
import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import stubDraggable from './stubs/vuedraggable.js'

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
const PageListEditor = (await import('../../src/components/page-editor/PageListEditor.vue')).default

/**
 * Mount the list page editor on a config.
 *
 * @param {object} config The page config.
 * @return {object} The wrapper.
 */
function editor(config) {
	return shallowMount(IndexPageEditor, { props: { config } })
}

describe('REQ-BQCV-004 list page export', () => {
	it('a new list page offers Export', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'index'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()

		const page = wrapper.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('index')
		expect(page.config.allowExport).toBe(true)
	})

	it('shows the switch on for a page that offers Export', () => {
		const wrapper = editor({ schema: 'permit', allowExport: true })
		expect(wrapper.find('[data-testid="index-page-allow-export"]').element.checked).toBe(true)
	})

	it('a maker switches export off: the saved page carries allowExport false and keeps its other keys', async () => {
		const wrapper = editor({ register: 'vergunningen', schema: 'permit', columns: ['title'], allowExport: true })
		await wrapper.find('[data-testid="index-page-allow-export"]').setValue(false)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			register: 'vergunningen',
			schema: 'permit',
			columns: ['title'],
			allowExport: false,
		})
	})

	it('a maker switches export back on', async () => {
		const wrapper = editor({ schema: 'permit', allowExport: false })
		expect(wrapper.find('[data-testid="index-page-allow-export"]').element.checked).toBe(false)
		await wrapper.find('[data-testid="index-page-allow-export"]').setValue(true)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ schema: 'permit', allowExport: true })
	})
})
