/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RuleSetTestSandboxModal — a rule set's stored test cases.
 *
 * Covers:
 *  - only the rule set's own test cases are listed, from either response shape.
 *  - a failed load says so.
 *  - running all records the summary and the failed names; a failed run says so.
 *  - the per-case result class and label before and after a run.
 *  - adding a case posts the parsed JSON, resets the draft and re-reads;
 *    invalid JSON is refused without a request.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import RuleSetTestSandboxModal from '../../src/modals/RuleSetTestSandboxModal.vue'

const CASES_URL = '/apps/openregister/api/objects/buildiq/rule-test-case'
const CASES = [
	{ name: 'adult', ruleSetId: 'eligibility' },
	{ name: 'minor', ruleSetId: 'eligibility' },
	{ name: 'other', ruleSetId: 'pricing' },
]

async function mountModal() {
	const wrapper = shallowMount(RuleSetTestSandboxModal, { props: { ruleSet: { slug: 'eligibility' } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.get.mockResolvedValue({ data: { results: CASES } })
})

describe('RuleSetTestSandboxModal', () => {
	it('lists only this rule set\'s cases on mount', async () => {
		const wrapper = await mountModal()
		expect(axios.get).toHaveBeenCalledWith(CASES_URL)
		expect(wrapper.vm.testCases.map((tc) => tc.name)).toEqual(['adult', 'minor'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('accepts a bare array response', async () => {
		axios.get.mockResolvedValue({ data: CASES })
		const wrapper = await mountModal()
		expect(wrapper.vm.testCases).toHaveLength(2)
	})

	it('treats a response without results as no cases', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const wrapper = await mountModal()
		expect(wrapper.vm.testCases).toEqual([])
	})

	it('reports a failed load', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = await mountModal()
		expect(wrapper.vm.errorMessage).toBe('Could not load test cases.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('records the summary and failures of a run', async () => {
		axios.post.mockResolvedValue({ data: { passed: 1, failed: 1, failures: ['minor'] } })
		const wrapper = await mountModal()
		await wrapper.vm.runAll()

		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/rules/eligibility/test-all', {})
		expect(wrapper.vm.summary).toEqual({ passed: 1, failed: 1, failures: ['minor'] })
		expect(wrapper.vm.failedNames).toEqual(['minor'])
		expect(wrapper.vm.running).toBe(false)
	})

	it('a run without failures lists none', async () => {
		axios.post.mockResolvedValue({ data: { passed: 2 } })
		const wrapper = await mountModal()
		await wrapper.vm.runAll()
		expect(wrapper.vm.failedNames).toEqual([])
	})

	it('reports a failed run', async () => {
		axios.post.mockRejectedValue(new Error('500'))
		const wrapper = await mountModal()
		await wrapper.vm.runAll()
		expect(wrapper.vm.errorMessage).toBe('Test run failed.')
		expect(wrapper.vm.summary).toBeNull()
		expect(wrapper.vm.running).toBe(false)
	})

	it('shows each case as not run, then passed or failed', async () => {
		const wrapper = await mountModal()
		const [adult, minor] = wrapper.vm.testCases
		expect(wrapper.vm.resultLabel(adult)).toBe('Not run')
		expect(wrapper.vm.resultClass(adult)).toBe('rule-set-test-sandbox__result--unknown')

		axios.post.mockResolvedValue({ data: { failures: ['minor'] } })
		await wrapper.vm.runAll()
		expect(wrapper.vm.resultLabel(adult)).toBe('Passed')
		expect(wrapper.vm.resultClass(adult)).toBe('rule-set-test-sandbox__result--pass')
		expect(wrapper.vm.resultLabel(minor)).toBe('Failed')
		expect(wrapper.vm.resultClass(minor)).toBe('rule-set-test-sandbox__result--fail')
	})

	it('adds a case with its parsed JSON, resets the draft and re-reads', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountModal()
		wrapper.vm.showAdd = true
		wrapper.vm.draft = { name: 'senior', inputPayloadText: '{"age":70}', expectedText: '{"eligible":true}' }
		await wrapper.vm.addTestCase()
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(CASES_URL, {
			ruleSetId: 'eligibility',
			name: 'senior',
			inputPayload: { age: 70 },
			expectedResult: { eligible: true },
			lastTestResult: 'not-run',
		})
		expect(wrapper.vm.showAdd).toBe(false)
		expect(wrapper.vm.draft).toEqual({ name: '', inputPayloadText: '{}', expectedText: '{}' })
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('blank JSON fields are sent as empty objects', async () => {
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountModal()
		wrapper.vm.draft = { name: 'x', inputPayloadText: '', expectedText: '' }
		await wrapper.vm.addTestCase()
		const [, body] = axios.post.mock.calls[0]
		expect(body.inputPayload).toEqual({})
		expect(body.expectedResult).toEqual({})
	})

	it('refuses a case with invalid JSON without a request', async () => {
		const wrapper = await mountModal()
		wrapper.vm.showAdd = true
		wrapper.vm.draft = { name: 'bad', inputPayloadText: '{age:', expectedText: '{}' }
		await wrapper.vm.addTestCase()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.errorMessage).toBe('Could not save the test case — check the JSON is valid.')
		expect(wrapper.vm.showAdd).toBe(true)
		expect(wrapper.vm.saving).toBe(false)
	})
})
