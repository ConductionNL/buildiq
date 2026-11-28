/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuildiqFormPage: the running app's form page, which hands
 * CnFormPage its calculated fields and eligibility check
 * (forms-live-values-and-checks, T11).
 *
 * CnFormPage is replaced by a stand-in that keeps the contract of
 * nextcloud-vue's hooks: it calls `calculate(key, answers)` 400 ms after an
 * answer a calculated field reads changes, writes the result into the field
 * and emits `input`; it lists `unmetConditions` and disables submit while
 * `blockSubmit` holds and the list is not empty.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const evaluatePreview = vi.fn()

vi.mock('../../../src/services/formLiveValues.js', async (importOriginal) => ({
	...(await importOriginal()),
	evaluatePreview: (...args) => evaluatePreview(...args),
}))

const CnFormPageStandIn = {
	name: 'CnFormPage',
	props: {
		fields: { type: Array, default: () => [] },
		calculate: { type: Function, default: null },
		unmetConditions: { type: Array, default: () => [] },
		blockSubmit: { type: Boolean, default: false },
		title: { type: String, default: '' },
		customComponents: { type: Object, default: null },
		smartPaste: { type: Object, default: null },
	},
	emits: ['input', 'submit'],
	data() {
		return { formData: {}, timers: {} }
	},
	methods: {
		answer(key, value) {
			this.formData[key] = value
			this.$emit('input', { key, value })
			if (typeof this.calculate !== 'function') {
				return
			}
			for (const field of this.fields) {
				const inputs = field.calculate && Array.isArray(field.calculate.inputs) ? field.calculate.inputs : []
				if (!inputs.includes(key)) {
					continue
				}
				clearTimeout(this.timers[field.key])
				this.timers[field.key] = setTimeout(async () => {
					const value = await this.calculate(field.key, { ...this.formData })
					this.formData[field.key] = value
					this.$emit('input', { key: field.key, value })
				}, 400)
			}
		},
	},
	render() {
		return h('form', { class: 'form-stand-in' }, [
			h('h2', this.title),
			...this.fields.map((field) => h('output', { 'data-key': field.key }, String(this.formData[field.key] ?? ''))),
			h('ul', { class: 'unmet' }, this.unmetConditions.map((c) => h('li', c.message))),
			h('button', {
				type: 'submit',
				disabled: this.blockSubmit && this.unmetConditions.length > 0,
			}, 'Submit'),
			this.$slots.footer ? this.$slots.footer() : null,
		])
	},
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnFormPage: CnFormPageStandIn }))

const { default: BuildiqFormPage } = await import('../../../src/components/runtime/BuildiqFormPage.vue')

const FEE_FIELDS = [
	{ key: 'attendees', type: 'number', label: 'Attendees' },
	{
		key: 'fee',
		type: 'number',
		label: 'Fee',
		calculate: { ruleSet: 'event-fee', output: 'fee', inputs: ['attendees'] },
	},
]

const LOAN_CHECK = {
	ruleSet: 'loan-eligibility',
	passWhen: { output: 'decision', equals: 'approved' },
	explainWith: 'reason',
	blockSubmit: true,
}

