/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCV-017: a maker exports a rule set from the rule sets page. Export
 * asks the server for the rule set's export document and downloads it as one
 * JSON file named after the rule set's slug, holding the decision table and
 * the test cases and no execution logs. What the server puts in the document
 * is asserted by RulesControllerExportTest.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, vars = {}) => p.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import RuleSetsPage from '../../src/views/RuleSetsPage.vue'

const stubs = {
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: true,
	NcEmptyContent: true,
	NcNoteCard: { template: '<div class="note-stub"><slot /></div>' },
	DecisionTableEditor: true,
	ConditionActionRuleEditor: true,
	RuleSetCallersDialog: true,
	RuleSetTestSandbox: true,
}

const RULE_SET = {
	slug: 'loan-eligibility',
	name: 'Loan eligibility',
	ruleType: 'decision-table',
	status: 'active',
	version: '1.0.0',
}

const EXPORTED = {
	format: 'buildiq.rule-set-export.v1',
	ruleSet: RULE_SET,
	decisionTables: [{ hitPolicy: 'first', inputColumns: [{ name: 'Income' }], rules: [{ conditions: {}, values: { ok: true } }] }],
	conditionActionRules: [],
	testCases: [
		{ ruleSetId: 'loan-eligibility', name: 'High income passes' },
		{ ruleSetId: 'loan-eligibility', name: 'Low income fails' },
	],
}

beforeEach(() => {
	axios.get.mockReset()
	vi.restoreAllMocks()
})

describe('REQ-BQCV-017 a rule set can be exported as a file', () => {
	it('A maker exports a rule set: the browser downloads one JSON file named after the slug with the table and both test cases', async () => {
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const blobs = []
		URL.createObjectURL = vi.fn((blob) => {
			blobs.push(blob)
			return 'blob:export'
		})
		URL.revokeObjectURL = vi.fn()
		axios.get.mockResolvedValueOnce({ data: { results: [RULE_SET] } })
		const wrapper = mount(RuleSetsPage, { global: { stubs, mocks: { $route: { query: {} } } } })
		await flushPromises()

		axios.get.mockResolvedValueOnce({ data: EXPORTED })
		const exportButton = wrapper.findAll('[data-testid="rule-set-row"] button').find((b) => b.text() === 'Export')
		await exportButton.trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/buildiq/api/rules/loan-eligibility/export')
		expect(click).toHaveBeenCalledTimes(1)
		expect(click.mock.contexts[0].download).toBe('loan-eligibility.json')
		expect(blobs).toHaveLength(1)
		expect(blobs[0].type).toBe('application/json')

		const file = JSON.parse(await blobs[0].text())
		expect(file.ruleSet.slug).toBe('loan-eligibility')
		expect(file.decisionTables).toHaveLength(1)
		expect(file.testCases.map((c) => c.name)).toEqual(['High income passes', 'Low income fails'])
		expect(Object.keys(file).filter((key) => /log/i.test(key))).toEqual([])
	})
})
