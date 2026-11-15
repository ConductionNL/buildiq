/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RuleSetTestSandboxModal as rendered: the rule set's cases in the table with
 * their last result, the run button and its summary line (with the failed
 * count only when something failed), the add form toggled from the toolbar
 * and saved from its fields, and errors in a note card.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import RuleSetTestSandboxModal from '../../src/modals/RuleSetTestSandboxModal.vue'

function field (name) {
  return {
	name,
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	render: () => h('div'),
}
}

const stubs = {
	NcModal: {
		name: 'NcModal',
		emits: ['close'],
		template: '<div class="modal"><slot /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<i class="loading" />' },
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	NcTextField: field('NcTextField'),
	NcTextArea: field('NcTextArea'),
}

const CASES = [
	{ slug: 'a', name: 'adult', description: 'Over 18', ruleSetId: 'eligibility' },
	{ name: 'minor', description: 'Under 18', ruleSetId: 'eligibility' },
	{ name: 'other', ruleSetId: 'pricing' },
]

async function mountModal() {
	const wrapper = mount(RuleSetTestSandboxModal, {
		props: { ruleSet: { slug: 'eligibility', name: 'Eligibility' } },
		global: { stubs },
	})
	await flushPromises()
	return wrapper
}

const buttons = (wrapper) => wrapper.findAll('button')
function results (wrapper) {
  return wrapper
		.findAll('[data-testid="test-case-row"] td:nth-child(3) span')
		.map((s) => [s.text(), s.classes()[1]])
}

beforeEach(() => {
	axios.get.mockReset().mockResolvedValue({ data: { results: CASES } })
	axios.post.mockReset()
})

describe('RuleSetTestSandboxModal rendered', () => {
	it("lists the rule set's cases as not run yet", async () => {
		const wrapper = await mountModal()
		expect(wrapper.find('h2').text()).toBe('Test sandbox — Eligibility')
		const rows = wrapper.findAll('[data-testid="test-case-row"]')
		expect(
			rows.map((r) =>
				r
					.findAll('td')
					.slice(0, 2)
					.map((td) => td.text()),
			),
		).toEqual([
			['adult', 'Over 18'],
			['minor', 'Under 18'],
		])
		expect(results(wrapper)).toEqual([
			['Not run', 'rule-set-test-sandbox__result--unknown'],
			['Not run', 'rule-set-test-sandbox__result--unknown'],
		])
		expect(wrapper.find('[data-testid="test-summary"]').exists()).toBe(false)
	})

	it('runs all cases and shows the summary and each result', async () => {
		let finish
		axios.post.mockReturnValue(
			new Promise((r) => {
				finish = r
			}),
		)
		const wrapper = await mountModal()
		await buttons(wrapper)[0].trigger('click')
		expect(buttons(wrapper)[0].text()).toBe('Running…')
		expect(buttons(wrapper)[0].attributes('disabled')).toBeDefined()

		finish({ data: { passed: 1, failed: 1, total: 2, failures: ['minor'] } })
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/rules/eligibility/test-all',
			{},
		)
		expect(
			wrapper.find('[data-testid="test-summary"]').text().replace(/\s+/g, ' '),
		).toBe('Passed: 1 / 2 (1 failed)')
		expect(results(wrapper)).toEqual([
			['Passed', 'rule-set-test-sandbox__result--pass'],
			['Failed', 'rule-set-test-sandbox__result--fail'],
		])
		expect(buttons(wrapper)[0].text()).toBe('Run all tests')
	})

	it('leaves the failed count out when everything passed, and shows a failed run', async () => {
		axios.post.mockResolvedValueOnce({
			data: { passed: 2, failed: 0, total: 2 },
		})
		const wrapper = await mountModal()
		await buttons(wrapper)[0].trigger('click')
		await flushPromises()
		expect(wrapper.find('.rule-set-test-sandbox__failed').exists()).toBe(false)

		axios.post.mockRejectedValueOnce(new Error('down'))
		await buttons(wrapper)[0].trigger('click')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Test run failed.')
	})

	it('adds a test case from the form fields, then hides the form and re-reads', async () => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await mountModal()
		expect(wrapper.find('.rule-set-test-sandbox__add').exists()).toBe(false)
		await buttons(wrapper)[1].trigger('click')
		const [name] = wrapper.findAllComponents({ name: 'NcTextField' })
		const [input, expected] = wrapper.findAllComponents({ name: 'NcTextArea' })
		await name.vm.$emit('update:modelValue', 'senior')
		await input.vm.$emit('update:modelValue', '{"age": 70}')
		await expected.vm.$emit('update:modelValue', '{"eligible": true}')
		await buttons(wrapper)[2].trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/rule-test-case',
			{
				ruleSetId: 'eligibility',
				name: 'senior',
				inputPayload: { age: 70 },
				expectedResult: { eligible: true },
				lastTestResult: 'not-run',
			},
		)
		expect(wrapper.find('.rule-set-test-sandbox__add').exists()).toBe(false)
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('refuses invalid JSON in the form with a note', async () => {
		const wrapper = await mountModal()
		await buttons(wrapper)[1].trigger('click')
		await wrapper
			.findAllComponents({ name: 'NcTextArea' })[0]
			.vm.$emit('update:modelValue', '{not json')
		await buttons(wrapper)[2].trigger('click')
		await flushPromises()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.note').text()).toBe(
			'Could not save the test case — check the JSON is valid.',
		)
		expect(wrapper.find('.rule-set-test-sandbox__add').exists()).toBe(true)
	})

	it('shows a loader until the cases arrive and closes from the modal', async () => {
		let finish
		axios.get.mockReturnValueOnce(
			new Promise((r) => {
				finish = r
			}),
		)
		const wrapper = mount(RuleSetTestSandboxModal, {
			props: { ruleSet: { slug: 'eligibility', name: 'E' } },
			global: { stubs },
		})
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.loading').exists()).toBe(true)
		finish({ data: [] })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
