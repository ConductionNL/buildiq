/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard.vue (shell).
 *
 * Covers spec openbuilt-app-creation-wizard task 6.1:
 *   - shell renders step 1 on open
 *   - Next is disabled until step 1 is valid
 *   - navigation: 1→2, 2→4 (non-custom skips 3), 4→Back→2 (non-custom)
 *   - navigation: 1→2→3→4 for custom preset
 *   - displayStep / visibleStepCount for non-custom (3 visible steps)
 *   - displayStep / visibleStepCount for custom (4 visible steps)
 *   - Create button disabled until allStepsValid
 *   - onClose emits update:show false and resets state
 *   - error banner hidden by default, shown after errorMessage set
 *   - orphanedResources section hidden when empty
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

// ---------------------------------------------------------------------------
// Stubs
// ---------------------------------------------------------------------------

const NcModalStub = {
	name: 'NcModal',
	props: ['show', 'name', 'canClose'],
	template: `
		<div v-if="show" class="nc-modal-stub">
			<slot />
			<div class="nc-modal-actions"><slot name="actions" /></div>
		</div>
	`,
	emits: ['close', 'update:show'],
}

const NcButtonStub = {
	name: 'NcButton',
	props: ['type', 'disabled'],
	template: '<button :disabled="disabled || false" :data-type="type" @click="$emit(\'click\', $event)"><slot /><slot name="icon" /></button>',
}

// Lightweight step stubs — each renders a named div so tests can assert which step is active.
const Step1Stub = {
	name: 'Step1Basics',
	props: ['payload'],
	template: '<div class="step1-stub" />',
	emits: ['update:payload'],
}
const Step2Stub = {
	name: 'Step2Preset',
	props: ['payload'],
	template: '<div class="step2-stub" />',
	emits: ['update:payload'],
}
const Step3Stub = {
	name: 'Step3Custom',
	props: ['payload'],
	template: '<div class="step3-stub" />',
	emits: ['update:payload'],
}
const Step4Stub = {
	name: 'Step4Review',
	props: ['payload'],
	template: '<div class="step4-stub" />',
}

const baseStubs = {
	NcModal: NcModalStub,
	NcButton: NcButtonStub,
	Step1Basics: Step1Stub,
	Step2Preset: Step2Stub,
	Step3Custom: Step3Stub,
	Step4Review: Step4Stub,
}

/**
 * Mount helper.
 *
 * @param {object} propsData
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountWizard(propsData = {}) {
	return mount(CreateApplicationWizard, {
		propsData: { show: true, ...propsData },
		stubs: baseStubs,
	})
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Find the Next button (data-type="primary" when step < 4). */
function nextBtn(wrapper) {
	return wrapper.findAll('button[data-type="primary"]').wrappers.find(b => b.text().includes('Next'))
}

/** Find the Create button (rendered on step 4). */
function createBtn(wrapper) {
	return wrapper.findAll('button[data-type="primary"]').wrappers.find(b => b.text().includes('Create'))
}

