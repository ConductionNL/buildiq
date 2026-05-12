/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PageListEditor (REQ-OBPD-002).
 *
 * Covers:
 *  - Empty-state render.
 *  - "Add page" -> picker -> confirm flow emits `update:pages` with the
 *    correct DEFAULT_CONFIGS shape per page-type.
 *  - Unique-id constraint: duplicate ids surface the error paragraph and
 *    flag the row with `--error` class.
 *  - Route-pattern validation: invalid route surfaces the error paragraph;
 *    a valid route (incl. `/:param` shape) passes.
 *  - Delete fires `update:pages` minus the row and emits `select(-1)` when
 *    the deleted row was selected.
 *  - vuedraggable @input forwards a re-ordered array.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PageListEditor, { PAGE_TYPES } from '../../../src/components/page-editor/PageListEditor.vue'

const stubDraggable = {
	name: 'Draggable',
	props: ['value', 'options'],
	render(h) { return h('div', { staticClass: 'vuedraggable-stub' }, this.$slots.default) },
}

function mountEditor(pages = [], selectedIndex = -1) {
	return mount(PageListEditor, {
		propsData: { pages, selectedIndex },
		stubs: { Draggable: stubDraggable },
	})
}

describe('PageListEditor', () => {
	it('renders the empty state with no pages', () => {
		const wrapper = mountEditor([])
		expect(wrapper.text()).toContain('No pages yet')
	})

	it('exports the canonical PAGE_TYPES enum (9 entries)', () => {
		expect(PAGE_TYPES).toHaveLength(9)
		expect(PAGE_TYPES).toContain('index')
		expect(PAGE_TYPES).toContain('form')
		expect(PAGE_TYPES).toContain('custom')
	})

	it('clicking Add reveals the type picker', async () => {
		const wrapper = mountEditor([])
		expect(wrapper.find('.page-list-editor__add-row').exists()).toBe(false)
		await wrapper.find('.page-list-editor__add').trigger('click')
		expect(wrapper.find('.page-list-editor__add-row').exists()).toBe(true)
	})

	it('confirmAdd appends a new page with the right DEFAULT_CONFIGS shape', async () => {
		const wrapper = mountEditor([])
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'index'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:pages')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].type).toBe('index')
		expect(next[0].route).toBe('/')
		expect(next[0].config).toMatchObject({ columns: [], actions: [] })
		// select(0) follows the add.
		expect(wrapper.emitted('select')[0][0]).toBe(0)
	})

	it('confirmAdd for form-type seeds submitMethod + mode defaults', async () => {
		const wrapper = mountEditor([])
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'form'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:pages')[0][0]
		expect(next[0].type).toBe('form')
		expect(next[0].config.submitMethod).toBe('POST')
		expect(next[0].config.mode).toBe('public')
	})

	it('confirmAdd is a no-op when type is empty', () => {
		const wrapper = mountEditor([])
		wrapper.vm.startAdd()
		wrapper.vm.addingType = ''
		wrapper.vm.confirmAdd()
		expect(wrapper.emitted('update:pages')).toBeUndefined()
	})

	it('flags duplicate ids and surfaces the error paragraph', async () => {
		const wrapper = mountEditor([
			{ id: 'home', type: 'index', route: '/' },
			{ id: 'home', type: 'detail', route: '/foo' },
		])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.duplicateIds).toContain('home')
		expect(wrapper.find('.page-list-editor__error').exists()).toBe(true)
		expect(wrapper.find('.page-list-editor__error').text()).toContain('home')
		expect(wrapper.findAll('.page-list-editor__row--error')).toHaveLength(2)
	})

	it('valid routes do NOT surface an error', () => {
		const wrapper = mountEditor([
			{ id: 'home', type: 'index', route: '/' },
			{ id: 'detail', type: 'detail', route: '/items/:id' },
			{ id: 'nested', type: 'detail', route: '/a/b/c-d_e' },
		])
		expect(wrapper.vm.invalidRoutes).toEqual([])
	})

	it('invalid routes surface the error paragraph', () => {
		const wrapper = mountEditor([
			{ id: 'broken', type: 'index', route: 'no-leading-slash' },
		])
		expect(wrapper.vm.invalidRoutes).toContain('no-leading-slash')
	})

	it('updateField clears the key when value is empty', async () => {
		const wrapper = mountEditor([{ id: 'home', route: '/', type: 'index' }])
		wrapper.vm.updateField(0, 'route', '')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:pages')[0][0]
		expect(next[0]).not.toHaveProperty('route')
	})

	it('removePage emits update:pages minus the deleted row', async () => {
		const wrapper = mountEditor([
			{ id: 'a', type: 'index' },
			{ id: 'b', type: 'detail' },
		])
		wrapper.vm.removePage(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:pages')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].id).toBe('b')
	})

	it('removePage of the selected row emits select(-1)', async () => {
		const wrapper = mountEditor([
			{ id: 'a', type: 'index' },
		], 0)
		wrapper.vm.removePage(0)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('select')).toContainEqual([-1])
	})

	it('vuedraggable @input forwards re-ordered array', async () => {
		const wrapper = mountEditor([
			{ id: 'a', type: 'index' },
			{ id: 'b', type: 'detail' },
		])
		wrapper.vm.onReorder([
			{ id: 'b', type: 'detail' },
			{ id: 'a', type: 'index' },
		])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:pages')[0][0]
		expect(next.map((p) => p.id)).toEqual(['b', 'a'])
	})

	it('row click emits select(index)', async () => {
		const wrapper = mountEditor([
			{ id: 'a', type: 'index' },
			{ id: 'b', type: 'detail' },
		])
		await wrapper.findAll('.page-list-editor__row').at(1).trigger('click')
		expect(wrapper.emitted('select')[0][0]).toBe(1)
	})

	it('cancelAdd hides the picker without emitting', async () => {
		const wrapper = mountEditor([])
		wrapper.vm.startAdd()
		wrapper.vm.cancelAdd()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.page-list-editor__add-row').exists()).toBe(false)
		expect(wrapper.emitted('update:pages')).toBeUndefined()
	})
})
