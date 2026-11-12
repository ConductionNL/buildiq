/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RuleSetsPage.vue: the list states, the per-row actions
 * (edit by rule type, sandbox, test run with its badge, JSON export), the
 * blank draft of "New rule set" and the reload after a save.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import RuleSetsPage from '../../src/views/RuleSetsPage.vue'

const NcButtonStub = {
	name: 'NcButton',
	emits: ['click'],
	template: '<button @click="$emit(\'click\')"><slot /></button>',
}
function editorStub (name) {
  return {
	name,
	props: ['ruleSet'],
	emits: ['close', 'saved'],
	template: `<div class="${name}" />`,
}
}

const stubs = {
	NcButton: NcButtonStub,
	NcLoadingIcon: { template: '<div class="loading-stub" />' },
	NcEmptyContent: { props: ['name'], template: '<div class="empty-stub">{{ name }}</div>' },
	NcNoteCard: { template: '<div class="note-stub"><slot /></div>' },
	DecisionTableEditor: editorStub('DecisionTableEditor'),
	ConditionActionRuleEditor: editorStub('ConditionActionRuleEditor'),
	RuleSetTestSandbox: editorStub('RuleSetTestSandbox'),
}

const DT = { slug: 'fees', name: 'Fees', ruleType: 'decision-table', status: 'active', version: '1.2.0', ownerApp: 'buildiq' }
const CA = { slug: 'notify', name: 'Notify', ruleType: 'condition-action', status: 'draft', version: '0.1.0', ownerApp: 'x' }

