/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for MenuTreeEditor (REQ-OBPD-001).
 *
 * Covers:
 *  - Initial render lists provided menu entries.
 *  - Clicking "Add menu entry" appends a placeholder and emits `update:menu`.
 *  - vuedraggable @input forwards a re-ordered array via `update:menu`
 *    with re-assigned monotonic `order` integers.
 *  - 2-level nesting cap: a child cannot itself declare `children[]`;
 *    attempting to set it raises `depth-violation` and surfaces the error
 *    paragraph but does NOT emit `update:menu`.
 *  - Setting `action` on a top-level entry clears `route` and `href`
 *    (canonical mutex documented in the template comment).
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MenuTreeEditor from '../../../src/components/page-editor/MenuTreeEditor.vue'

const stubDraggable = {
	name: 'Draggable',
	props: ['value', 'options'],
	render(h) { return h('div', { staticClass: 'vuedraggable-stub' }, this.$slots.default) },
}

function mountEditor(menu = []) {
	return mount(MenuTreeEditor, {
		propsData: { menu },
		stubs: { Draggable: stubDraggable },
	})
}

describe('MenuTreeEditor', () => {
	it('renders the empty-state message when menu is empty', () => {
		const wrapper = mountEditor([])
		expect(wrapper.text()).toContain('No menu entries yet')
	})

	it('renders one row per provided top-level entry', () => {
		const wrapper = mountEditor([
			{ id: 'inbox', label: 'inbox.label', target: 'main' },
			{ id: 'settings', label: 'settings.label', target: 'settings' },
		])
		const rows = wrapper.findAll('.menu-tree-editor__entry')
		expect(rows).toHaveLength(2)
	})

	it('addEntry() emits update:menu with the appended placeholder', async () => {
		const wrapper = mountEditor([{ id: 'inbox', label: 'inbox.label' }])
		await wrapper.find('.menu-tree-editor__add').trigger('click')
		const emitted = wrapper.emitted('update:menu')
		expect(emitted).toBeTruthy()
		const next = emitted[0][0]
		expect(next).toHaveLength(2)
		expect(next[1]).toMatchObject({ target: 'main' })
		expect(next[1].order).toBe(1)
	})

	it('assigns monotonic `order` integers on every emit', async () => {
		const wrapper = mountEditor([
			{ id: 'a' },
			{ id: 'b' },
			{ id: 'c' },
		])
		// Re-order via the draggable stub's @input event.
		wrapper.vm.onTopLevelReorder([
			{ id: 'c' },
			{ id: 'a' },
			{ id: 'b' },
		])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next.map((e) => e.id)).toEqual(['c', 'a', 'b'])
		expect(next.map((e) => e.order)).toEqual([0, 1, 2])
	})

	it('addChild() seeds an empty children[] on the parent', async () => {
		const wrapper = mountEditor([{ id: 'parent' }])
		wrapper.vm.addChild(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next[0].children).toBeTruthy()
		expect(next[0].children).toHaveLength(1)
	})

	it('enforces the 2-level depth cap when attempting to add children-of-a-child', async () => {
		const wrapper = mountEditor([
			{ id: 'parent', children: [{ id: 'child' }] },
		])
		// Try to set `children` on the child — must trigger depth-violation
		// and the visible error paragraph; must NOT emit update:menu.
		wrapper.vm.updateChildField(0, 0, 'children', [{ id: 'grandchild' }])
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('depth-violation')).toBeTruthy()
		expect(wrapper.emitted('update:menu')).toBeUndefined()
		expect(wrapper.find('.menu-tree-editor__error').exists()).toBe(true)
		expect(wrapper.find('.menu-tree-editor__error').text()).toContain('Maximum nesting depth is two levels')
	})

	it('updateField clears the key when value is empty string', async () => {
		const wrapper = mountEditor([{ id: 'inbox', label: 'inbox.label' }])
		wrapper.vm.updateField(0, 'label', '')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next[0]).not.toHaveProperty('label')
	})

	it('setting action clears route + href (mutex rule)', async () => {
		const wrapper = mountEditor([
			{ id: 'inbox', route: 'foo', href: '/bar' },
		])
		wrapper.vm.updateActionField(0, 'user-settings')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next[0].action).toBe('user-settings')
		expect(next[0]).not.toHaveProperty('route')
		expect(next[0]).not.toHaveProperty('href')
	})

	it('clearing action restores the ability to set route/href', async () => {
		const wrapper = mountEditor([
			{ id: 'inbox', action: 'user-settings' },
		])
		wrapper.vm.updateActionField(0, '')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next[0]).not.toHaveProperty('action')
	})

	it('removeChild deletes the children array when it becomes empty', async () => {
		const wrapper = mountEditor([
			{ id: 'parent', children: [{ id: 'only' }] },
		])
		wrapper.vm.removeChild(0, 0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:menu')[0][0]
		expect(next[0]).not.toHaveProperty('children')
	})

	it('@update:menu fires on save when add+edit sequence completes', async () => {
		// Compose: add entry -> edit id -> emits twice. Props do not update
		// between emits (the parent owns state) so the second emission
		// only carries the id mutation against the initial empty list.
		const wrapper = mountEditor([])
		await wrapper.find('.menu-tree-editor__add').trigger('click')
		wrapper.vm.updateField(0, 'id', 'inbox')
		await wrapper.vm.$nextTick()
		const emissions = wrapper.emitted('update:menu')
		expect(emissions).toHaveLength(2)
		expect(emissions[0][0][0]).toMatchObject({ target: 'main' })
		expect(emissions[1][0][0]).toMatchObject({ id: 'inbox' })
	})
})
