/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the two read-only schema-editor panels,
 * `AggregationEditor.vue` and `NotificationEditor.vue`. Both show an existing
 * block as pretty-printed JSON, or an empty message, and never emit.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AggregationEditor from '../../../src/components/schema-editor/AggregationEditor.vue'
import NotificationEditor from '../../../src/components/schema-editor/NotificationEditor.vue'

const stubs = {
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}

const cases = [
	{
		component: AggregationEditor,
		prop: 'aggregations',
		base: 'buildiq-aggregation-editor',
		note: 'You cannot edit aggregations here yet. Aggregations already on this schema are listed below.',
		empty: 'No aggregations declared on this schema.',
	},
	{
		component: NotificationEditor,
		prop: 'notifications',
		base: 'buildiq-notification-editor',
		note: 'You cannot edit notifications here yet. Notifications already on this schema are listed below.',
		empty: 'No notifications declared on this schema.',
	},
]

describe.each(cases)('$component.name', ({ component, prop, base, note, empty }) => {
	it('says the block is read-only and shows the empty message without a block', () => {
		const wrapper = mount(component, { global: { stubs } })

		expect(wrapper.find('.note').text()).toBe(note)
		expect(wrapper.find('.note').attributes('data-type')).toBe('info')
		expect(wrapper.find(`.${base}__empty`).text()).toBe(empty)
		expect(wrapper.find(`.${base}__readonly`).exists()).toBe(false)
	})

	it('pretty-prints an existing block', () => {
		const block = { total: { field: 'amount', op: 'sum' } }
		const wrapper = mount(component, { props: { [prop]: block }, global: { stubs } })

		expect(wrapper.find(`.${base}__readonly`).text()).toBe(JSON.stringify(block, null, 2))
		expect(wrapper.find(`.${base}__empty`).exists()).toBe(false)
	})

	it('prints an array block too', () => {
		const wrapper = mount(component, { props: { [prop]: [{ on: 'create' }] }, global: { stubs } })

		expect(wrapper.vm.formatted).toBe('[\n  {\n    "on": "create"\n  }\n]')
	})

	it('falls back to an empty string for a block JSON cannot print', () => {
		const block = { n: 1n }
		const wrapper = mount(component, { props: { [prop]: block }, global: { stubs } })

		expect(wrapper.vm.formatted).toBe('')
		expect(wrapper.find(`.${base}__readonly`).text()).toBe('')
	})
})