/**
 * Mount the page with the given list response and wait for the load.
 *
 * @param {Array<object>} list The rule sets the API returns.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountPage(list = [DT, CA]) {
	axios.get.mockResolvedValueOnce({ data: { results: list } })
	const wrapper = mount(RuleSetsPage, { global: { stubs } })
	await flushPromises()
	return wrapper
}

/**
 * The action button with this label in the given row.
 *
 * @param {import('@vue/test-utils').DOMWrapper} row The table row.
 * @param {string} label The button text.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function button(row, label) {
	return row.findAll('button').find((b) => b.text() === label)
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('RuleSetsPage', () => {
	it('shows a loader until the list arrives', async () => {
		let resolve
		axios.get.mockReturnValueOnce(new Promise((r) => { resolve = r }))
		const wrapper = mount(RuleSetsPage, { global: { stubs } })

		expect(wrapper.find('.loading-stub').exists()).toBe(true)
		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/rule-set')

		resolve({ data: [] })
		await flushPromises()
		expect(wrapper.find('.loading-stub').exists()).toBe(false)
		expect(wrapper.find('.empty-stub').text()).toBe('No rule sets yet')
	})

	it('renders one row per rule set with an unrun test badge', async () => {
		const wrapper = await mountPage()
		const rows = wrapper.findAll('[data-testid="rule-set-row"]')

		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Fees')
		expect(rows[0].find('.rule-sets-page__status').classes()).toContain('rule-sets-page__status--active')
		const badge = rows[0].find('.rule-sets-page__test')
		expect(badge.text()).toBe('Not run')
		expect(badge.classes()).toContain('rule-sets-page__test--unknown')
	})

	it('shows the load error', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = mount(RuleSetsPage, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.note-stub').text()).toBe('Could not load rule sets.')
	})

	it('edits a decision table in the decision-table editor', async () => {
		const wrapper = await mountPage()
		await button(wrapper.findAll('[data-testid="rule-set-row"]')[0], 'Edit').trigger('click')

		const editor = wrapper.findComponent({ name: 'DecisionTableEditor' })
		expect(editor.exists()).toBe(true)
		expect(editor.props('ruleSet')).toEqual(DT)
		expect(wrapper.findComponent({ name: 'ConditionActionRuleEditor' }).exists()).toBe(false)
	})

	it('edits a condition-action set in its own editor and closes it', async () => {
		const wrapper = await mountPage()
		await button(wrapper.findAll('[data-testid="rule-set-row"]')[1], 'Edit').trigger('click')

		const editor = wrapper.findComponent({ name: 'ConditionActionRuleEditor' })
		expect(editor.props('ruleSet')).toEqual(CA)
		expect(wrapper.findComponent({ name: 'DecisionTableEditor' }).exists()).toBe(false)

		await editor.vm.$emit('close')
		expect(wrapper.findComponent({ name: 'ConditionActionRuleEditor' }).exists()).toBe(false)
	})

	it('opens the sandbox for a rule set', async () => {
		const wrapper = await mountPage()
		await button(wrapper.findAll('[data-testid="rule-set-row"]')[1], 'Test').trigger('click')

		const sandbox = wrapper.findComponent({ name: 'RuleSetTestSandbox' })
		expect(sandbox.props('ruleSet')).toEqual(CA)
		await sandbox.vm.$emit('close')
		expect(wrapper.findComponent({ name: 'RuleSetTestSandbox' }).exists()).toBe(false)
	})

	it('opens a blank draft decision table for a new rule set', async () => {
		const wrapper = await mountPage([])
		await wrapper.findAll('button').find((b) => b.text() === 'New rule set').trigger('click')

		expect(wrapper.findComponent({ name: 'DecisionTableEditor' }).props('ruleSet')).toEqual({
			slug: '',
			name: '',
			ruleType: 'decision-table',
			status: 'draft',
			version: '1.0.0',
		})
	})

	it('runs the tests and shows a passing badge', async () => {
		axios.post.mockResolvedValueOnce({ data: { passed: 3, total: 3, failed: 0 } })
		const wrapper = await mountPage()
		const row = wrapper.findAll('[data-testid="rule-set-row"]')[0]

		await button(row, 'Run tests').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/rules/fees/test-all', {})
		const badge = row.find('.rule-sets-page__test')
		expect(badge.text()).toBe('3/3')
		expect(badge.classes()).toContain('rule-sets-page__test--pass')
	})

	it('marks a run with failures as failing', () => {
		const ctx = { testResults: { fees: { passed: 1, total: 3, failed: 2 } } }
		expect(RuleSetsPage.methods.testBadgeClass.call(ctx, 'fees')).toBe('rule-sets-page__test--fail')
		expect(RuleSetsPage.methods.testBadgeLabel.call(ctx, 'fees')).toBe('1/3')
	})

	it('reports a failed test run', async () => {
		axios.post.mockRejectedValueOnce(new Error('500'))
		const wrapper = await mountPage()

		await button(wrapper.findAll('[data-testid="rule-set-row"]')[0], 'Run tests').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-stub').text()).toBe('Test run failed.')
		expect(wrapper.vm.testResults).toEqual({})
	})

	it('exports a rule set as a named JSON download', async () => {
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const blobs = []
		URL.createObjectURL = vi.fn((blob) => { blobs.push(blob); return 'blob:1' })
		URL.revokeObjectURL = vi.fn()
		const wrapper = await mountPage()

		await button(wrapper.findAll('[data-testid="rule-set-row"]')[0], 'Export').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
		expect(click.mock.contexts[0].download).toBe('fees.json')
		expect(blobs[0].type).toBe('application/json')
		expect(JSON.parse(await blobs[0].text())).toEqual(DT)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1')
	})

	it('names an export without a slug rule-set.json', () => {
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		URL.createObjectURL = vi.fn(() => 'blob:2')
		URL.revokeObjectURL = vi.fn()

		RuleSetsPage.methods.exportRuleSet({ name: 'x' })

		expect(click.mock.contexts[0].download).toBe('rule-set.json')
	})

	it('closes the editors and reloads after a save', async () => {
		const wrapper = await mountPage()
		await button(wrapper.findAll('[data-testid="rule-set-row"]')[0], 'Edit').trigger('click')
		axios.get.mockResolvedValueOnce({ data: [DT] })

		await wrapper.findComponent({ name: 'DecisionTableEditor' }).vm.$emit('saved')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'DecisionTableEditor' }).exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.findAll('[data-testid="rule-set-row"]')).toHaveLength(1)
	})
})
