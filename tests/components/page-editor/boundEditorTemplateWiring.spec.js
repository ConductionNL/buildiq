/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the TEMPLATE WIRING of the register-bound page editors
 * (map, wiki, index) and the menu tree editor.
 *
 * Their own specs drive the components through `wrapper.vm`, which proves the
 * methods but not the markup that is meant to call them: a field bound to the
 * wrong key, reading the wrong `$event.target` property or listening on the
 * wrong event would stay green there while the form edits nothing. These
 * tests type into, pick in and click the RENDERED controls (or emit from the
 * child component the template listens to) and assert the payload that comes
 * out.
 *
 * Only the register picker's HTTP calls are mocked.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import stubDraggable from '../../vitest/stubs/vuedraggable.js'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn(async () => [
			{ slug: 'places', title: 'Places' },
			{ slug: 'docs', title: 'Docs' },
		]),
		fetchSchemas: vi.fn(async () => [
			{ slug: 'locations', title: 'Locations' },
			{ slug: 'articles', title: 'Articles' },
		]),
		fetchSchemaProperties: vi.fn(async () => ({
			lat: { type: 'number' },
			lng: { type: 'number' },
			body: { type: 'string' },
		})),
		resolveAppRegister: () => '',
	}),
}))

const MapPageEditor = (
	await import('../../../src/components/page-editor/MapPageEditor.vue')
).default
const WikiPageEditor = (
	await import('../../../src/components/page-editor/WikiPageEditor.vue')
).default
const IndexPageEditor = (
	await import('../../../src/components/page-editor/IndexPageEditor.vue')
).default
const MenuTreeEditor = (
	await import('../../../src/components/page-editor/MenuTreeEditor.vue')
).default

/**
 * The control inside the `<label>` whose text contains `text`.
 *
 * @param {object} wrapper The mounted editor.
 * @param {string} text A distinctive part of the label's text.
 * @param {string} selector The control to find inside that label.
 * @return {object} The control wrapper.
 */
function field(wrapper, text, selector = 'input') {
	const found = wrapper
		.findAll('label')
		.filter((candidate) => candidate.text().includes(text))
		.find((candidate) => candidate.find(selector).exists())
	if (found === undefined) {
		throw new Error(`no label containing "${text}" holds a ${selector}`)
	}
	return found.find(selector)
}

/**
 * Type `value` into a text control and let the handler run.
 *
 * @param {object} control The input wrapper.
 * @param {string} value The value to type.
 * @return {Promise<void>}
 */
async function type(control, value) {
	control.element.value = value
	await control.trigger('input')
}

/**
 * Pick `value` in a select and let the handler run.
 *
 * @param {object} control The select wrapper.
 * @param {string} value The option value to choose.
 * @return {Promise<void>}
 */
async function choose(control, value) {
	control.element.value = value
	await control.trigger('change')
}

/**
 * The payload of the component's last emission of `event`.
 *
 * @param {object} wrapper The mounted component.
 * @param {string} event The event name.
 * @return {*} The emitted payload.
 */
function last(wrapper, event = 'update:config') {
	const events = wrapper.emitted(event)
	expect(events, `the component emitted no ${event}`).toBeTruthy()
	return events[events.length - 1][0]
}

