/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard.vue.
 *
 * The wizard now delegates its chrome (step indicator, Back/Next/Create
 * footer, navigation) to the shared @conduction/nextcloud-vue CnWizardDialog,
 * and creates VIRTUAL apps only (no app-type choice — hybrid apps come from
 * the App Store). These tests cover the seam the component still owns:
 *   - it mounts CnWizardDialog only while `show`
 *   - wizardSteps inserts the Custom step only for the custom preset
 *   - onPresetUpdate forwards data + tracks the preset for the steps list
 *   - validateStep maps the per-step validity flags to true / error string
 *   - onSubmit posts, emits created + closes on success, calls setError on failure
 *   - onClose emits update:show and resets state
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

import axios from '@nextcloud/axios'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (url) => url,
}))

const CnWizardDialogStub = {
	name: 'CnWizardDialog',
	props: [
		'steps',
		'defaults',
		'validate',
		'dialogTitle',
		'cancelLabel',
		'backLabel',
		'nextLabel',
		'submitLabel',
		'closeLabel',
		'successText',
	],
	methods: { setError() {} },
	template: '<div class="wizard-stub" />',
}

function mountWizard(propsData = {}) {
	return mount(CreateApplicationWizard, {
		propsData: { show: true, ...propsData },
		stubs: {
			CnWizardDialog: CnWizardDialogStub,
			Step1Basics: true,
			Step2Preset: true,
			Step3Custom: true,
			Step4Review: true,
		},
	})
}

describe('CreateApplicationWizard', () => {
	beforeEach(() => {
		axios.post.mockReset()
	})

	it('mounts CnWizardDialog only while show is true', () => {
		const hidden = mountWizard({ show: false })
		expect(hidden.findComponent({ name: 'CnWizardDialog' }).exists()).toBe(false)

		const shown = mountWizard({ show: true })
		expect(shown.findComponent({ name: 'CnWizardDialog' }).exists()).toBe(true)
	})

	it('lists basics → preset → review by default (no Custom step)', () => {
		const w = mountWizard()
		expect(w.vm.wizardSteps.map((s) => s.id)).toEqual([
			'basics',
			'preset',
			'review',
		])
	})

	it('inserts the Custom step only for the custom preset', async () => {
		const w = mountWizard()
		w.vm.presetSelected = 'custom'
		await w.vm.$nextTick()
		expect(w.vm.wizardSteps.map((s) => s.id)).toEqual([
			'basics',
			'preset',
			'custom',
			'review',
		])
	})

	it('onPresetUpdate forwards the partial and tracks the preset', () => {
		const w = mountWizard()
		const setStepData = vi.fn()
		w.vm.onPresetUpdate({ preset: 'custom', _step2Valid: true }, setStepData)
		expect(setStepData).toHaveBeenCalledWith({
			preset: 'custom',
			_step2Valid: true,
		})
		expect(w.vm.presetSelected).toBe('custom')

		// A partial without a preset key must not clear the tracked preset.
		w.vm.onPresetUpdate({ _step2Valid: false }, setStepData)
		expect(w.vm.presetSelected).toBe('custom')
	})

	it('validateStep gates on the per-step validity flags', () => {
		const w = mountWizard()
		expect(typeof w.vm.validateStep('basics', { _step1Valid: false })).toBe(
			'string',
		)
		expect(w.vm.validateStep('basics', { _step1Valid: true })).toBe(true)
		expect(typeof w.vm.validateStep('preset', { _step2Valid: false })).toBe(
			'string',
		)
		expect(w.vm.validateStep('preset', { _step2Valid: true })).toBe(true)
		expect(typeof w.vm.validateStep('custom', { _step3Valid: false })).toBe(
			'string',
		)
		expect(w.vm.validateStep('custom', { _step3Valid: true })).toBe(true)
		expect(w.vm.validateStep('review', {})).toBe(true)
	})

	it('onSubmit posts and emits created + closes on success', async () => {
		axios.post.mockResolvedValue({
			status: 201,
			data: { applicationUuid: 'uuid-1' },
		})
		const w = mountWizard()
		await w.vm.onSubmit({
			name: 'X',
			slug: 'x',
			description: '',
			preset: 'single',
			versions: [],
		})

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openbuild/api/applications/wizard',
			{
				name: 'X',
				slug: 'x',
				description: '',
				preset: 'single',
				versions: [],
			},
		)
		expect(w.emitted('created')).toEqual([['uuid-1']])
		expect(w.emitted('update:show')).toEqual([[false]])
	})

	it('onSubmit surfaces a recoverable error via setError on failure', async () => {
		axios.post.mockResolvedValue({
			status: 200,
			data: { message: 'Slug taken' },
		})
		const w = mountWizard()
		const setError = vi.fn()
		w.vm.$refs.wizard.setError = setError

		await w.vm.onSubmit({
			name: 'X',
			slug: 'x',
			description: '',
			preset: 'single',
			versions: [],
		})

		expect(setError).toHaveBeenCalledTimes(1)
		expect(setError.mock.calls[0][0]).toContain('Slug taken')
		expect(w.emitted('created')).toBeFalsy()
	})

	it('onSubmit handles a rejected request via setError', async () => {
		axios.post.mockRejectedValue({ response: { data: { message: 'Boom' } } })
		const w = mountWizard()
		const setError = vi.fn()
		w.vm.$refs.wizard.setError = setError

		await w.vm.onSubmit({
			name: 'X',
			slug: 'x',
			description: '',
			preset: 'single',
			versions: [],
		})
		expect(setError).toHaveBeenCalledTimes(1)
		expect(setError.mock.calls[0][0]).toContain('Boom')
	})

	it('onClose emits update:show false and resets the tracked preset', () => {
		const w = mountWizard()
		w.vm.presetSelected = 'custom'
		w.vm.onClose()
		expect(w.emitted('update:show')).toEqual([[false]])
		expect(w.vm.presetSelected).toBe('')
	})
})
