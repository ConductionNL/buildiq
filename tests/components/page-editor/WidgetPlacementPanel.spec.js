/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for WidgetPlacementPanel (v2-widget-placement-editor tasks
 * 2.1 to 2.6 and 3.2).
 *
 * The panel is a controlled component: it never mutates the page it is given
 * and emits the whole replacement `widgets[]` array. Every assertion below
 * therefore reads the EMITTED array, which is the thing that reaches the
 * manifest, rather than any in-memory state of the panel.
 *
 * `CnWidgetGrid` and `CnAddWidgetModal` come from the vitest alias stub, which
 * declares the props and emits the real components declare. The widget type
 * catalogue is the REAL shared registry, seeded here with two types, so the
 * surface filtering under test is the library's own and not a fake of it.
 */

import { registerDashboardWidget } from '@conduction/nextcloud-vue'
import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it } from 'vitest'
import WidgetPlacementPanel from '../../../src/components/page-editor/WidgetPlacementPanel.vue'

const ANY_SURFACE_TYPE = 'wpp-spec-stat'
const DETAIL_ONLY_TYPE = 'wpp-spec-detail-only'

beforeAll(() => {
	// A renderer-only entry (form: null) is never offered, so both of these
	// carry a form. `surfaces` omitted means "offerable everywhere".
	registerDashboardWidget(ANY_SURFACE_TYPE, {
		renderer: { name: 'SpecStat', render: () => null },
		form: { name: 'SpecStatForm', render: () => null },
		defaultContent: {},
		displayName: 'Spec stat',
	})
	registerDashboardWidget(DETAIL_ONLY_TYPE, {
		renderer: { name: 'SpecDetail', render: () => null },
		form: { name: 'SpecDetailForm', render: () => null },
		defaultContent: {},
		displayName: 'Spec detail',
		surfaces: ['detail-page'],
	})
})

/**
 * Mount the panel on a page.
 *
 * @param {object} page - the page under edit.
 * @return {object} the VTU wrapper.
 */
function mountPanel(page) {
	return mount(WidgetPlacementPanel, { props: { page } })
}

/**
 * The single `update:widgets` payload, failing loudly when the panel emitted
 * nothing (which is the shape design.md R3 warns about).
 *
 * @param {object} wrapper - the VTU wrapper.
 * @return {Array<object>} the emitted replacement array.
 */
function emittedWidgets(wrapper) {
	const emitted = wrapper.emitted('update:widgets')
	expect(emitted, 'the panel emitted no update:widgets at all').toBeTruthy()
	return emitted[emitted.length - 1][0]
}

/**
 * A body placement.
 *
 * @param {object} overrides - keys to override on the entry.
 * @return {object} the placement.
 */
function bodyWidget(overrides = {}) {
	return {
		id: 'first',
		widgetKey: ANY_SURFACE_TYPE,
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 6,
		gridHeight: 3,
		...overrides,
	}
}

/**
 * A `type: "dashboard"` page carrying the given placements.
 *
 * @param {Array<object>} widgets - the page's placements.
 * @return {object} the page.
 */
function dashboardPage(widgets = []) {
	return { id: 'home', type: 'dashboard', config: {}, widgets }
}

