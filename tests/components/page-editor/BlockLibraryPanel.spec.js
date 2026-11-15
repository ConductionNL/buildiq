/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BlockLibraryPanel — the page designer's component-block
 * library.
 *
 * Covers:
 *  - fetching on open (mount and watcher), and an empty list on a failed read.
 *  - the preview label of a widget block and of a section block.
 *  - insert: straight through when the target app has every schema, via the
 *    remap dialog when it does not; the resolution rewrites schema refs.
 *  - import: a valid file is posted and the list re-read; a dependency gap
 *    goes through the remap dialog first; invalid files and failed posts
 *    surface an error.
 *  - delete: the block's uuid is used in the URL and the row dropped; a
 *    failed delete keeps the row and says so.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import BlockLibraryPanel from '../../../src/components/page-editor/BlockLibraryPanel.vue'

const OR_BLOCKS = '/apps/openregister/api/objects/buildiq/component-block'

const WIDGET_BLOCK = {
	slug: 'case-list',
	name: 'Case list',
	category: 'lists',
	schemaDependencies: ['case'],
	fragment: { id: 'w1', widgetKey: 'table', schema: 'case' },
}

const SECTION_BLOCK = {
	slug: 'intake',
	name: 'Intake',
	uuid: 'uuid-intake',
	schemaDependencies: [],
	fragment: { id: 'sec', widgets: [{ id: 'a' }, { id: 'b' }, { id: 'c' }] },
}

function mountPanel(props = {}) {
	return shallowMount(BlockLibraryPanel, { props: { open: false, ...props } })
}

function fileEvent(text) {
	return { target: { value: 'C:\\fakepath\\block.json', files: text === null ? [] : [{ text: async () => text }] } }
}

function exportOf(block) {
	return JSON.stringify({ schemaVersion: '1.0', kind: 'component-block', block })
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.delete.mockReset()
	axios.get.mockResolvedValue({ data: { results: [WIDGET_BLOCK, SECTION_BLOCK] } })
})

