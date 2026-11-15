/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the chart widget helpers (pages-chart-widget-authoring D2, D3).
 */

import { describe, expect, it } from 'vitest'
import {
	CHART_GRID_HEIGHT,
	CHART_GRID_WIDTH,
	chartFormInput,
	layoutWithItemFor,
	newChartWidget,
	nextChartId,
	widgetWithChartContent,
} from '../../../src/components/page-editor/fields/chartWidget.js'
import { validateWidgetDef } from './widgetDefValidator.js'

describe('chart widget helpers', () => {
	it('round-trips the form content through the widget without a content key', () => {
		const content = { chartKind: 'line', dataSource: { register: 'r', schema: 's', bucket: { field: 'created', interval: 'week' } } }
		const widget = widgetWithChartContent({ id: 'c', type: 'chart', content: { stale: true } }, content)

		expect(widget).not.toHaveProperty('content')
		expect(chartFormInput(widget)).toEqual({ content })
	})

	it('a widget with no props or dataSource opens the form empty', () => {
		expect(chartFormInput({ id: 'c', type: 'chart' })).toEqual({ content: { chartKind: undefined, dataSource: {} } })
	})

	it('takes the first unused chart id', () => {
		expect(nextChartId([])).toBe('chart-1')
		expect(nextChartId([{ id: 'chart-1' }, { id: 'chart-3' }, { id: 'kpi' }])).toBe('chart-2')
	})

	it('seeds a complete bar chart that validates', () => {
		const widget = newChartWidget('chart-4')
		expect(widget).toEqual({
			id: 'chart-4',
			title: '',
			type: 'chart',
			props: { chartKind: 'bar' },
			dataSource: { register: '', schema: '' },
		})
		expect(validateWidgetDef(widget)).toEqual([])
	})

	it('places a new chart below the lowest item', () => {
		const layout = [
			{ id: 1, widgetId: 'a', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 2 },
			{ id: 2, widgetId: 'b', gridX: 6, gridY: 1, gridWidth: 6, gridHeight: 3 },
		]

		expect(layoutWithItemFor(layout, 'chart-1')).toEqual([
			...layout,
			{ id: 3, widgetId: 'chart-1', gridX: 0, gridY: 4, gridWidth: CHART_GRID_WIDTH, gridHeight: CHART_GRID_HEIGHT },
		])
	})

	it('adds no second item for a widget already placed', () => {
		const layout = [{ id: 1, widgetId: 'chart-1', gridX: 0, gridY: 0, gridWidth: 6, gridHeight: 4 }]
		expect(layoutWithItemFor(layout, 'chart-1')).toBe(layout)
	})

	it('starts an empty or missing layout at the top', () => {
		expect(layoutWithItemFor(undefined, 'chart-1')).toEqual([
			{ id: 1, widgetId: 'chart-1', gridX: 0, gridY: 0, gridWidth: CHART_GRID_WIDTH, gridHeight: CHART_GRID_HEIGHT },
		])
	})
})