/** Find the Back button. */
function backBtn(wrapper) {
	return wrapper.findAll('button[data-type="tertiary"]').wrappers.find(b => b.text().includes('Back'))
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('CreateApplicationWizard.vue (shell) — spec task 6.1', () => {

	// -------------------------------------------------------------------------
	// Initial render
	// -------------------------------------------------------------------------

	it('renders step 1 on open', () => {
		const wrapper = mountWizard()
		expect(wrapper.find('.step1-stub').exists()).toBe(true)
		expect(wrapper.find('.step2-stub').exists()).toBe(false)
	})

	it('does not render if show is false', () => {
		const wrapper = mountWizard({ show: false })
		// NcModal stub hides itself when show is false
		expect(wrapper.find('.nc-modal-stub').exists()).toBe(false)
	})

	// -------------------------------------------------------------------------
	// Step indicator
	// -------------------------------------------------------------------------

	it('shows 3 step dots for non-custom preset', async () => {
		const wrapper = mountWizard()
		// Default: no preset selected → isCustomPreset is false → visibleStepCount = 3
		expect(wrapper.vm.visibleStepCount).toBe(3)
	})

	it('shows 4 step dots for custom preset', async () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ preset: 'custom' })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.visibleStepCount).toBe(4)
	})

	it('displayStep returns 3 on step 4 when non-custom', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.payload.preset = 'single'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.displayStep).toBe(3)
	})

	it('displayStep returns 4 on step 4 when custom', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.payload.preset = 'custom'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.displayStep).toBe(4)
	})

	// -------------------------------------------------------------------------
	// currentStepValid gate
	// -------------------------------------------------------------------------

	it('Next button is disabled when step 1 is not valid', () => {
		const wrapper = mountWizard()
		// _step1Valid defaults to false
		const btn = nextBtn(wrapper)
		expect(btn).toBeTruthy()
		expect(btn.attributes('disabled')).toBeTruthy()
	})

	it('Next button is enabled when step 1 becomes valid', async () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ _step1Valid: true })
		await wrapper.vm.$nextTick()
		const btn = nextBtn(wrapper)
		expect(btn.attributes('disabled')).toBeFalsy()
	})

	// -------------------------------------------------------------------------
	// Navigation — non-custom preset
	// -------------------------------------------------------------------------

	it('goNext advances step 1→2', async () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ _step1Valid: true })
		await wrapper.vm.$nextTick()
		wrapper.vm.goNext()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(2)
	})

	it('goNext skips step 3 from step 2 when non-custom preset', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 2
		wrapper.vm.mergePayload({ preset: 'single', _step2Valid: true })
		await wrapper.vm.$nextTick()
		wrapper.vm.goNext()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(4)
	})

	it('Back from step 4 returns to step 2 when non-custom', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.mergePayload({ preset: 'dev-prod' })
		await wrapper.vm.$nextTick()
		wrapper.vm.goBack()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(2)
	})

	// -------------------------------------------------------------------------
	// Navigation — custom preset
	// -------------------------------------------------------------------------

	it('goNext proceeds step 2→3 when custom preset', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 2
		wrapper.vm.mergePayload({ preset: 'custom', _step2Valid: true })
		await wrapper.vm.$nextTick()
		wrapper.vm.goNext()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(3)
	})

	it('goNext proceeds step 3→4 for custom preset', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 3
		wrapper.vm.mergePayload({ preset: 'custom', _step3Valid: true })
		await wrapper.vm.$nextTick()
		wrapper.vm.goNext()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(4)
	})

	it('Back from step 4 returns to step 3 when custom', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.mergePayload({ preset: 'custom' })
		await wrapper.vm.$nextTick()
		wrapper.vm.goBack()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(3)
	})

	// -------------------------------------------------------------------------
	// Back button visibility
	// -------------------------------------------------------------------------

	it('Back button is not rendered on step 1', () => {
		const wrapper = mountWizard()
		expect(backBtn(wrapper)).toBeFalsy()
	})

	it('Back button is rendered on step 2', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 2
		await wrapper.vm.$nextTick()
		expect(backBtn(wrapper)).toBeTruthy()
	})

	// -------------------------------------------------------------------------
	// Create button (step 4)
	// -------------------------------------------------------------------------

	it('Create button is rendered on step 4', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		await wrapper.vm.$nextTick()
		expect(createBtn(wrapper)).toBeTruthy()
	})

	it('Create button is disabled when allStepsValid is false', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		// _step1Valid still false
		await wrapper.vm.$nextTick()
		const btn = createBtn(wrapper)
		expect(btn.attributes('disabled')).toBeTruthy()
	})

	it('Create button is enabled when allStepsValid is true (non-custom)', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.mergePayload({ preset: 'single', _step1Valid: true, _step2Valid: true })
		await wrapper.vm.$nextTick()
		const btn = createBtn(wrapper)
		expect(btn.attributes('disabled')).toBeFalsy()
	})

	it('Create button is disabled when _step3Valid is false for custom preset', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 4
		wrapper.vm.mergePayload({ preset: 'custom', _step1Valid: true, _step2Valid: true, _step3Valid: false })
		await wrapper.vm.$nextTick()
		const btn = createBtn(wrapper)
		expect(btn.attributes('disabled')).toBeTruthy()
	})

	// -------------------------------------------------------------------------
	// allStepsValid computed
	// -------------------------------------------------------------------------

	it('allStepsValid is false by default', () => {
		const wrapper = mountWizard()
		expect(wrapper.vm.allStepsValid).toBe(false)
	})

	it('allStepsValid is true for non-custom with steps 1+2 valid', async () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ preset: 'single', _step1Valid: true, _step2Valid: true })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.allStepsValid).toBe(true)
	})

	it('allStepsValid is true for custom with all three steps valid', async () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ preset: 'custom', _step1Valid: true, _step2Valid: true, _step3Valid: true })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.allStepsValid).toBe(true)
	})

	// -------------------------------------------------------------------------
	// Error banner
	// -------------------------------------------------------------------------

	it('error banner is hidden by default', () => {
		const wrapper = mountWizard()
		expect(wrapper.find('.wizard__error-banner').exists()).toBe(false)
	})

	it('error banner shows when errorMessage is set', async () => {
		const wrapper = mountWizard()
		wrapper.vm.errorMessage = 'Something went wrong'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.wizard__error-banner').exists()).toBe(true)
		expect(wrapper.find('.wizard__error-banner').text()).toContain('Something went wrong')
	})

	it('orphanedResources details hidden when list is empty', async () => {
		const wrapper = mountWizard()
		wrapper.vm.errorMessage = 'Rollback partial'
		wrapper.vm.orphanedResources = []
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.wizard__error-banner details').exists()).toBe(false)
	})

	it('orphanedResources details shown when list is non-empty', async () => {
		const wrapper = mountWizard()
		wrapper.vm.errorMessage = 'Rollback partial'
		wrapper.vm.orphanedResources = ['openbuilt-my-app-development']
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.wizard__error-banner details').exists()).toBe(true)
		expect(wrapper.find('.wizard__error-banner details').text()).toContain('openbuilt-my-app-development')
	})

	// -------------------------------------------------------------------------
	// onClose resets state
	// -------------------------------------------------------------------------

	it('onClose emits update:show false', async () => {
		const wrapper = mountWizard()
		wrapper.vm.onClose()
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:show')
		expect(emitted).toBeTruthy()
		expect(emitted[0][0]).toBe(false)
	})

	it('onClose resets step to 1', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 3
		wrapper.vm.onClose()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.step).toBe(1)
	})

	it('onClose does not close while submitting', async () => {
		const wrapper = mountWizard()
		wrapper.vm.submitting = true
		wrapper.vm.onClose()
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:show')).toBeFalsy()
	})

	// -------------------------------------------------------------------------
	// mergePayload
	// -------------------------------------------------------------------------

	it('mergePayload merges partial into payload', () => {
		const wrapper = mountWizard()
		wrapper.vm.mergePayload({ name: 'Test App', slug: 'test-app' })
		expect(wrapper.vm.payload.name).toBe('Test App')
		expect(wrapper.vm.payload.slug).toBe('test-app')
		// Other keys untouched
		expect(wrapper.vm.payload.preset).toBe('')
	})
})
