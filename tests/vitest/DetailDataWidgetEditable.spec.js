/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for editing record fields in place on a detail page
 * (capability-requirements-backfill T13, REQ-BQCV-013).
 *
 * The inline edit itself (click a field, change it, save it to OpenRegister,
 * show the new value without a reload) is nextcloud-vue's CnObjectDataWidget,
 * mounted by CnDetailWidgetHost with
 * `editable = content.editable !== false && !recordEditDenied`. What buildiq
 * owns is the widget definition it writes into the manifest:
 *
 * - "A user corrects a field": a data widget the maker leaves alone carries no
 *   `content.editable`, so it stays editable for a user who may update.
 * - "A read-only widget offers no editing": the maker ticks Read-only and the
 *   widget carries `content.editable: false`, so no edit control opens.
 *
 * `content` is the key the library reads (widgetContentOf) and the one
 * buildiq's connector widget already writes; the strict JSON schema's
 * `widgetDef` does not list it yet, so it is not validated against here.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WidgetBuilder from '../../src/components/page-editor/fields/WidgetBuilder.vue'

/**
 * Mount the widget builder on a widget list.
 *
 * @param {Array<object>} widgets The page's widgets.
 * @return {object} The wrapper.
 */
function builder(widgets) {
	return mount(WidgetBuilder, { props: { modelValue: widgets } })
}

/**
 * The widget list the builder emitted last.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array<object>} The widgets.
 */
function lastWidgets(wrapper) {
	return wrapper.emitted('update:modelValue').at(-1)[0]
}

describe('detail page data widget: edit in place (REQ-BQCV-013)', () => {
	it('leaves the default data widget editable: no editable key is written', () => {
		const wrapper = builder([{ id: 'data', title: 'Permit', type: 'data' }])
		const toggle = wrapper.find('[data-testid="widget-builder-read-only"] input')

		expect(toggle.exists()).toBe(true)
		expect(toggle.element.checked).toBe(false)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
	})

	it('offers the read-only switch on a data widget only', () => {
		const wrapper = builder([
			{ id: 'data', title: '', type: 'data' },
			{ id: 'kpi', title: '', type: 'custom' },
		])
		const items = wrapper.findAll('.widget-builder__item')

		expect(items[0].find('[data-testid="widget-builder-read-only"]').exists()).toBe(true)
		expect(items[1].find('[data-testid="widget-builder-read-only"]').exists()).toBe(false)
	})

	it('writes content.editable false when the maker makes the widget read-only', async () => {
		const wrapper = builder([{ id: 'data', title: 'Permit', type: 'data', content: { columns: 2 } }])
		await wrapper.find('[data-testid="widget-builder-read-only"] input').setValue(true)

		const saved = lastWidgets(wrapper)[0]
		expect(saved).toEqual({ id: 'data', title: 'Permit', type: 'data', content: { columns: 2, editable: false } })
	})

	it('shows a read-only widget as read-only', () => {
		const wrapper = builder([{ id: 'data', title: '', type: 'data', content: { editable: false } }])

		expect(wrapper.find('[data-testid="widget-builder-read-only"] input').element.checked).toBe(true)
	})

	it('makes the widget editable again by taking the key out, dropping an empty content', async () => {
		const wrapper = builder([{ id: 'data', title: '', type: 'data', content: { editable: false } }])
		await wrapper.find('[data-testid="widget-builder-read-only"] input').setValue(false)

		expect(lastWidgets(wrapper)[0]).toEqual({ id: 'data', title: '', type: 'data' })
	})

	it('keeps the other widgets and content keys when switching', async () => {
		const other = { id: 'kpi', title: 'Open', type: 'custom' }
		const wrapper = builder([other, { id: 'data', title: '', type: 'data', content: { editable: false, hideEmpty: true } }])
		await wrapper.findAll('[data-testid="widget-builder-read-only"] input')[0].setValue(false)

		expect(lastWidgets(wrapper)).toEqual([other, { id: 'data', title: '', type: 'data', content: { hideEmpty: true } }])
	})
})
