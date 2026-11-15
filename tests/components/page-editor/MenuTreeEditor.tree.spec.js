/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for MenuTreeEditor's tree operations beyond add/edit
 * (REQ-OBPD-001).
 *
 * Covers:
 *  - removing a top-level entry re-numbers `order`.
 *  - adding a child keeps the existing children.
 *  - editing a child sets and clears its fields without touching siblings,
 *    and leaves the prop unmutated.
 *  - removing one child keeps the rest.
 *  - re-ordering top-level entries and children.
 *  - the group ids already used by entries' `group:` permissions.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MenuTreeEditor from '../../../src/components/page-editor/MenuTreeEditor.vue'
import stubDraggable from '../../vitest/stubs/vuedraggable.js'

const MENU = [
	{ id: 'home', label: 'Home', order: 0 },
	{
		id: 'cases',
		label: 'Cases',
		order: 1,
		children: [
			{ id: 'open', label: 'Open' },
			{ id: 'closed', label: 'Closed', route: '/closed' },
		],
	},
	{ id: 'admin', label: 'Admin', order: 2, permission: 'group:admins' },
]

function mountEditor(menu = MENU) {
	return mount(MenuTreeEditor, {
		props: { menu },
		global: { stubs: { Draggable: stubDraggable } },
	})
}

function lastMenu(wrapper) {
	const events = wrapper.emitted('update:menu')
	return events[events.length - 1][0]
}

describe('MenuTreeEditor tree operations', () => {
	it('removing an entry closes the gap in the order', () => {
		const wrapper = mountEditor()
		wrapper.vm.removeEntry(0)
		const menu = lastMenu(wrapper)
		expect(menu.map((e) => [e.id, e.order])).toEqual([['cases', 0], ['admin', 1]])
	})

	it('adding a child keeps the children already there', () => {
		const wrapper = mountEditor()
		wrapper.vm.addChild(1)
		const children = lastMenu(wrapper)[1].children
		expect(children).toEqual([
			{ id: 'open', label: 'Open' },
			{ id: 'closed', label: 'Closed', route: '/closed' },
			{ id: '', label: '' },
		])
		expect(MENU[1].children).toHaveLength(2)
	})

	it('editing a child sets only that child\'s field', () => {
		const wrapper = mountEditor()
		wrapper.vm.updateChildField(1, 0, 'route', '/open')
		const menu = lastMenu(wrapper)
		expect(menu[1].children).toEqual([
			{ id: 'open', label: 'Open', route: '/open' },
			{ id: 'closed', label: 'Closed', route: '/closed' },
		])
		expect(menu[0]).toEqual({ id: 'home', label: 'Home', order: 0 })
		expect(MENU[1].children[0]).not.toHaveProperty('route')
	})

	it('clearing a child field removes the key', () => {
		const wrapper = mountEditor()
		wrapper.vm.updateChildField(1, 1, 'route', '')
		expect(lastMenu(wrapper)[1].children[1]).toEqual({ id: 'closed', label: 'Closed' })
		expect(wrapper.vm.depthError).toBe(false)
	})

	it('removing one child keeps the others', () => {
		const wrapper = mountEditor()
		wrapper.vm.removeChild(1, 0)
		expect(lastMenu(wrapper)[1].children).toEqual([{ id: 'closed', label: 'Closed', route: '/closed' }])
	})

	it('a top-level reorder is emitted with fresh order numbers', () => {
		const wrapper = mountEditor()
		wrapper.vm.onTopLevelReorder([MENU[2], MENU[0], MENU[1]])
		expect(lastMenu(wrapper).map((e) => [e.id, e.order])).toEqual([['admin', 0], ['home', 1], ['cases', 2]])
	})

	it('a children reorder replaces that parent\'s children only', () => {
		const wrapper = mountEditor()
		const reversed = [MENU[1].children[1], MENU[1].children[0]]
		wrapper.vm.onChildrenReorder(1, reversed)
		const menu = lastMenu(wrapper)
		expect(menu[1].children.map((c) => c.id)).toEqual(['closed', 'open'])
		expect(menu).toHaveLength(3)
		expect(MENU[1].children[0].id).toBe('open')
	})

	it('lists the groups already used by entry permissions', () => {
		const wrapper = mountEditor([
			{ id: 'a', permission: 'group:admins' },
			{ id: 'b', permission: 'group:staff' },
			{ id: 'c', permission: 'group:admins' },
			{ id: 'd', permission: 'user:alice' },
			{ id: 'e', permission: 42 },
		])
		expect(wrapper.vm.knownGroups).toEqual(['admins', 'staff'])
	})
})
