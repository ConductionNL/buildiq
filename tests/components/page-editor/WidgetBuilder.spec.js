/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the chart rows of WidgetBuilder (pages-chart-widget-authoring T01).
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WidgetBuilder from '../../../src/components/page-editor/fields/WidgetBuilder.vue'
import { validateWidgetDef } from './widgetDefValidator.js'

function mountBuilder(widgets) {
	return mount(WidgetBuilder, { props: { modelValue: widgets } })
}

describe('WidgetBuilder chart rows', () => {
	it('shows the chart form under a chart widget only', () => {
		const wrapper = mountBuilder([
			{ id: 'kpi', title: 'Open', type: 'custom' },
			{ id: 'chart-1', title: '', type: 'chart', props: { chartKind: 'bar' }, dataSource: { register: '', schema: '' } },
		])

		const forms = wrapper.findAllComponents({ name: 'CnChartWidgetForm' })
		expect(forms).toHaveLength(1)
		expect(wrapper.findAll('.widget-builder__item')[1].findComponent({ name: 'CnChartWidgetForm' }).exists()).toBe(true)
	})

	it('saves a bar chart by category on requests.status as props.chartKind and dataSource', async () => {
		const wrapper = mountBuilder([
			{ id: 'chart-1', title: 'Requests', type: 'chart', props: { chartKind: 'bar', height: 300 }, dataSource: { register: '', schema: '' } },
		])
		const content = {
			chartKind: 'bar',
			dataSource: {
				register: 'crm',
				schema: 'requests',
				groupBy: { field: 'status', metric: 'count', sort: 'desc', limit: 8 },
			},
		}

		wrapper.findComponent({ name: 'CnChartWidgetForm' }).vm.$emit('update:content', content)
		await wrapper.vm.$nextTick()

		const saved = wrapper.emitted('update:modelValue')[0][0][0]
		expect(saved).toEqual({
			id: 'chart-1',
			title: 'Requests',
			type: 'chart',
			props: { chartKind: 'bar', height: 300 },
			dataSource: content.dataSource,
		})
		expect(saved).not.toHaveProperty('content')
		expect(validateWidgetDef(saved)).toEqual([])
	})

	it('pre-fills the form from a saved pie chart grouped by status', () => {
		const wrapper = mountBuilder([
			{
				id: 'chart-2',
				type: 'chart',
				props: { chartKind: 'pie' },
				dataSource: { register: 'crm', schema: 'requests', groupBy: { field: 'status' } },
			},
		])

		expect(wrapper.findComponent({ name: 'CnChartWidgetForm' }).props('editingWidget')).toEqual({
			content: {
				chartKind: 'pie',
				dataSource: { register: 'crm', schema: 'requests', groupBy: { field: 'status' } },
			},
		})
	})

	it('a chart edit leaves the other widgets as they were', async () => {
		const other = { id: 'kpi', title: 'Open', type: 'custom' }
		const wrapper = mountBuilder([other, { id: 'chart-1', type: 'chart', props: { chartKind: 'bar' }, dataSource: {} }])

		wrapper.findComponent({ name: 'CnChartWidgetForm' }).vm.$emit('update:content', { chartKind: 'line', dataSource: { register: 'r', schema: 's' } })
		await wrapper.vm.$nextTick()

		const saved = wrapper.emitted('update:modelValue')[0][0]
		expect(saved[0]).toEqual(other)
		expect(saved[1].props.chartKind).toBe('line')
	})
})
