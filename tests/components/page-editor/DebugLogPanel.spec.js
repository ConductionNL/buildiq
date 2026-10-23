/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQDM-001, REQ-BQDM-002: the page designer's error log (board BqPaginaOntwerperFouten).
 *
 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/builder-debug-log/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import DebugLogPanel from '../../../src/components/page-editor/DebugLogPanel.vue'

const ENTRIES = [
	{ key: 'b', kind: 'binding', pageId: 'ticket', problem: 'missing-property', property: 'subjectReference.title', schema: 'onderwerp', register: 'pipelinq', where: 'column', count: 12, lastAt: 0 },
	{ key: 'r', kind: 'request', pageId: 'ticket', request: 'GET /objects/buildiq/agent?app', status: 403, durationMs: 40, count: 1, lastAt: 0 },
]

describe('DebugLogPanel', () => {
	it('names each problem, its page and its request', () => {
		const wrapper = mount(DebugLogPanel, { propsData: { entries: ENTRIES, pageTitles: { ticket: 'Ticket' } } })
		const rows = wrapper.findAll('[data-testid="debug-log-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Ticket')
		// The test t() does not fill {placeholders}; the sentence shows which kind it is.
		expect(rows[0].text()).toContain('is not a field of schema')
		expect(rows[1].text()).toContain('GET /objects/buildiq/agent?app')
	})

	it('filters on failed requests and opens a problem\'s page', async () => {
		const wrapper = mount(DebugLogPanel, { propsData: { entries: ENTRIES } })
		await wrapper.find('[data-testid="debug-log-filter"]').setValue('request')
		expect(wrapper.findAll('[data-testid="debug-log-row"]')).toHaveLength(1)

		await wrapper.find('.ob-debug-log__toggle').trigger('click')
		const goTo = wrapper.findAll('button').find((b) => b.text() === 'Go to the page')
		await goTo.trigger('click')
		expect(wrapper.emitted('selectPage')[0]).toEqual(['ticket'])
	})

	it('says there is nothing wrong, and offers clear and reload', async () => {
		const wrapper = mount(DebugLogPanel, { propsData: { entries: [] } })
		expect(wrapper.find('[data-testid="debug-log-empty"]').exists()).toBe(true)
		await wrapper.find('[data-testid="debug-log-reload"]').trigger('click')
		expect(wrapper.emitted('reload')).toHaveLength(1)
	})
})
