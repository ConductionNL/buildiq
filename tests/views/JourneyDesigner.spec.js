/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * JourneyDesigner against a fake client: it loads the form library and the
 * journey, shows a broken form reference on open, refuses to send a journey
 * the local checks reject, saves through buildiq's journey route, and shows
 * that route's refusal (which names journey authoring) and its findings.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import JourneyDesigner from '../../src/views/JourneyDesigner.vue'

const library = {
	results: [
		{ '@self': { id: 'f-1' }, name: 'Applicant', form: { fields: [{ key: 'name' }, { key: 'age' }] } },
		{ '@self': { id: 'f-2' }, name: 'Contact', form: { fields: [{ key: 'email' }] } },
		{ '@self': { id: 'f-3' }, name: 'No form body' },
	],
}
const stored = {
	'@self': { id: 'j-1' },
	title: 'Register',
	steps: [
		{ id: 'a', type: 'form', title: 'Applicant', formId: 'f-1' },
		{ id: 'b', type: 'form', title: 'Contact', formId: 'f-2', writes: [{ as: 'org', register: 'crm', schema: 'org', mapping: { fields: [] } }] },
		{ id: 'c', type: 'review', title: 'Check' },
	],
}

function client({ journey = stored, post } = {}) {
	return {
		get: vi.fn((url) => Promise.resolve({ data: url.includes('form-template') ? library : journey })),
		post: post || vi.fn(() => Promise.resolve({ data: { id: 'j-1' } })),
	}
}

async function mountWith(fake, journeyId = 'j-1') {
	const wrapper = mount(JourneyDesigner, {
		props: { journeyId, client: fake },
		global: { stubs: { JourneyPreviewPane: { name: 'JourneyPreviewPane', props: ['journey', 'forms'], template: '<div class="preview" />' } } },
	})
	await flushPromises()
	return wrapper
}