describe('BuildiqFormPage', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		evaluatePreview.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('recalculates the fee after the debounce when the size changes', async () => {
		evaluatePreview.mockImplementation((slug, payload) =>
			Promise.resolve({ fee: payload.attendees > 100 ? 250 : 40 }),
		)
		const wrapper = mount(BuildiqFormPage, { props: { fields: FEE_FIELDS } })
		const form = wrapper.findComponent(CnFormPageStandIn)

		form.vm.answer('attendees', 250)
		await vi.advanceTimersByTimeAsync(399)
		expect(evaluatePreview).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()
		expect(evaluatePreview).toHaveBeenCalledTimes(1)
		expect(evaluatePreview).toHaveBeenCalledWith('event-fee', { attendees: 250 })
		expect(wrapper.find('output[data-key="fee"]').text()).toBe('250')

		form.vm.answer('attendees', 40)
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()
		expect(evaluatePreview).toHaveBeenLastCalledWith('event-fee', { attendees: 40 })
		expect(wrapper.find('output[data-key="fee"]').text()).toBe('40')
	})

	it('sends a calculation only the answers it reads', async () => {
		evaluatePreview.mockResolvedValue({ fee: 10 })
		const fields = [...FEE_FIELDS, { key: 'name', type: 'string' }]
		const wrapper = mount(BuildiqFormPage, { props: { fields } })
		const form = wrapper.findComponent(CnFormPageStandIn)

		await expect(form.props('calculate')('fee', { attendees: 3, name: 'Ann' })).resolves.toBe(10)
		expect(evaluatePreview).toHaveBeenCalledWith('event-fee', { attendees: 3 })
	})

	it('lets a failed calculation reject so the form says it could not calculate', async () => {
		evaluatePreview.mockRejectedValue(new Error('rate limited'))
		const wrapper = mount(BuildiqFormPage, { props: { fields: FEE_FIELDS } })
		const calculate = wrapper.findComponent(CnFormPageStandIn).props('calculate')

		await expect(calculate('fee', { attendees: 3 })).rejects.toThrow('rate limited')
		await expect(calculate('attendees', { attendees: 3 })).rejects.toThrow('not calculated')
	})

	it('lists the explanation of a failing check and disables submit', async () => {
		evaluatePreview.mockResolvedValue({ decision: 'rejected', reason: 'Eligibility criteria not met' })
		const wrapper = mount(BuildiqFormPage, {
			props: { fields: [{ key: 'income', type: 'number' }], eligibility: LOAN_CHECK },
		})
		const form = wrapper.findComponent(CnFormPageStandIn)
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()

		form.vm.answer('income', 900)
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		expect(evaluatePreview).toHaveBeenCalledWith('loan-eligibility', { income: 900 })
		expect(form.props('unmetConditions')).toEqual([{ message: 'Eligibility criteria not met' }])
		expect(form.props('blockSubmit')).toBe(true)
		expect(wrapper.find('.unmet').text()).toBe('Eligibility criteria not met')
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()

		evaluatePreview.mockResolvedValue({ decision: 'approved', reason: '' })
		form.vm.answer('income', 5000)
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		expect(form.props('unmetConditions')).toEqual([])
		expect(form.props('blockSubmit')).toBe(false)
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
	})

	it('shows a failing check without holding submit when it does not block', async () => {
		evaluatePreview.mockResolvedValue({ decision: 'rejected', reason: 'Too young' })
		const wrapper = mount(BuildiqFormPage, {
			props: { fields: [{ key: 'age', type: 'number' }], eligibility: { ...LOAN_CHECK, blockSubmit: false } },
		})
		const form = wrapper.findComponent(CnFormPageStandIn)

		form.vm.answer('age', 12)
		await vi.advanceTimersByTimeAsync(400)
		await flushPromises()

		expect(form.props('unmetConditions')).toEqual([{ message: 'Too young' }])
		expect(form.props('blockSubmit')).toBe(false)
	})

	it('renders a form without bindings exactly as CnFormPage', async () => {
		const onInput = vi.fn()
		const onSubmit = vi.fn()
		const fields = [{ key: 'name', type: 'string' }]
		const wrapper = mount(BuildiqFormPage, {
			props: { fields, title: 'Contact', onInput, onSubmit },
			slots: { footer: '<p class="foot">Thanks</p>' },
		})
		const form = wrapper.findComponent(CnFormPageStandIn)

		expect(form.props('fields')).toEqual(fields)
		expect(form.props('title')).toBe('Contact')
		expect(form.props('calculate')).toBeNull()
		expect(form.props('unmetConditions')).toEqual([])
		expect(form.props('blockSubmit')).toBe(false)
		expect(wrapper.find('.foot').text()).toBe('Thanks')

		form.vm.answer('name', 'Ann')
		form.vm.$emit('submit', { name: 'Ann' })
		await vi.advanceTimersByTimeAsync(1000)
		await flushPromises()

		expect(onInput).toHaveBeenCalledWith({ key: 'name', value: 'Ann' })
		expect(onSubmit).toHaveBeenCalledWith({ name: 'Ann' })
		expect(evaluatePreview).not.toHaveBeenCalled()
	})

	describe('paste to fill (ai-smart-paste-into-forms REQ-BQSP-003)', () => {
		it('registers buildiq\'s smart-paste handler beside what the form would have resolved', () => {
			const inherited = { 'my-handler': () => ({}) }
			const wrapper = mount(BuildiqFormPage, {
				props: { fields: [] },
				global: { provide: { cnCustomComponents: inherited } },
			})
			const components = wrapper.findComponent({ name: 'CnFormPage' }).props('customComponents')
			expect(components['my-handler']).toBe(inherited['my-handler'])
			expect(typeof components['buildiq-smart-paste'].fill).toBe('function')
			expect(typeof components['buildiq-smart-paste'].available).toBe('function')
		})

		it('names buildiq\'s handler on a setting saved without one, and keeps one that names its own', () => {
			const without = mount(BuildiqFormPage, { props: { fields: [] }, attrs: { smartPaste: { enabled: true, fields: ['naam'] } } })
			expect(without.findComponent({ name: 'CnFormPage' }).props('smartPaste')).toEqual({ enabled: true, fields: ['naam'], handler: 'buildiq-smart-paste' })

			const own = mount(BuildiqFormPage, { props: { fields: [] }, attrs: { smartPaste: { enabled: true, fields: ['naam'], handler: 'other' } } })
			expect(own.findComponent({ name: 'CnFormPage' }).props('smartPaste').handler).toBe('other')

			const none = mount(BuildiqFormPage, { props: { fields: [] } })
			expect(none.findComponent({ name: 'CnFormPage' }).props('smartPaste')).toBeNull()
		})
	})
})

