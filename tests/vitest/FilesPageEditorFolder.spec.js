/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the files page (capability-requirements-backfill T08,
 * REQ-BQCV-008): a maker adds a page of type files and sets in its editor the
 * folder it browses. The editor writes `config.folder`, the prop
 * nextcloud-vue's CnFilesPage lists the files and folders of; the listing
 * itself is the library's and is not tested again here.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-files-page-req-bqcv-008
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FilesPageEditor from '../../src/components/page-editor/FilesPageEditor.vue'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import stubDraggable from './stubs/vuedraggable.js'

/**
 * Mount the files page editor on a config.
 *
 * @param {object} config The page config.
 * @return {object} The wrapper.
 */
function editor(config) {
	return mount(FilesPageEditor, {
		props: { config, pageType: 'files' },
		global: {
			stubs: { InlineFieldMark: { template: '<span />' } },
			mocks: {
				$validator: {
					register: vi.fn(),
					unregister: vi.fn(),
					errorsForPathPrefix: () => ({ errors: [], warnings: [] }),
					summaryForPathPrefix: () => null,
				},
			},
		},
	})
}

describe('REQ-BQCV-008 files page', () => {
	it('a maker adds a files page, sets the app folder, and the saved page browses it', async () => {
		const list = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		list.vm.startAdd()
		list.vm.addingType = 'files'
		list.vm.confirmAdd()
		await list.vm.$nextTick()
		const page = list.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('files')
		expect(page.config).toEqual({ folder: '' })

		const wrapper = editor(page.config)
		const input = wrapper.find('.files-page-editor__group-row input')
		expect(input.element.value).toBe('')
		await input.setValue('/Vergunningen/Documenten')
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ folder: '/Vergunningen/Documenten' })
	})

	it('shows the folder a saved page browses', () => {
		const wrapper = editor({ folder: '/Vergunningen/Documenten' })
		expect(wrapper.find('.files-page-editor__group-row input').element.value).toBe('/Vergunningen/Documenten')
	})

	it('changing the folder keeps the allowed types', async () => {
		const wrapper = editor({ folder: '/Old', allowedTypes: ['.pdf'] })
		await wrapper.find('.files-page-editor__group-row input').setValue('/Vergunningen')
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ folder: '/Vergunningen', allowedTypes: ['.pdf'] })
	})
})