describe('JourneyDesigner', () => {
	it('loads the library forms and the journey, and selects the first step', async () => {
		const fake = client()
		const wrapper = await mountWith(fake)

		expect(fake.get).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/openregister\/api\/objects\/buildiq\/form-template$/), { params: { _limit: 200 } })
		expect(fake.get).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/openregister\/api\/objects\/buildiq\/journey\/j-1$/))
		expect(Object.keys(wrapper.vm.forms)).toEqual(['f-1', 'f-2'])
		expect(wrapper.vm.formOptions).toEqual([{ id: 'f-1', label: 'Applicant' }, { id: 'f-2', label: 'Contact' }])
		expect(wrapper.vm.journey.id).toBe('j-1')
		expect(wrapper.vm.selectedId).toBe('a')
		expect(wrapper.find('[data-testid="journey-title"]').element.value).toBe('Register')
		expect(wrapper.findComponent({ name: 'JourneyPreviewPane' }).props('journey')).toEqual(wrapper.vm.journey)
	})

	it('starts empty for a new journey without asking for one', async () => {
		const fake = client()
		const wrapper = await mountWith(fake, 'new')

		expect(fake.get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.journey.steps).toEqual([])
		expect(wrapper.vm.selectedId).toBe('')
	})

	it('shows a reference to a deleted form as soon as the journey opens', async () => {
		const journey = { ...stored, steps: [{ id: 'a', type: 'form', title: 'Gone', formId: 'f-9' }] }
		const wrapper = await mountWith(client({ journey }))

		expect(wrapper.vm.localFindings).toEqual([{ step: 'a', code: 'step-form-gone', form: 'f-9' }])
		expect(wrapper.find('[data-testid="journey-findings"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="journey-step-broken"]').exists()).toBe(true)
	})

	it('offers a step only the fields asked by then, and earlier steps\' writes', async () => {
		const wrapper = await mountWith(client())

		expect(wrapper.vm.fieldsHere).toEqual(['name', 'age'])
		wrapper.vm.selectedId = 'c'
		expect(wrapper.vm.fieldsHere).toEqual(['name', 'age', 'email'])
		expect(wrapper.vm.earlierWrites).toEqual(['org'])
		expect(wrapper.vm.branchTargets.map((t) => t.id)).toEqual(['a', 'b'])
	})

	it('does not send a journey the local checks refuse', async () => {
		const fake = client()
		const wrapper = await mountWith(fake)
		wrapper.vm.patchSelected({ branch: [{ when: { field: 'email', op: 'eq', value: 'x' }, goto: 'c' }] })

		await wrapper.find('[data-testid="journey-save"]').trigger('click')
		await flushPromises()

		expect(fake.post).not.toHaveBeenCalled()
		expect(wrapper.vm.localFindings).toEqual([{ step: 'a', code: 'branch-field-later', field: 'email', askedAt: 'b' }])
		expect(wrapper.find('[data-testid="journey-findings"]').text()).toContain('first asked later')
	})

	it('saves through the journey route and keeps the id it gets', async () => {
		const post = vi.fn(() => Promise.resolve({ data: { id: 'j-new' } }))
		const wrapper = await mountWith(client({ post }), 'new')
		await wrapper.find('[data-testid="journey-title"]').setValue('Fresh')
		wrapper.vm.setSteps([{ id: 'r', type: 'review', title: 'Check' }])

		await wrapper.find('[data-testid="journey-save"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/buildiq\/api\/journeys$/), { journey: { id: '', title: 'Fresh', description: '', steps: [{ id: 'r', type: 'review', title: 'Check' }] } })
		expect(wrapper.vm.journey.id).toBe('j-new')
		expect(wrapper.find('[data-testid="journey-saved"]').exists()).toBe(true)
		expect(wrapper.vm.saving).toBe(false)
	})

	it('shows the refusal of a caller who may not author journeys', async () => {
		const message = 'Authoring a journey takes an administrator, because a journey can write into any register its mappings name.'
		const post = vi.fn(() => Promise.reject({ response: { status: 403, data: { error: 'forbidden', message } } }))
		const wrapper = await mountWith(client({ post }))

		await wrapper.find('[data-testid="journey-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="journey-refusal"]').text()).toBe(message)
		expect(wrapper.find('[data-testid="journey-saved"]').exists()).toBe(false)
	})

	it('shows the server findings on the writes, on their step', async () => {
		const findings = [{ step: 'b', write: 'org', property: 'nope', code: 'property-unknown', message: 'nope is not on org.' }]
		const post = vi.fn(() => Promise.reject({ response: { status: 422, data: { error: 'journey_refused', findings } } }))
		const wrapper = await mountWith(client({ post }))

		await wrapper.find('[data-testid="journey-save"]').trigger('click')
		await flushPromises()

		expect(wrapper.vm.serverFindings).toEqual(findings)
		expect(wrapper.find('[data-testid="journey-refusal"]').exists()).toBe(false)
		wrapper.vm.selectedId = 'b'
		await flushPromises()
		expect(wrapper.find('[data-testid="journey-write-finding"]').text()).toBe('nope is not on org.')
	})

	it('says the journey could not be saved on a network failure, and could not be loaded on a failed load', async () => {
		const post = vi.fn(() => Promise.reject(new Error('offline')))
		const wrapper = await mountWith(client({ post }))

		await wrapper.find('[data-testid="journey-save"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="journey-refusal"]').text()).toBe('The journey could not be saved.')

		const broken = await mountWith({ get: vi.fn(() => Promise.reject(new Error('down'))), post: vi.fn() })
		expect(broken.find('[data-testid="journey-load-error"]').text()).toBe('The journey could not be loaded.')
		expect(broken.vm.loading).toBe(false)
	})

	it('writes a sentence for every local finding code', async () => {
		const wrapper = await mountWith(client())
		const codes = ['step-id-missing', 'step-id-duplicate', 'step-type-unknown', 'step-copies-fields', 'step-form-missing', 'step-form-gone', 'branch-target-unknown', 'branch-operator-unknown', 'branch-field-unknown', 'branch-field-later', 'write-unknown', 'write-as-duplicate']

		for (const code of codes) {
			expect(wrapper.vm.findingText({ step: 'a', code })).not.toBe('Step {step}: {code}')
		}
		expect(wrapper.vm.findingText({ step: 'a', code: 'other' })).toBe('Step {step}: {code}')
	})
})
