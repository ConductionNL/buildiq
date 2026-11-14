/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning PageListEditor's row validity (`hasError`) and the
 * id / route / title a new page gets (`confirmAdd`) across the cases the main
 * spec leaves open.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PageListEditor from '../../../src/components/page-editor/PageListEditor.vue'
import stubDraggable from '../../vitest/stubs/vuedraggable.js'

function mountEditor(pages = []) {
	return mount(PageListEditor, {
		propsData: { pages, selectedIndex: -1 },
		stubs: { Draggable: stubDraggable },
	})
}

function added(pages, state) {
	const wrapper = mountEditor(pages)
	Object.assign(wrapper.vm, state)
	wrapper.vm.confirmAdd()
	const next = wrapper.emitted('update:pages')[0][0]
	return { page: next[next.length - 1], selected: wrapper.emitted('select')[0][0], vm: wrapper.vm }
}

describe('PageListEditor hasError', () => {
	const pages = [
		{ id: 'a', route: '/a', type: 'index' },
		{ id: 'a', route: '/b', type: 'index' },
		{ id: 'c', route: '/b', type: 'index' },
		{ id: 'd', route: 'bad route', type: 'index' },
		{ id: 'e', type: 'index' },
		{ id: 'f', route: '/f', type: 'index' },
	]

	it.each([
		[0, 0, true],
		[2, 2, true],
		[3, 3, true],
		[4, 4, false],
		[5, 5, false],
		[5, -1, true],
	])('row %i at index %i is %s', (row, index, expected) => {
		const vm = mountEditor(pages).vm
		expect(vm.hasError(pages[row], index)).toBe(expected)
	})

	it('treats a missing page as valid unless an id collides', () => {
		const vm = mountEditor([{ route: '/x', type: 'index' }, { route: '/y', type: 'index' }]).vm
		expect(vm.hasError(null, 0)).toBe(vm.duplicateIds.includes(null))
		expect(vm.hasError(undefined, 0)).toBe(vm.duplicateIds.includes(undefined))
	})
})

describe('PageListEditor confirmAdd naming', () => {
	it('puts the first untyped index page on "/" and numbers its id', () => {
		const { page, selected, vm } = added([{ id: 'x', route: '/x', type: 'detail' }], { addingType: 'index' })
		expect(page).toMatchObject({ id: 'index-page-2', route: '/', type: 'index' })
		expect(selected).toBe(1)
		expect(vm.addingType).toBe(null)
	})

	it('gives a second untyped index page its own route', () => {
		const { page } = added([{ id: 'home', route: '/', type: 'index' }], { addingType: 'index' })
		expect(page.route).toBe('/index')
	})

	it('routes a typed index slug on the slug even when "/" is free', () => {
		const { page } = added([], { addingType: 'index', addingId: 'Pets List' })
		expect(page).toMatchObject({ id: 'pets-list', route: '/pets-list' })
	})

	it('numbers a taken id and route', () => {
		const { page } = added(
			[
				{ id: 'dashboard-page-2', route: '/dashboard', type: 'index' },
				{ id: 'dashboard-page-2-2', route: '/dashboard-2', type: 'index' },
			],
			{ addingType: 'dashboard' },
		)
		expect(page).toMatchObject({ id: 'dashboard-page-3', route: '/dashboard-3' })
	})

	it('copies the default config instead of sharing it', () => {
		const first = added([], { addingType: 'form' }).page
		const second = added([], { addingType: 'form' }).page
		expect(first.config).toEqual(second.config)
		expect(first.config).not.toBe(second.config)
	})

	it('keeps a typed title, trimmed', () => {
		const { page } = added([], { addingType: 'detail', addingTitle: '  Pet  ' })
		expect(page.title).toBe('Pet')
	})
})