/**
 * Mount a register-bound editor and let its register list load.
 *
 * @param {object} component The editor component.
 * @param {object} config The page config.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountBound(component, config) {
	const wrapper = mount(component, {
		propsData: { config, appSlug: 'hello-world' },
	})
	await flushPromises()
	return wrapper
}

describe('MapPageEditor template wiring', () => {
	it('the centre inputs write their own half of the pair', async () => {
		const wrapper = await mountBound(MapPageEditor, { center: [1, 2], keep: 'x' })

		await type(field(wrapper, 'Centre latitude'), '52.5')
		expect(last(wrapper)).toEqual({ center: [52.5, 2], keep: 'x' })

		await type(field(wrapper, 'Centre longitude'), '4.75')
		expect(last(wrapper)).toEqual({ center: [1, 4.75], keep: 'x' })
	})

	it('a non-numeric centre keeps the previous value and a missing centre starts at 0,0', async () => {
		const wrapper = await mountBound(MapPageEditor, {})

		await type(field(wrapper, 'Centre longitude'), '7')
		expect(last(wrapper)).toEqual({ center: [0, 7] })
	})

	it('zoom writes an integer and ignores text that is not one', async () => {
		const wrapper = await mountBound(MapPageEditor, { zoom: 3 })

		await type(field(wrapper, 'Zoom'), '11')
		expect(last(wrapper)).toEqual({ zoom: 11 })

		const before = wrapper.emitted('update:config').length
		await type(field(wrapper, 'Zoom'), '')
		expect(wrapper.emitted('update:config')).toHaveLength(before)
	})

	it('height writes the text and clearing it drops the key', async () => {
		const wrapper = await mountBound(MapPageEditor, { height: '400px', zoom: 2 })

		await type(field(wrapper, 'Height'), '500px')
		expect(last(wrapper)).toEqual({ height: '500px', zoom: 2 })

		await type(field(wrapper, 'Height'), '')
		expect(last(wrapper)).toEqual({ zoom: 2 })
	})

	it('layer rows are added, edited by type and URL, and removed', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			layers: [{ type: 'tile', url: 'a' }, { type: 'wms', url: 'b' }],
		})

		await wrapper.find('.map-page-editor__row-add').trigger('click')
		expect(last(wrapper).layers).toEqual([
			{ type: 'tile', url: 'a' },
			{ type: 'wms', url: 'b' },
			{ type: 'tile', url: '' },
		])

		const rows = wrapper.findAll('.map-page-editor__row')
		await choose(rows[1].find('select'), 'geojson')
		expect(last(wrapper).layers[1]).toEqual({ type: 'geojson', url: 'b' })

		await type(rows[0].find('.map-page-editor__row-url'), 'https://tiles.example')
		expect(last(wrapper).layers[0]).toEqual({ type: 'tile', url: 'https://tiles.example' })

		await rows[0].find('.map-page-editor__row-remove').trigger('click')
		expect(last(wrapper).layers).toEqual([{ type: 'wms', url: 'b' }])
	})

	it('removing the last layer drops the layers key', async () => {
		const wrapper = await mountBound(MapPageEditor, { layers: [{ type: 'tile', url: 'a' }] })

		await wrapper.find('.map-page-editor__row-remove').trigger('click')
		expect(last(wrapper)).toEqual({})
	})

	it('the marker source URL writes markers.dataSource.url and keeps sibling keys', async () => {
		const wrapper = await mountBound(MapPageEditor, { markers: { clustering: true } })

		await type(field(wrapper, 'Marker source URL'), 'https://m.example/markers.json')
		expect(last(wrapper)).toEqual({
			markers: { clustering: true, dataSource: { url: 'https://m.example/markers.json' } },
		})
	})

	it('clearing the marker source URL drops an emptied dataSource', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			markers: { dataSource: { url: 'x' }, latField: 'lat' },
		})

		await type(field(wrapper, 'Marker source URL'), '')
		expect(last(wrapper)).toEqual({ markers: { latField: 'lat' } })
	})

	it('the register radio drops the URL and the URL radio drops register and schema', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			markers: { dataSource: { url: 'u' } },
		})
		const radios = wrapper.findAll('input[type="radio"]')

		await radios[1].trigger('change')
		expect(last(wrapper)).toEqual({ markers: {} })

		await wrapper.setProps({
			config: { markers: { dataSource: { register: 'places', schema: 'locations' } } },
		})
		await wrapper.findAll('input[type="radio"]')[0].trigger('change')
		expect(last(wrapper)).toEqual({ markers: {} })
	})

	it('switching to register keeps a register already in the dataSource', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			markers: { dataSource: { url: 'u', register: 'places' } },
		})

		await wrapper.findAll('input[type="radio"]')[1].trigger('change')
		expect(last(wrapper)).toEqual({ markers: { dataSource: { register: 'places' } } })
	})

	it('picking a marker register resets the schema; the schema select writes the schema', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			markers: { dataSource: { register: 'places', schema: 'locations' } },
		})
		expect(wrapper.find('[role="note"]').exists()).toBe(true)

		await choose(field(wrapper, 'Register', 'select'), 'docs')
		expect(last(wrapper)).toEqual({ markers: { dataSource: { register: 'docs' } } })

		await choose(field(wrapper, 'Schema', 'select'), 'articles')
		expect(last(wrapper)).toEqual({
			markers: { dataSource: { register: 'places', schema: 'articles' } },
		})

		await choose(field(wrapper, 'Register', 'select'), '')
		expect(last(wrapper)).toEqual({ markers: {} })
	})

	it('the field pickers write lat, lng and popup and the checkbox writes clustering', async () => {
		const wrapper = await mountBound(MapPageEditor, {
			markers: { dataSource: { register: 'places', schema: 'locations' } },
		})
		const ds = { register: 'places', schema: 'locations' }

		// Bound to a schema, the property fields are selects over its keys.
		await choose(field(wrapper, 'Latitude field', 'select'), 'lat')
		expect(last(wrapper)).toEqual({ markers: { dataSource: ds, latField: 'lat' } })

		await choose(field(wrapper, 'Longitude field', 'select'), 'lng')
		expect(last(wrapper)).toEqual({ markers: { dataSource: ds, lngField: 'lng' } })

		await choose(field(wrapper, 'Popup field', 'select'), 'body')
		expect(last(wrapper)).toEqual({ markers: { dataSource: ds, popupField: 'body' } })

		const box = field(wrapper, 'Clustering')
		box.element.checked = true
		await box.trigger('change')
		expect(last(wrapper)).toEqual({ markers: { dataSource: ds, clustering: true } })
	})

	it('emptying a marker field removes it from markers', async () => {
		const wrapper = await mountBound(MapPageEditor, { markers: { latField: 'y' } })

		await type(field(wrapper, 'Latitude field'), '')
		expect(last(wrapper)).toEqual({ markers: {} })
	})
})

describe('WikiPageEditor template wiring', () => {
	it('picking a register writes it and drops the schema; clearing it drops both', async () => {
		const wrapper = await mountBound(WikiPageEditor, {
			register: 'places',
			schema: 'locations',
			keep: 1,
		})

		await choose(field(wrapper, 'Register', 'select'), 'docs')
		expect(last(wrapper)).toEqual({ register: 'docs', keep: 1 })

		await choose(field(wrapper, 'Register', 'select'), '')
		expect(last(wrapper)).toEqual({ keep: 1 })
	})

	it('the schema select writes the schema key', async () => {
		const wrapper = await mountBound(WikiPageEditor, { register: 'docs' })

		await choose(field(wrapper, 'Schema', 'select'), 'articles')
		expect(last(wrapper)).toEqual({ register: 'docs', schema: 'articles' })
	})

	it('every text field writes its own config key', async () => {
		const cases = [
			['Content field', 'contentField', 'text'],
			['Title field', 'titleField', 'heading'],
			['Route id param', 'idParam', 'slug'],
			['Tree children field', 'treeField', 'kids'],
			['Sidebar title field', 'sidebarTitleField', 'name'],
			['Not-found heading', 'emptyText', 'Gone'],
			['Not-found description', 'emptyDescription', 'Nothing here'],
			['No-content heading', 'emptyBodyText', 'Empty'],
			['No-content description', 'emptyBodyDescription', 'No body yet'],
		]

		for (const [label, key, value] of cases) {
			const wrapper = await mountBound(WikiPageEditor, { keep: 'x' })
			await type(field(wrapper, label), value)
			expect(last(wrapper), label).toEqual({ keep: 'x', [key]: value })
		}
	})

	it('picking a sidebar register writes it and drops the sidebar schema', async () => {
		const wrapper = await mountBound(WikiPageEditor, {
			sidebarRegister: 'places',
			sidebarSchema: 'locations',
		})

		await choose(field(wrapper, 'Sidebar register', 'select'), 'docs')
		expect(last(wrapper)).toEqual({ sidebarRegister: 'docs' })

		await choose(field(wrapper, 'Sidebar register', 'select'), '')
		expect(last(wrapper)).toEqual({})
	})

	it('the sidebar schema select writes the sidebar schema', async () => {
		const wrapper = await mountBound(WikiPageEditor, { register: 'docs' })

		await choose(field(wrapper, 'Sidebar schema', 'select'), 'articles')
		expect(last(wrapper)).toEqual({ register: 'docs', sidebarSchema: 'articles' })
	})
})

describe('IndexPageEditor template wiring', () => {
	it('the card component input writes cardComponent', async () => {
		const wrapper = await mountBound(IndexPageEditor, { register: 'places' })

		await type(field(wrapper, 'Card component'), 'PlaceCard')
		expect(last(wrapper)).toEqual({ register: 'places', cardComponent: 'PlaceCard' })
	})

	it('register and schema events from the picker write their keys', async () => {
		const wrapper = await mountBound(IndexPageEditor, { keep: 1 })
		const picker = wrapper.findComponent({ name: 'RegisterSchemaFields' })

		picker.vm.$emit('register', 'docs')
		expect(last(wrapper)).toEqual({ keep: 1, register: 'docs' })

		picker.vm.$emit('schema', 'articles')
		expect(last(wrapper)).toEqual({ keep: 1, schema: 'articles' })
	})

	it('the column and action builders write columns and actions', async () => {
		const wrapper = await mountBound(IndexPageEditor, {})

		wrapper.findComponent({ name: 'ColumnBuilder' }).vm.$emit('update:modelValue', ['title'])
		expect(last(wrapper)).toEqual({ columns: ['title'] })

		wrapper.findComponent({ name: 'ActionBuilder' }).vm.$emit('update:modelValue', [{ id: 'open' }])
		expect(last(wrapper)).toEqual({ actions: [{ id: 'open' }] })
	})

	it('a sidebar edit promotes a legacy boolean sidebar to the object form', async () => {
		const wrapper = await mountBound(IndexPageEditor, { sidebar: true })
		const groups = [{ title: 'Main', columns: ['title'] }]

		wrapper.findComponent({ name: 'SidebarSectionBuilder' }).vm.$emit('update:modelValue', groups)
		expect(last(wrapper)).toEqual({ sidebar: { enabled: true, columnGroups: groups } })
	})

	it('a sidebar edit keeps the other keys of an object sidebar', async () => {
		const wrapper = await mountBound(IndexPageEditor, {
			sidebar: { enabled: true, width: 300, columnGroups: [{ title: 'Old' }] },
		})

		wrapper.findComponent({ name: 'SidebarSectionBuilder' }).vm.$emit('update:modelValue', [])
		expect(last(wrapper)).toEqual({
			sidebar: { enabled: true, width: 300, columnGroups: [] },
		})
	})

	it('a data source update stores it and an empty one drops the key', async () => {
		const wrapper = await mountBound(IndexPageEditor, { register: 'places' })
		const toggle = wrapper.findComponent({ name: 'DataSourceOriginToggle' })
		const connector = { connector: { endpointPath: '/items', fields: {} } }

		toggle.vm.$emit('update:dataSource', connector)
		expect(last(wrapper)).toEqual({ register: 'places', dataSource: connector })

		await wrapper.setProps({ config: { register: 'places', dataSource: connector } })
		expect(wrapper.findComponent({ name: 'RegisterSchemaFields' }).exists()).toBe(false)

		toggle.vm.$emit('update:dataSource', {})
		expect(last(wrapper)).toEqual({ register: 'places' })

		toggle.vm.$emit('update:dataSource', null)
		expect(last(wrapper)).toEqual({ register: 'places' })
	})
})

describe('MenuTreeEditor template wiring', () => {
	/**
	 * Mount the menu tree editor over `menu`.
	 *
	 * @param {Array<object>} menu The menu entries.
	 * @return {object} The mounted wrapper.
	 */
	function mountMenu(menu) {
		return mount(MenuTreeEditor, {
			propsData: { menu },
			stubs: { Draggable: stubDraggable },
		})
	}

	/**
	 * The `n`th control with this aria-label.
	 *
	 * @param {object} wrapper The mounted editor.
	 * @param {string} name The aria-label.
	 * @param {number} nth Which match.
	 * @return {object} The control wrapper.
	 */
	function byAria(wrapper, name, nth = 0) {
		const found = wrapper.findAll(`[aria-label="${name}"]`)
		if (found.length <= nth) {
			throw new Error(`no [aria-label="${name}"] at index ${nth}`)
		}
		return found[nth]
	}

	it('every top-level text field writes its own key on its own entry', async () => {
		const cases = [
			['id (e.g. inbox)', 'id', 'inbox'],
			['icon', 'icon', 'mail'],
			['route name', 'route', 'inbox.index'],
			['href URL', 'href', 'https://example.org'],
		]

		for (const [name, key, value] of cases) {
			const wrapper = mountMenu([{ id: 'a' }, { id: 'b' }])
			await type(byAria(wrapper, name, 1), value)
			const next = last(wrapper, 'update:menu')
			expect(next[1], name).toEqual({ id: 'b', [key]: value, order: 1 })
			expect(next[0], name).toEqual({ id: 'a', order: 0 })
		}
	})

	it('the label field writes the label and an emptied field drops its key', async () => {
		const wrapper = mountMenu([{ id: 'a', label: 'old' }])

		await type(byAria(wrapper, 'label (i18n key)'), 'menu.inbox')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', label: 'menu.inbox', order: 0 }])

		await type(byAria(wrapper, 'label (i18n key)'), '')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', order: 0 }])
	})

	it('the target select writes target', async () => {
		const wrapper = mountMenu([{ id: 'a' }])
		const [target] = wrapper.findAll('select')

		await choose(target, 'settings')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', target: 'settings', order: 0 }])
	})

	it('the action select clears route and href, and clearing it removes the action', async () => {
		const wrapper = mountMenu([{ id: 'a', route: 'r', href: 'h' }])
		const action = wrapper.findAll('select')[1]

		await choose(action, 'user-settings')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', action: 'user-settings', order: 0 }])

		await wrapper.setProps({ menu: [{ id: 'a', action: 'user-settings' }] })
		expect(wrapper.text()).toContain('Route and href are ignored when an action is set.')
		expect(byAria(wrapper, 'route name').attributes('disabled')).toBeDefined()

		await choose(wrapper.findAll('select')[1], '')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', order: 0 }])
	})

	it('the add-child and remove buttons act on their own entry', async () => {
		const wrapper = mountMenu([{ id: 'a' }, { id: 'b' }])

		await wrapper.findAll('[title="Add child"]')[1].trigger('click')
		expect(last(wrapper, 'update:menu')[1]).toEqual({
			id: 'b',
			children: [{ id: '', label: '' }],
			order: 1,
		})

		await wrapper.findAll('[title="Remove entry"]')[0].trigger('click')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'b', order: 0 }])
	})

	it('the permission field writes permission and a cleared one drops it', async () => {
		const wrapper = mountMenu([{ id: 'a', permission: 'group:old' }])
		const permission = wrapper.findComponent({ name: 'PermissionGroupField' })

		permission.vm.$emit('update:permission', 'group:admin')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', permission: 'group:admin', order: 0 }])

		permission.vm.$emit('update:permission', null)
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'a', order: 0 }])
	})

	it('every child field writes its own key on its own child', async () => {
		const cases = [
			['child id', 'id', 'sub'],
			['icon', 'icon', 'star'],
			['route name', 'route', 'sub.index'],
		]

		for (const [name, key, value] of cases) {
			const wrapper = mountMenu([{ id: 'p', children: [{ id: 'c0' }, { id: 'c1' }] }])
			// The parent row carries the first "icon" / "route name" control.
			const nth = name === 'child id' ? 1 : 2
			await type(byAria(wrapper, name, nth), value)
			expect(last(wrapper, 'update:menu')[0].children, name).toEqual([
				{ id: 'c0' },
				{ id: 'c1', [key]: value },
			])
		}
	})

	it('the child label field writes the label of that child', async () => {
		const wrapper = mountMenu([{ id: 'p', children: [{ id: 'c0' }] }])

		await type(byAria(wrapper, 'label (i18n key)', 1), 'menu.sub')
		expect(last(wrapper, 'update:menu')[0].children).toEqual([{ id: 'c0', label: 'menu.sub' }])
	})

	it('removing a child keeps the rest, and removing the last drops children', async () => {
		const wrapper = mountMenu([{ id: 'p', children: [{ id: 'c0' }, { id: 'c1' }] }])

		await wrapper.findAll('[title="Remove child"]')[0].trigger('click')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'p', children: [{ id: 'c1' }], order: 0 }])

		await wrapper.setProps({ menu: [{ id: 'p', children: [{ id: 'c1' }] }] })
		await wrapper.find('[title="Remove child"]').trigger('click')
		expect(last(wrapper, 'update:menu')).toEqual([{ id: 'p', order: 0 }])
	})

	it('a reorder of the child list is stored on its parent', async () => {
		const wrapper = mountMenu([{ id: 'p', children: [{ id: 'c0' }, { id: 'c1' }] }])
		const children = wrapper.findAllComponents(stubDraggable)[1]

		children.vm.$emit('update:modelValue', [{ id: 'c1' }, { id: 'c0' }])
		expect(last(wrapper, 'update:menu')).toEqual([
			{ id: 'p', children: [{ id: 'c1' }, { id: 'c0' }], order: 0 },
		])
	})
})
