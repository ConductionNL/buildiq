/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FilesPageEditor (task 4.7 — `type: "files"`).
 *
 * Covers:
 *  - `folder` propagates and clears on empty.
 *  - allowedTypes tag input: commit adds, dedupes, remove drops; clearing
 *    to [] deletes the key.
 *  - Lossless round-trip of an unsurfaced config key.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FilesPageEditor from '../../../src/components/page-editor/FilesPageEditor.vue'

function mountEditor(config = {}) {
	return mount(FilesPageEditor, { propsData: { config } })
}

describe('FilesPageEditor', () => {
	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Files page')
	})

	it('folder propagates and clears on empty', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('folder', '/Documents')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].folder).toBe('/Documents')
		wrapper.vm.update('folder', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[1][0]).not.toHaveProperty('folder')
	})

	it('committing a draft adds it to allowedTypes', async () => {
		const wrapper = mountEditor({ folder: '/x' })
		wrapper.vm.typeDraft = 'application/pdf'
		wrapper.vm.commitDraft()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].allowedTypes).toEqual(['application/pdf'])
		expect(wrapper.vm.typeDraft).toBe('')
	})

	it('committing trims whitespace and ignores blanks', async () => {
		const wrapper = mountEditor({ folder: '/x' })
		wrapper.vm.typeDraft = '   '
		wrapper.vm.commitDraft()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('does not add a duplicate type', async () => {
		const wrapper = mountEditor({ folder: '/x', allowedTypes: ['.pdf'] })
		wrapper.vm.typeDraft = '.pdf'
		wrapper.vm.commitDraft()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')).toBeFalsy()
	})

	it('removing a type drops it from the list', async () => {
		const wrapper = mountEditor({ folder: '/x', allowedTypes: ['.pdf', '.docx'] })
		wrapper.vm.removeType(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].allowedTypes).toEqual(['.docx'])
	})

	it('removing the last type deletes the key', async () => {
		const wrapper = mountEditor({ folder: '/x', allowedTypes: ['.pdf'] })
		wrapper.vm.removeType(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0]).not.toHaveProperty('allowedTypes')
	})

	it('renders an existing allowedTypes list as tags', () => {
		const wrapper = mountEditor({ folder: '/x', allowedTypes: ['image/png', '.csv'] })
		const tags = wrapper.findAll('.files-page-editor__tag')
		expect(tags).toHaveLength(2)
		expect(wrapper.text()).toContain('image/png')
		expect(wrapper.text()).toContain('.csv')
	})

	it('preserves unsurfaced config keys on update (lossless round-trip)', async () => {
		const wrapper = mountEditor({ folder: '/x', viewMode: 'grid' })
		wrapper.vm.update('folder', '/y')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0].viewMode).toBe('grid')
	})
})