describe('WidgetPlacementPanel', () => {
	describe('listing, adding, editing and deleting', () => {
		it('invites an author to add a placement when the page has none', () => {
			const wrapper = mountPanel(dashboardPage([]))
			expect(
				wrapper.find('[data-testid="placement-empty-state"]').exists(),
			).toBe(true)
			expect(wrapper.findAll('[data-testid="placement-row"]')).toHaveLength(0)
			// The empty state is an invitation, not an error.
			expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		})

		it('lists one row per placement, grouped by slot', () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget(),
					bodyWidget({ id: 'aside', slot: 'sidebar', gridWidth: 1 }),
				]),
			)
			expect(wrapper.findAll('[data-testid="placement-row"]')).toHaveLength(2)
			expect(wrapper.text()).toContain('Sidebar')
		})

		it('writes a new placement carrying all six required fields', async () => {
			const wrapper = mountPanel(dashboardPage([]))
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper.findComponent({ name: 'CnAddWidgetModal' }).vm.$emit('submit', {
				type: ANY_SURFACE_TYPE,
				content: { label: 'x' },
			})
			await wrapper.vm.$nextTick()

			const widgets = emittedWidgets(wrapper)
			expect(widgets).toHaveLength(1)
			for (const key of [
				'widgetKey',
				'slot',
				'gridX',
				'gridY',
				'gridWidth',
				'gridHeight',
			]) {
				expect(widgets[0]).toHaveProperty(key)
			}
			expect(widgets[0].widgetKey).toBe(ANY_SURFACE_TYPE)
			expect(widgets[0].props).toEqual({ label: 'x' })
		})

		it('updates a placement in place on edit and keeps its position', async () => {
			const page = dashboardPage([
				bodyWidget({ id: 'one' }),
				bodyWidget({ id: 'two', gridY: 3 }),
			])
			const wrapper = mountPanel(page)
			await wrapper
				.findAll('[data-testid="placement-edit"]')[1]
				.trigger('click')
			wrapper.findComponent({ name: 'CnAddWidgetModal' }).vm.$emit('submit', {
				type: ANY_SURFACE_TYPE,
				content: { label: 'edited' },
			})
			await wrapper.vm.$nextTick()

			const widgets = emittedWidgets(wrapper)
			expect(widgets).toHaveLength(2)
			expect(widgets[1].id).toBe('two')
			expect(widgets[1].props).toEqual({ label: 'edited' })
			// The neighbour is untouched. Identity is not assertable here: a
			// Vue 3 prop reaches the component as a reactive proxy of the raw
			// entry, so the useful claim is that nothing about it changed.
			expect(widgets[0]).toEqual(page.widgets[0])
		})

		it('deletes exactly one placement and leaves the rest as they were', async () => {
			const page = dashboardPage([
				bodyWidget({ id: 'one' }),
				bodyWidget({ id: 'two', gridX: 6 }),
				bodyWidget({ id: 'three', gridY: 3 }),
			])
			const wrapper = mountPanel(page)
			await wrapper
				.findAll('[data-testid="placement-delete"]')[1]
				.trigger('click')

			const widgets = emittedWidgets(wrapper)
			expect(widgets.map((w) => w.id)).toEqual(['one', 'three'])
			expect(widgets[0]).toEqual(page.widgets[0])
			expect(widgets[1]).toEqual(page.widgets[2])
		})

		it('reorders a placement without a pointer drag', async () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget({ id: 'one' }),
					bodyWidget({ id: 'two', gridY: 3 }),
				]),
			)
			await wrapper
				.findAll('[data-testid="placement-move-up"]')[1]
				.trigger('click')
			expect(emittedWidgets(wrapper).map((w) => w.id)).toEqual(['two', 'one'])
		})
	})

	describe('the field path', () => {
		it('positions a sidebar placement through numeric fields', async () => {
			const page = dashboardPage([
				bodyWidget({ id: 'aside', slot: 'sidebar', gridWidth: 1 }),
			])
			const wrapper = mountPanel(page)

			// The sidebar resolves to one column, so it offers no column or
			// span control at all — only the row and the height.
			expect(wrapper.find('[data-testid="placement-grid-x"]').exists()).toBe(
				false,
			)
			expect(
				wrapper.find('[data-testid="placement-grid-width"]').exists(),
			).toBe(false)

			const rowField = wrapper.find('[data-testid="placement-grid-y"]')
			await rowField.setValue('4')
			const heightField = wrapper.find('[data-testid="placement-grid-height"]')
			await heightField.setValue('8')

			const widgets = emittedWidgets(wrapper)
			expect(widgets[0]).toMatchObject({
				slot: 'sidebar',
				gridWidth: 1,
				gridHeight: 8,
			})
		})

		it('offers no row control in header-actions', () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget({
						slot: 'header-actions',
						gridWidth: 2,
						gridHeight: 1,
					}),
				]),
			)
			expect(wrapper.find('[data-testid="placement-grid-y"]').exists()).toBe(
				false,
			)
		})

		it('re-applies the target slot rules when a placement moves slot', async () => {
			const wrapper = mountPanel(
				dashboardPage([bodyWidget({ gridX: 4, gridY: 5, gridWidth: 6 })]),
			)
			await wrapper
				.find('[data-testid="placement-slot"]')
				.setValue('header-actions')

			const moved = emittedWidgets(wrapper)[0]
			expect(moved.slot).toBe('header-actions')
			// header-actions pins the row to zero, and the clamp still holds.
			expect(moved.gridY).toBe(0)
			expect(moved.gridX + moved.gridWidth).toBeLessThanOrEqual(12)
		})

		it('constrains a column the author pushes past the last one', async () => {
			const wrapper = mountPanel(
				dashboardPage([bodyWidget({ gridX: 0, gridWidth: 6 })]),
			)
			await wrapper.find('[data-testid="placement-grid-x"]').setValue('11')

			const widget = emittedWidgets(wrapper)[0]
			expect(widget.gridX + widget.gridWidth).toBeLessThanOrEqual(12)
			expect(widget.gridX).toBe(6)
		})
	})

	describe('the body drag canvas', () => {
		it('mounts an editable grid for the body slot only', () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget(),
					bodyWidget({ id: 'aside', slot: 'sidebar', gridWidth: 1 }),
				]),
			)
			const grids = wrapper.findAllComponents({ name: 'CnWidgetGrid' })
			expect(grids).toHaveLength(1)
			expect(grids[0].props('slotName')).toBe('body')
			expect(grids[0].props('editable')).toBe(true)
			expect(grids[0].props('widgets').map((w) => w.id)).toEqual(['first'])
		})

		it('stores the geometry a drag produced, read back from the save path', async () => {
			const page = dashboardPage([
				bodyWidget({ id: 'one' }),
				bodyWidget({
					id: 'aside',
					slot: 'sidebar',
					gridWidth: 1,
					gridHeight: 4,
				}),
			])
			const wrapper = mountPanel(page)
			const grid = wrapper.findComponent({ name: 'CnWidgetGrid' })

			// Exactly what the real component does: write the new geometry onto
			// the entries IN PLACE, then emit the very array it was handed.
			const handedBack = grid.props('widgets')
			handedBack[0].gridX = 3
			handedBack[0].gridY = 2
			handedBack[0].gridWidth = 4
			handedBack[0].gridHeight = 5
			grid.vm.$emit('layout-change', handedBack)
			await wrapper.vm.$nextTick()

			const widgets = emittedWidgets(wrapper)
			expect(widgets[0]).toMatchObject({
				id: 'one',
				gridX: 3,
				gridY: 2,
				gridWidth: 4,
				gridHeight: 5,
			})
			// The sidebar entry was not in the payload and must not move.
			expect(widgets[1]).toMatchObject({ id: 'aside', gridHeight: 4 })
		})

		it('emits even when the payload is the array it already held', async () => {
			// design.md R3: there is no previous value left to diff against, so
			// a handler that skipped an unchanged-looking payload would drop the
			// write entirely.
			const wrapper = mountPanel(dashboardPage([bodyWidget({ id: 'one' })]))
			const grid = wrapper.findComponent({ name: 'CnWidgetGrid' })
			grid.vm.$emit('layout-change', grid.props('widgets'))
			await wrapper.vm.$nextTick()
			expect(wrapper.emitted('update:widgets')).toBeTruthy()
		})
	})

	describe('the type picker', () => {
		it('asks the modal for the administrator catalogue on the page surface', () => {
			const wrapper = mountPanel(dashboardPage([]))
			const modal = wrapper.findComponent({ name: 'CnAddWidgetModal' })
			expect(modal.props('surface')).toBe('app-dashboard')
			expect(modal.props('userAddableOnly')).toBe(false)
			expect(modal.props('editingWidget')).toBe(null)
		})

		it('asks for the detail surface on a detail page, and passes its object context', () => {
			const wrapper = mountPanel({
				id: 'case',
				type: 'detail',
				config: { register: 'dossiq', schema: 'zaak' },
				widgets: [],
			})
			const modal = wrapper.findComponent({ name: 'CnAddWidgetModal' })
			expect(modal.props('surface')).toBe('detail-page')
			expect(modal.props('dataContext')).toEqual({
				register: 'dossiq',
				schema: 'zaak',
			})
		})

		it('does not offer a detail-only type on a dashboard page', () => {
			const dashboard = mountPanel(dashboardPage([]))
			expect(dashboard.vm.offerableTypes).toContain(ANY_SURFACE_TYPE)
			expect(dashboard.vm.offerableTypes).not.toContain(DETAIL_ONLY_TYPE)

			const detail = mountPanel({ id: 'case', type: 'detail', widgets: [] })
			expect(detail.vm.offerableTypes).toContain(DETAIL_ONLY_TYPE)
		})

		it('seeds the modal from the placement being edited', async () => {
			const wrapper = mountPanel(
				dashboardPage([bodyWidget({ props: { label: 'current' } })]),
			)
			await wrapper.find('[data-testid="placement-edit"]').trigger('click')
			const modal = wrapper.findComponent({ name: 'CnAddWidgetModal' })
			expect(modal.props('editingWidget')).toEqual({
				type: ANY_SURFACE_TYPE,
				content: { label: 'current' },
			})
		})
	})

	describe('the note a custom page demands', () => {
		const customPage = (widgets = []) => ({
			id: 'bespoke',
			type: 'custom',
			config: {},
			widgets,
		})

		it('blocks the placement while the note is empty and says what it is for', async () => {
			const wrapper = mountPanel(customPage([]))
			const addButton = wrapper.find('[data-testid="placement-add"]')
			expect(addButton.attributes('disabled')).toBeDefined()
			expect(
				wrapper.find('[data-testid="pending-note-hint"]').text(),
			).toContain('document why a standard page type was not feasible')

			await addButton.trigger('click')
			expect(
				wrapper.findComponent({ name: 'CnAddWidgetModal' }).props('show'),
			).toBe(false)
			expect(wrapper.emitted('update:widgets')).toBeFalsy()
		})

		it('writes the note onto the placement once it is given', async () => {
			const wrapper = mountPanel(customPage([]))
			await wrapper
				.find('[data-testid="pending-note"]')
				.setValue('the dossier viewer has no standard page type')
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: {} })
			await wrapper.vm.$nextTick()

			expect(emittedWidgets(wrapper)[0]._note).toBe(
				'the dossier viewer has no standard page type',
			)
		})

		it('writes no _note key on a dashboard page', async () => {
			const wrapper = mountPanel(dashboardPage([]))
			expect(wrapper.find('[data-testid="pending-note"]').exists()).toBe(false)
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: {} })
			await wrapper.vm.$nextTick()

			expect(emittedWidgets(wrapper)[0]).not.toHaveProperty('_note')
		})

		it('preserves an existing note on a page of another type', async () => {
			const wrapper = mountPanel(
				dashboardPage([bodyWidget({ _note: 'written by the copilot' })]),
			)
			await wrapper.find('[data-testid="placement-grid-y"]').setValue('2')
			expect(emittedWidgets(wrapper)[0]._note).toBe('written by the copilot')
		})
	})

	describe('the custom page in disguise', () => {
		const lone12x12 = (widgetKey) =>
			dashboardPage([
				{
					id: 'only',
					widgetKey,
					slot: 'body',
					gridX: 0,
					gridY: 0,
					gridWidth: 12,
					gridHeight: 12,
				},
			])

		it('says so, and names both ways out', () => {
			const wrapper = mountPanel(lone12x12('case-timeline'))
			const warning = wrapper.find('[data-testid="disguise-warning"]')
			expect(warning.exists()).toBe(true)
			const text = warning.text()
			expect(text).toContain('custom page in disguise')
			// Way out (a): declare the page as custom, naming the component.
			expect(text).toContain('Declare this page as custom')
			expect(warning.find('[data-testid="disguise-component"]').text()).toBe(
				'case-timeline',
			)
			// Way out (b): add a second widget.
			expect(text).toContain('add a second widget')
		})

		it('stays quiet for a widget the library renders itself', () => {
			const wrapper = mountPanel(lone12x12('object-table'))
			expect(wrapper.find('[data-testid="disguise-warning"]').exists()).toBe(
				false,
			)
		})
	})

	describe('placement ids', () => {
		it('mints a different id for two placements of the same type', async () => {
			const page = dashboardPage([])
			const wrapper = mountPanel(page)

			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: {} })
			await wrapper.vm.$nextTick()
			const first = emittedWidgets(wrapper)

			// The parent is controlled: feed the emitted array back in, which is
			// what PageDesigner does through the manifest.
			await wrapper.setProps({ page: dashboardPage(first) })
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: {} })
			await wrapper.vm.$nextTick()
			const second = emittedWidgets(wrapper)

			expect(second).toHaveLength(2)
			expect(second[0].id).toBeTruthy()
			expect(second[1].id).toBeTruthy()
			expect(second[1].id).not.toBe(second[0].id)
			// Kebab-case, matching the widgetEntry id pattern.
			for (const entry of second) {
				expect(entry.id).toMatch(/^[a-z0-9]+(-[a-z0-9]+)*$/)
			}
		})

		it('never regenerates an id on an edit', async () => {
			const wrapper = mountPanel(
				dashboardPage([bodyWidget({ id: 'keeps-its-name' })]),
			)
			await wrapper.find('[data-testid="placement-edit"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: { a: 1 } })
			await wrapper.vm.$nextTick()

			expect(emittedWidgets(wrapper)[0].id).toBe('keeps-its-name')
		})
	})

	describe('keys the panel does not surface', () => {
		it('carries them through a geometry edit untouched', async () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget({
						tabGroup: 'general',
						roles: ['beheerders'],
						visibleWhen: { field: 'status', value: 'open' },
						requiredApp: 'openregister',
						dateChip: true,
						ncDashboard: { panel: 'cases' },
					}),
				]),
			)
			await wrapper.find('[data-testid="placement-grid-x"]').setValue('2')

			const stored = emittedWidgets(wrapper)[0]
			expect(stored.tabGroup).toBe('general')
			expect(stored.roles).toEqual(['beheerders'])
			expect(stored.visibleWhen).toEqual({ field: 'status', value: 'open' })
			expect(stored.requiredApp).toBe('openregister')
			expect(stored.dateChip).toBe(true)
			// The sibling change's property, which this change does not add and
			// must not drop either (design.md D8).
			expect(stored.ncDashboard).toEqual({ panel: 'cases' })
		})

		it('keeps props the sub-form does not own through an edit', async () => {
			const wrapper = mountPanel(
				dashboardPage([
					bodyWidget({ props: { label: 'old', keptByNobody: 'here' } }),
				]),
			)
			await wrapper.find('[data-testid="placement-edit"]').trigger('click')
			wrapper.findComponent({ name: 'CnAddWidgetModal' }).vm.$emit('submit', {
				type: ANY_SURFACE_TYPE,
				content: { label: 'new' },
			})
			await wrapper.vm.$nextTick()

			expect(emittedWidgets(wrapper)[0].props).toEqual({
				label: 'new',
				keptByNobody: 'here',
			})
		})

		it('writes the modal chrome inside props, where the schema accepts it', async () => {
			const wrapper = mountPanel(dashboardPage([]))
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper.findComponent({ name: 'CnAddWidgetModal' }).vm.$emit('submit', {
				type: ANY_SURFACE_TYPE,
				content: {},
				chrome: {
					customTitle: 'Open cases',
					showTitle: true,
					customIcon: 'mdi-folder',
					backgroundColor: '#eeeeee',
				},
			})
			await wrapper.vm.$nextTick()

			const stored = emittedWidgets(wrapper)[0]
			// The v2 widgetEntry is additionalProperties:false and declares no
			// title / showTitle / icon / styleConfig, so a top-level write would
			// produce a placement the schema rejects.
			expect(stored).not.toHaveProperty('title')
			expect(stored).not.toHaveProperty('styleConfig')
			expect(stored.props).toEqual({
				title: 'Open cases',
				showTitle: true,
				icon: 'mdi-folder',
				styleConfig: { backgroundColor: '#eeeeee' },
			})
		})

		it('writes no empty props key when the widget has no content', async () => {
			const wrapper = mountPanel(dashboardPage([]))
			await wrapper.find('[data-testid="placement-add"]').trigger('click')
			wrapper
				.findComponent({ name: 'CnAddWidgetModal' })
				.vm.$emit('submit', { type: ANY_SURFACE_TYPE, content: {} })
			await wrapper.vm.$nextTick()

			expect(emittedWidgets(wrapper)[0]).not.toHaveProperty('props')
		})
	})
})
