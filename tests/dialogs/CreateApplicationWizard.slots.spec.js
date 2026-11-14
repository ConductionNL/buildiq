/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard.vue: the step
 * slots it hands to CnWizardDialog wire each step's payload back to the
 * wizard, a copilot-created app closes the wizard and opens its designer,
 * and an icon is written as a file and then referenced on the app.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), patch: vi.fn() } }))
vi.mock('../../src/utils/iconCatalogues.js', () => ({ resolveAppIcon: vi.fn(() => '') }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (url) => url,
}))

const setStepData = vi.fn()

const CnWizardDialogStub = {
	name: 'CnWizardDialog',
	props: ['steps', 'defaults', 'validate'],
	methods: {
		setError() {},
		setStepData(...args) {
			setStepData(...args)
		},
	},
	template: `<div class="wizard-stub">
		<slot name="step-basics" :stepData="{ name: 'B' }" :setStepData="setStepData" />
		<slot name="step-preset" :stepData="{}" :setStepData="setStepData" />
		<slot name="step-custom" :stepData="{}" :setStepData="setStepData" />
		<slot name="step-review" :stepData="{ name: 'R' }" />
	</div>`,
}

function step(name) {
	return {
		name,
		props: ['payload'],
		template: '<div :class="$options.name" />',
	}
}

function mountWizard(mocks = {}) {
	return mount(CreateApplicationWizard, {
		propsData: { show: true },
		stubs: {
			CnWizardDialog: CnWizardDialogStub,
			Step1Basics: step('Step1Basics'),
			Step2Preset: step('Step2Preset'),
			Step3Custom: step('Step3Custom'),
			Step4Review: step('Step4Review'),
		},
		mocks,
	})
}

describe('CreateApplicationWizard step slots', () => {
	beforeEach(() => {
		setStepData.mockReset()
		axios.post.mockReset().mockResolvedValue({ data: {} })
		axios.patch.mockReset().mockResolvedValue({ data: {} })
	})

	it('hands every step its payload and writes its updates back', async () => {
		const wrapper = mountWizard()

		expect(wrapper.findComponent({ name: 'Step1Basics' }).props('payload')).toEqual({ name: 'B' })
		expect(wrapper.findComponent({ name: 'Step4Review' }).props('payload')).toEqual({ name: 'R' })

		wrapper.findComponent({ name: 'Step1Basics' }).vm.$emit('update:payload', { name: 'Permits' })
		wrapper.findComponent({ name: 'Step3Custom' }).vm.$emit('update:payload', { pages: ['a'] })
		wrapper.findComponent({ name: 'Step2Preset' }).vm.$emit('update:payload', { preset: 'custom' })
		await wrapper.vm.$nextTick()

		expect(setStepData).toHaveBeenCalledWith({ name: 'Permits' })
		expect(setStepData).toHaveBeenCalledWith({ pages: ['a'] })
		expect(setStepData).toHaveBeenCalledWith({ preset: 'custom' })
		expect(wrapper.vm.presetSelected).toBe('custom')
	})

	it('a copilot-created app closes the wizard and opens its page designer', async () => {
		const push = vi.fn().mockResolvedValue()
		const wrapper = mountWizard({ $router: { push } })

		wrapper.findComponent({ name: 'Step1Basics' }).vm.$emit('aiAppCreated', 'permits')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:show')).toEqual([[false]])
		expect(push).toHaveBeenCalledWith({ name: 'PageDesigner', params: { slug: 'permits' } })
		expect(wrapper.emitted('created')).toEqual([[]])
	})

	it('without a router or a slug it still closes and refreshes', () => {
		const wrapper = mountWizard()

		wrapper.vm.onAiAppCreated('')

		expect(wrapper.emitted('update:show')).toEqual([[false]])
		expect(wrapper.emitted('created')).toEqual([[]])
	})

	it('writes the icon as a file, then points the app field at it', async () => {
		const wrapper = mountWizard()

		await wrapper.vm.attachIcon('app-1', 'icon', 'icon.svg', '<svg/>')

		expect(axios.post).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/built-app/app-1/files',
			{ name: 'icon.svg', content: '<svg/>' },
		)
		expect(axios.patch).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/built-app/app-1',
			{ icon: { ref: 'icon.svg' } },
		)
		expect(axios.post.mock.invocationCallOrder[0]).toBeLessThan(axios.patch.mock.invocationCallOrder[0])
	})
})
