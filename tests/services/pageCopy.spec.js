/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/pageCopy.js and "Copy page" in the page list
 * (change apps-copy-app-and-page, T04, REQ-BQCP-003).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import { copyPage } from '../../src/services/pageCopy.js'
import stubDraggable from '../vitest/stubs/vuedraggable.js'

const intake = {
	id: 'intake',
	title: 'Intake',
	route: '/intake',
	type: 'form',
	config: { fields: [{ key: 'name', label: 'Name', type: 'string' }] },
}

describe('copyPage (REQ-BQCP-003)', () => {
	it('inserts a copy below the source with a unique id, route and title', () => {
		const pages = [intake, { id: 'home', route: '/', type: 'index', config: {} }]
		const next = copyPage(pages, 0, (title) => 'Copy of ' + title)

		expect(next.map((p) => p.id)).toEqual(['intake', 'intake-copy', 'home'])
		expect(next[1]).toEqual({
			...intake,
			id: 'intake-copy',
			route: '/intake-copy',
			title: 'Copy of Intake',
		})
	})

	it('deep-copies, so editing the copy leaves the source alone', () => {
		const next = copyPage([intake], 0, (title) => title)
		next[1].config.fields[0].label = 'Changed'
		expect(intake.config.fields[0].label).toBe('Name')
	})

	it('numbers a second copy and keeps route parameters', () => {
		const pages = [
			{ id: 'permit', route: '/permits/:id', type: 'detail', config: {} },
			{
				id: 'permit-copy',
				route: '/permits-copy/:id',
				type: 'detail',
				config: {},
			},
		]
		const next = copyPage(pages, 0, (title) => title)
		expect(next[1].id).toBe('permit-copy-2')
		expect(next[1].route).toBe('/permits-copy-2/:id')
	})
})

describe('Copy page in the page list', () => {
	it('emits the pages with the copy inserted below the row', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [intake], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		await wrapper.find('.page-list-editor__copy').trigger('click')
		const emitted = wrapper.emitted('update:pages')
		expect(emitted[emitted.length - 1][0].map((p) => p.id)).toEqual([
			'intake',
			'intake-copy',
		])
	})
})
