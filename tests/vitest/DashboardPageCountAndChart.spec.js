/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the dashboard page editor (capability-requirements-backfill
 * T07, REQ-BQCV-007): a maker adds a dashboard page, a count tile of open
 * permits and a chart, and places both on the layout grid. The editor writes
 * `config.widgets` (a `stats-block` count with its `dataSource`, a `chart`
 * with `props.chartKind` and `dataSource`) and `config.layout` items that
 * reference them; nextcloud-vue's CnDashboardPage renders each widget in its
 * cell, which is the library's and is not tested again here.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-dashboard-page-req-bqcv-007
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DashboardPageEditor from '../../src/components/page-editor/DashboardPageEditor.vue'
import WidgetBuilder from '../../src/components/page-editor/fields/WidgetBuilder.vue'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import { countFormInput, newCountWidget, nextCountId, widgetWithCountContent } from '../../src/components/page-editor/fields/countWidget.js'
import { validateWidgetDef } from '../components/page-editor/widgetDefValidator.js'
import stubDraggable from './stubs/vuedraggable.js'

/**
 * Mount the dashboard editor on a config, with its builders stubbed.
 *
 * @param {object} config The page config.
 * @return {object} The wrapper.
 */
function dashboard(config) {
	return mount(DashboardPageEditor, {
		props: { config, pageType: 'dashboard' },
		global: {
			stubs: {
				WidgetBuilder: { name: 'WidgetBuilder', template: '<div />' },
				LayoutItemBuilder: { name: 'LayoutItemBuilder', template: '<div />' },
				InlineFieldMark: { template: '<span />' },
				DataSourceOriginToggle: { template: '<div />' },
			},
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

/**
 * Apply the config the editor emitted last back onto it, as PageDesigner does.
 *
 * @param {object} wrapper The editor wrapper.
 * @return {Promise<object>} The config.
 */
async function takeConfig(wrapper) {
	const config = wrapper.emitted('update:config').at(-1)[0]
	await wrapper.setProps({ config })
	return config
}

describe('REQ-BQCV-007 dashboard page', () => {
	it('a maker adds a dashboard page', async () => {
		const list = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		list.vm.startAdd()
		list.vm.addingType = 'dashboard'
		list.vm.confirmAdd()
		await list.vm.$nextTick()
		const page = list.emitted('update:pages')[0][0][0]
		expect(page.type).toBe('dashboard')
		expect(page.config).toEqual({ widgets: [], layout: [] })
	})

	it('adds a count tile of open permits and a chart, each placed on the layout', async () => {
		const wrapper = dashboard({ widgets: [], layout: [] })

		await wrapper.find('[data-testid="dashboard-page-add-count"]').trigger('click')
		let config = await takeConfig(wrapper)
		expect(config.widgets).toEqual([newCountWidget('count-1')])
		expect(config.layout).toHaveLength(1)
		expect(config.layout[0]).toMatchObject({ widgetId: 'count-1', gridX: 0, gridY: 0 })

		// The maker sets what the tile counts in the library's stats-block form.
		const counted = widgetWithCountContent(config.widgets[0], {
			title: 'Open permits',
			props: { countLabel: 'open', variant: 'primary', iconClass: '' },
			dataSource: { register: 'vergunningen', schema: 'permit', metric: 'count', field: '', filter: { status: 'open' } },
		})
		wrapper.findComponent({ name: 'WidgetBuilder' }).vm.$emit('update:modelValue', [counted])
		await takeConfig(wrapper)

		await wrapper.find('.dashboard-page-editor__add-chart').trigger('click')
		config = await takeConfig(wrapper)

		// The maker moves the chart beside the tile.
		const layout = config.layout.map((item) => (item.widgetId === 'chart-1' ? { ...item, gridX: 6, gridY: 0 } : item))
		wrapper.findComponent({ name: 'LayoutItemBuilder' }).vm.$emit('update:modelValue', layout)
		config = await takeConfig(wrapper)

		expect(config.widgets.map((widget) => [widget.id, widget.type])).toEqual([['count-1', 'stats-block'], ['chart-1', 'chart']])
		expect(config.widgets[0]).toEqual({
			id: 'count-1',
			title: 'Open permits',
			type: 'stats-block',
			props: { countLabel: 'open', variant: 'primary', iconClass: '' },
			dataSource: { register: 'vergunningen', schema: 'permit', metric: 'count', field: '', filter: { status: 'open' } },
		})
		expect(config.layout.find((item) => item.widgetId === 'count-1')).toMatchObject({ gridX: 0, gridY: 0 })
		expect(config.layout.find((item) => item.widgetId === 'chart-1')).toMatchObject({ gridX: 6, gridY: 0 })
		for (const widget of config.widgets) {
			expect(validateWidgetDef(widget)).toEqual([])
		}
	})

	it('a second count gets its own id and its own cell', async () => {
		const wrapper = dashboard({ widgets: [newCountWidget('count-1')], layout: [{ id: 1, widgetId: 'count-1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 }] })
		await wrapper.find('[data-testid="dashboard-page-add-count"]').trigger('click')
		const config = wrapper.emitted('update:config').at(-1)[0]
		expect(config.widgets.map((widget) => widget.id)).toEqual(['count-1', 'count-2'])
		expect(config.layout.find((item) => item.widgetId === 'count-2')).toMatchObject({ gridY: 4 })
		expect(nextCountId(config.widgets)).toBe('count-3')
	})
})

describe('REQ-BQCV-007 count widget in the widget builder', () => {
	it('shows the stats-block form under a count widget only, filled from the saved widget', () => {
		const count = {
			id: 'count-1',
			title: 'Open permits',
			type: 'stats-block',
			props: { countLabel: 'open' },
			dataSource: { register: 'vergunningen', schema: 'permit', metric: 'count', filter: { status: 'open' } },
		}
		const wrapper = mount(WidgetBuilder, { props: { modelValue: [{ id: 'note', title: 'Note', type: 'custom' }, count] } })
		const forms = wrapper.findAllComponents({ name: 'CnStatsBlockWidgetForm' })
		expect(forms).toHaveLength(1)
		expect(forms[0].props('editingWidget')).toEqual(countFormInput(count))
		expect(countFormInput(count).content).toEqual({
			title: 'Open permits',
			props: { countLabel: 'open' },
			dataSource: { register: 'vergunningen', schema: 'permit', metric: 'count', filter: { status: 'open' } },
		})
	})

	it('writes the form content onto the widget without a content key', async () => {
		const wrapper = mount(WidgetBuilder, { props: { modelValue: [newCountWidget('count-1')] } })
		wrapper.findComponent({ name: 'CnStatsBlockWidgetForm' }).vm.$emit('update:content', {
			title: 'Open permits',
			props: { countLabel: 'open', variant: 'default', iconClass: '' },
			dataSource: { register: 'vergunningen', schema: 'permit', metric: 'count', field: '', filter: { status: 'open' } },
		})
		await wrapper.vm.$nextTick()
		const saved = wrapper.emitted('update:modelValue')[0][0][0]
		expect(saved).toMatchObject({ id: 'count-1', type: 'stats-block', title: 'Open permits' })
		expect(saved.dataSource.filter).toEqual({ status: 'open' })
		expect(saved).not.toHaveProperty('content')
		expect(validateWidgetDef(saved)).toEqual([])
	})
})
