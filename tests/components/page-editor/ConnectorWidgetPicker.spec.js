/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for placing a connector widget on a dashboard
 * (integrations-connector-bound-pages T04, REQ-BQCB-003): WidgetBuilder offers
 * the `connector-data` widget and binds it through the origin toggle, and
 * DashboardPageEditor adds it to `widgets` and `layout` in one update.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import DashboardPageEditor from '../../../src/components/page-editor/DashboardPageEditor.vue'
import WidgetBuilder from '../../../src/components/page-editor/fields/WidgetBuilder.vue'

const ToggleStub = {
	name: 'DataSourceOriginToggle',
	props: ['dataSource'],
	emits: ['update:dataSource'],
	template: '<div class="toggle-stub" />',
}

function mountBuilder(modelValue = []) {
	return mount(WidgetBuilder, {
		props: { modelValue },
		global: { stubs: { DataSourceOriginToggle: ToggleStub } },
	})
}

const binding = {
	connector: {
		endpointPath: 'open-requests',
		fields: { Title: 'title', Status: 'status' },
	},
}

describe('WidgetBuilder connector widget', () => {
	it('offers a connector widget that starts with an empty connector binding', async () => {
		const wrapper = mountBuilder([{ id: 'connector-1', type: 'stat' }])

		await wrapper
			.find('[data-testid="widget-builder-add-connector"]')
			.trigger('click')

		expect(wrapper.emitted('add:connectorWidget')[0][0]).toEqual({
			id: 'connector-2',
			title: '',
			type: 'connector-data',
			content: { dataSource: { connector: { endpointPath: '', fields: {} } } },
		})
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('shows the origin toggle only on a connector widget, bound to its content', () => {
		const wrapper = mountBuilder([
			{ id: 'stats', type: 'stat' },
			{ id: 'ext', type: 'connector-data', content: { dataSource: binding } },
		])

		const toggles = wrapper.findAllComponents(ToggleStub)
		expect(toggles).toHaveLength(1)
		expect(toggles[0].props('dataSource')).toEqual(binding)
	})

	it('saves the mapped data source on the widget and keeps its other content', async () => {
		const wrapper = mountBuilder([
			{
				id: 'ext',
				title: 'Requests',
				type: 'connector-data',
				content: { limit: 5 },
			},
		])

		wrapper.findComponent(ToggleStub).vm.$emit('update:dataSource', binding)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([
			{
				id: 'ext',
				title: 'Requests',
				type: 'connector-data',
				content: { limit: 5, dataSource: binding },
			},
		])
	})

	it('drops the data source when the toggle clears it', async () => {
		const wrapper = mountBuilder([
			{ id: 'ext', type: 'connector-data', content: { dataSource: binding } },
		])

		wrapper.findComponent(ToggleStub).vm.$emit('update:dataSource', {})
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:modelValue')[0][0][0].content).toEqual({})
	})
})

describe('DashboardPageEditor placing a connector widget', () => {
	function mountEditor(config) {
		return mount(DashboardPageEditor, {
			propsData: {
				config,
				pageType: 'dashboard',
				appSlug: 'hello-world',
				parentRoute: '/',
			},
			stubs: {
				DataSourceOriginToggle: ToggleStub,
				LayoutItemBuilder: { template: '<div />' },
				InlineFieldMark: { template: '<span />' },
			},
			mocks: {
				$validator: {
					register: vi.fn(),
					unregister: vi.fn(),
					errorsForPathPrefix: () => ({ errors: [], warnings: [] }),
					summaryForPathPrefix: () => null,
				},
			},
		})
	}

	it('adds the widget and a full-width cell below the layout in one update', async () => {
		const wrapper = mountEditor({
			title: 'kept',
			widgets: [{ id: 'stats', type: 'stat' }],
			layout: [
				{
					id: '1',
					widgetId: 'stats',
					gridX: 0,
					gridY: 1,
					gridWidth: 6,
					gridHeight: 2,
				},
			],
		})

		await wrapper
			.find('[data-testid="widget-builder-add-connector"]')
			.trigger('click')

		const emitted = wrapper.emitted('update:config')
		expect(emitted).toHaveLength(1)
		const config = emitted[0][0]
		expect(config.title).toBe('kept')
		expect(config.widgets.map((w) => [w.id, w.type])).toEqual([
			['stats', 'stat'],
			['connector-1', 'connector-data'],
		])
		expect(config.layout[1]).toEqual({
			id: 'connector-1',
			widgetId: 'connector-1',
			gridX: 0,
			gridY: 3,
			gridWidth: 12,
			gridHeight: 4,
		})
	})

	it('places the first widget of an empty dashboard at the top', async () => {
		const wrapper = mountEditor({})

		await wrapper
			.find('[data-testid="widget-builder-add-connector"]')
			.trigger('click')

		const config = wrapper.emitted('update:config')[0][0]
		expect(config.layout).toEqual([
			{
				id: 'connector-1',
				widgetId: 'connector-1',
				gridX: 0,
				gridY: 0,
				gridWidth: 12,
				gridHeight: 4,
			},
		])
	})
})
