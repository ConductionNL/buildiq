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

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
const { resolveAppIconMock } = vi.hoisted(() => ({
	resolveAppIconMock: vi.fn(() => ''),
}))
vi.mock('../../src/utils/iconCatalogues.js', () => ({
	resolveAppIcon: resolveAppIconMock,
}))
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
			'/apps/buildiq/api/applications/wizard',
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
	describe('step, error and icon branches', () => {
		it.each([
			['basics', { _step1Valid: false }, 'Enter a name and a valid slug.'],
			['preset', { _step2Valid: false }, 'Choose a version preset.'],
			['custom', { _step3Valid: false }, 'Complete the custom version chain.'],
			['basics', { _step2Valid: true }, 'Enter a name and a valid slug.'],
			['other', { _step1Valid: false }, true],
		])('validateStep(%j, %j) is %j', (stepId, data, expected) => {
			expect(mountWizard().vm.validateStep(stepId, data)).toBe(expected)
		})

		it.each([
			[{ message: 'M', detail: 'D' }, new Error('E'), 'M'],
			[{ detail: 'D' }, new Error('E'), 'D'],
			[{}, new Error('E'), 'E'],
			[{}, undefined, 'Failed to create the application.'],
			[{ orphanedResources: [] }, undefined, 'Failed to create the application.'],
			[
				{ message: 'M', orphanedResources: ['reg-a', 'schema-b'] },
				undefined,
				'M Some resources need manual cleanup: {list}',
			],
		])('reportError(%j) shows %j', (data, err, message) => {
			const w = mountWizard()
			const setError = vi.fn()
			w.vm.$refs.wizard.setError = setError
			w.vm.reportError(data, err)
			expect(setError).toHaveBeenCalledWith(message)
		})

		it('reportError does nothing without a mounted wizard', () => {
			const w = mountWizard({ show: false })
			expect(() => w.vm.reportError({ message: 'M' })).not.toThrow()
		})

		it('uploadIcons attaches nothing when no icon resolves', async () => {
			const w = mountWizard()
			const attach = vi.spyOn(w.vm, 'attachIcon').mockResolvedValue()
			resolveAppIconMock.mockReturnValue('')
			await w.vm.uploadIcons('uuid-1', { iconValue: 'x' })
			expect(attach).not.toHaveBeenCalled()
		})

		it('uploadIcons attaches the light and the dark icon, dark from the primary by default', async () => {
			const w = mountWizard()
			const attach = vi.spyOn(w.vm, 'attachIcon').mockResolvedValue()
			resolveAppIconMock.mockImplementation((value, { dark }) => `${value}:${dark}`)
			await w.vm.uploadIcons('uuid-1', { iconValue: 'cat' })
			expect(resolveAppIconMock).toHaveBeenCalledWith('cat', { dark: false })
			expect(resolveAppIconMock).toHaveBeenCalledWith('cat', { dark: true })
			expect(attach.mock.calls).toEqual([
				['uuid-1', 'icon', 'app-icon.svg', 'cat:false'],
				['uuid-1', 'iconDark', 'app-icon-dark.svg', 'cat:true'],
			])
		})

		it('uploadIcons uses the dark pick and skips an unresolved light icon', async () => {
			const w = mountWizard()
			const attach = vi.spyOn(w.vm, 'attachIcon').mockResolvedValue()
			resolveAppIconMock.mockImplementation((value, { dark }) =>
				dark ? `${value}:dark` : '',
			)
			await w.vm.uploadIcons('uuid-1', { iconValue: 'cat', iconDarkValue: 'dog' })
			expect(attach.mock.calls).toEqual([
				['uuid-1', 'iconDark', 'app-icon-dark.svg', 'dog:dark'],
			])
		})

		it('uploadIcons logs a failed attach and stops', async () => {
			const w = mountWizard()
			const failure = new Error('down')
			const attach = vi.spyOn(w.vm, 'attachIcon').mockRejectedValue(failure)
			const logged = vi.spyOn(console, 'error').mockImplementation(() => {})
			resolveAppIconMock.mockReturnValue('<svg/>')
			await w.vm.uploadIcons('uuid-1', { iconValue: 'cat' })
			expect(attach).toHaveBeenCalledTimes(1)
			expect(logged).toHaveBeenCalledWith('Buildiq: failed to attach app icon', failure)
			logged.mockRestore()
		})
	})
})