describe('BlockLibraryPanel', () => {
	it('does not read the library while closed', async () => {
		mountPanel()
		await flushPromises()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads the library when mounted open', async () => {
		const wrapper = mountPanel({ open: true })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledWith(OR_BLOCKS)
		expect(wrapper.vm.blocks.map((b) => b.slug)).toEqual(['case-list', 'intake'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reads the library when it is opened later', async () => {
		const wrapper = mountPanel()
		await wrapper.setProps({ open: true })
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.blocks).toHaveLength(2)
	})

	it('shows no blocks when the read fails', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mountPanel({ open: true })
		await flushPromises()
		expect(wrapper.vm.blocks).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('labels a section by its widget count and a widget by its key', () => {
		const wrapper = mountPanel()
		expect(wrapper.vm.previewLabel(SECTION_BLOCK)).toBe('{count} widget(s) in this section')
		expect(wrapper.vm.previewLabel(WIDGET_BLOCK)).toBe('table')
		expect(wrapper.vm.previewLabel({ fragment: { id: 'x' } })).toBe('Single widget')
		expect(wrapper.vm.previewLabel(null)).toBe('Single widget')
	})

	it('inserts straight away when the app has every schema the block needs', () => {
		const wrapper = mountPanel({ targetSchemaSlugs: ['case'], targetWidgets: [{ id: 'existing' }] })
		wrapper.vm.onInsert(WIDGET_BLOCK)

		const [widgets] = wrapper.emitted('insert-widgets')[0]
		expect(widgets).toHaveLength(1)
		expect(widgets[0].widgetKey).toBe('table')
		expect(widgets[0].schema).toBe('case')
		expect(widgets[0].id).toMatch(/^w1-[a-z0-9]+$/)
		expect(wrapper.vm.remapOpen).toBe(false)
	})

	it('asks for a remap before inserting a block whose schema is missing', () => {
		const wrapper = mountPanel({ targetSchemaSlugs: ['dossier'] })
		wrapper.vm.onInsert(WIDGET_BLOCK)

		expect(wrapper.emitted('insert-widgets')).toBeFalsy()
		expect(wrapper.vm.remapOpen).toBe(true)
		expect(wrapper.vm.remapDependencies).toEqual(['case'])
		expect(wrapper.vm.pendingRemap).toEqual({ mode: 'insert', block: WIDGET_BLOCK })
	})

	it('inserts with the chosen schema once the remap is resolved', async () => {
		const wrapper = mountPanel({ targetSchemaSlugs: ['dossier'] })
		wrapper.vm.onInsert(WIDGET_BLOCK)
		await wrapper.vm.onRemapResolved({ remapMap: { case: 'dossier' }, unresolvedDependencies: [] })

		const [widgets] = wrapper.emitted('insert-widgets')[0]
		expect(widgets[0].schema).toBe('dossier')
		expect(wrapper.vm.pendingRemap).toBeNull()
	})

	it('ignores a remap resolution with nothing pending', async () => {
		const wrapper = mountPanel()
		await wrapper.vm.onRemapResolved({ remapMap: {} })
		expect(wrapper.emitted('insert-widgets')).toBeFalsy()
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('imports a valid export without its uuid and re-reads the library', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountPanel({ targetSchemaSlugs: ['case'] })
		const event = fileEvent(exportOf({ ...WIDGET_BLOCK, uuid: 'old', createdBy: 'alice' }))
		await wrapper.vm.onImportFile(event)
		await flushPromises()

		expect(event.target.value).toBe('')
		expect(axios.post).toHaveBeenCalledTimes(1)
		const [url, record] = axios.post.mock.calls[0]
		expect(url).toBe(OR_BLOCKS)
		expect(record.slug).toBe('case-list')
		expect(record).not.toHaveProperty('uuid')
		expect(record).not.toHaveProperty('createdBy')
		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.importError).toBe('')
	})

	it('remaps an imported block before creating it', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = mountPanel({ targetSchemaSlugs: ['dossier'] })
		await wrapper.vm.onImportFile(fileEvent(exportOf(WIDGET_BLOCK)))

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.remapOpen).toBe(true)
		expect(wrapper.vm.pendingRemap.mode).toBe('import')

		await wrapper.vm.onRemapResolved({ remapMap: { case: 'dossier' }, unresolvedDependencies: [] })
		const [, record] = axios.post.mock.calls[0]
		expect(record.schemaDependencies).toEqual(['dossier'])
		expect(record.fragment.schema).toBe('dossier')
	})

	it('does nothing when no file was picked', async () => {
		const wrapper = mountPanel()
		await wrapper.vm.onImportFile(fileEvent(null))
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.importError).toBe('')
	})

	it('refuses a file that is not a block export', async () => {
		const wrapper = mountPanel()
		await wrapper.vm.onImportFile(fileEvent('{"kind":"page"}'))
		expect(wrapper.vm.importError).toBe('That file is not a valid block export.')

		await wrapper.vm.onImportFile(fileEvent('not json'))
		expect(wrapper.vm.importError).toBe('That file is not a valid block export.')
		expect(axios.post).not.toHaveBeenCalled()
	})

	it('reports a file that cannot be read', async () => {
		const wrapper = mountPanel()
		await wrapper.vm.onImportFile({ target: { value: 'x', files: [{ text: async () => { throw new Error('disk') } }] } })
		expect(wrapper.vm.importError).toBe('Import failed: {message}')
	})

	it('shows the server reason when creating the block fails', async () => {
		const wrapper = mountPanel()
		axios.post.mockRejectedValueOnce({ response: { data: { detail: 'Slug already taken' } } })
		await wrapper.vm.createBlock({ slug: 'x' })
		expect(wrapper.vm.importError).toBe('Slug already taken')

		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Forbidden' } } })
		await wrapper.vm.createBlock({ slug: 'x' })
		expect(wrapper.vm.importError).toBe('Forbidden')

		axios.post.mockRejectedValueOnce(new Error('Network Error'))
		await wrapper.vm.createBlock({ slug: 'x' })
		expect(wrapper.vm.importError).toBe('Network Error')

		axios.post.mockRejectedValueOnce({})
		await wrapper.vm.createBlock({ slug: 'x' })
		expect(wrapper.vm.importError).toBe('Import failed.')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('deletes a block by its uuid and drops it from the list', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = mountPanel({ open: true })
		await flushPromises()
		wrapper.vm.confirmDeleteSlug = 'intake'

		await wrapper.vm.onDelete({ ...SECTION_BLOCK, '@self': { id: 'self-id' } })

		expect(axios.delete).toHaveBeenCalledWith(`${OR_BLOCKS}/self-id`)
		expect(wrapper.vm.blocks.map((b) => b.slug)).toEqual(['case-list'])
		expect(wrapper.vm.confirmDeleteSlug).toBe('')
	})

	it('falls back to uuid, then id, for the delete address', async () => {
		axios.delete.mockResolvedValue({})
		const wrapper = mountPanel()
		await wrapper.vm.onDelete(SECTION_BLOCK)
		await wrapper.vm.onDelete({ slug: 'z', id: 'a b' })
		expect(axios.delete.mock.calls.map((c) => c[0])).toEqual([
			`${OR_BLOCKS}/uuid-intake`,
			`${OR_BLOCKS}/a%20b`,
		])
	})

	it('keeps the block and reports a failed delete', async () => {
		axios.delete.mockRejectedValue(new Error('403'))
		const wrapper = mountPanel({ open: true })
		await flushPromises()
		await wrapper.vm.onDelete(SECTION_BLOCK)
		expect(wrapper.vm.blocks).toHaveLength(2)
		expect(wrapper.vm.importError).toBe('Deleting the block failed.')
	})

	it('filters the list by the chosen category', async () => {
		const wrapper = mountPanel({ open: true })
		await flushPromises()
		expect(wrapper.vm.categoryOptions).toEqual([{ id: 'lists', label: 'lists' }])
		wrapper.vm.categoryFilter = { id: 'lists', label: 'lists' }
		expect(wrapper.vm.filteredBlocks.map((b) => b.slug)).toEqual(['case-list'])
	})
})
