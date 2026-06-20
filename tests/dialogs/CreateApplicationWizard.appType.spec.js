// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vitest unit tests for the unify-apps-with-app-type behaviour added to
 * src/dialogs/CreateApplicationWizard.vue.
 *
 * Covers spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model:
 *   - setAppType('hybrid') sets payload.appType and clears payload.preset
 *   - hybrid: isHybrid true, visibleStepCount 2, goNext 1→4, allStepsValid
 *     needs only _step1Valid
 *   - virtual (default): isHybrid false, visibleStepCount 3, goNext 1→2
 */

import { describe, it, expect } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import CreateApplicationWizard from '../../src/dialogs/CreateApplicationWizard.vue'

const t = (app, str) => str

/**
 * Mount helper — shallowMount avoids deep-rendering NcModal/NcButton/steps.
 *
 * @param {object} propsData Extra props (merged over `show: false`).
 * @return {import('@vue/test-utils').Wrapper} The mounted wrapper.
 */
function mountWizard(propsData = {}) {
	return shallowMount(CreateApplicationWizard, {
		propsData: { show: false, ...propsData },
		mocks: { t },
	})
}

describe('CreateApplicationWizard — app type (unify-apps-with-app-type)', () => {

	// --- defaults ----------------------------------------------------------

	it('defaults to a virtual app', () => {
		const w = mountWizard()
		expect(w.vm.payload.appType).toBe('virtual')
		expect(w.vm.isHybrid).toBe(false)
	})

	// --- setAppType --------------------------------------------------------

	it('setAppType("hybrid") sets payload.appType and clears the preset', () => {
		const w = mountWizard()
		w.vm.mergePayload({ preset: 'dev-prod' })
		w.vm.setAppType('hybrid')
		expect(w.vm.payload.appType).toBe('hybrid')
		expect(w.vm.payload.preset).toBe('')
		expect(w.vm.isHybrid).toBe(true)
	})

	it('setAppType("virtual") keeps the existing preset', () => {
		const w = mountWizard()
		w.vm.mergePayload({ appType: 'hybrid', preset: 'custom' })
		w.vm.setAppType('virtual')
		expect(w.vm.payload.appType).toBe('virtual')
		expect(w.vm.payload.preset).toBe('custom')
	})

	// --- hybrid flow -------------------------------------------------------

	it('hybrid: visibleStepCount is 2', async () => {
		const w = mountWizard()
		w.vm.setAppType('hybrid')
		await w.vm.$nextTick()
		expect(w.vm.visibleStepCount).toBe(2)
	})

	it('hybrid: goNext jumps from step 1 to step 4 (skips preset/custom)', async () => {
		const w = mountWizard()
		w.vm.setAppType('hybrid')
		w.vm.mergePayload({ _step1Valid: true })
		await w.vm.$nextTick()
		w.vm.goNext()
		await w.vm.$nextTick()
		expect(w.vm.step).toBe(4)
	})

	it('hybrid: allStepsValid is true when only _step1Valid is true', async () => {
		const w = mountWizard()
		w.vm.setAppType('hybrid')
		w.vm.mergePayload({ _step1Valid: true, _step2Valid: false })
		await w.vm.$nextTick()
		expect(w.vm.allStepsValid).toBe(true)
	})

	it('hybrid: allStepsValid is false when _step1Valid is false', async () => {
		const w = mountWizard()
		w.vm.setAppType('hybrid')
		w.vm.mergePayload({ _step1Valid: false })
		await w.vm.$nextTick()
		expect(w.vm.allStepsValid).toBe(false)
	})

	it('hybrid: displayStep maps step 4 to 2 and step 1 to 1', async () => {
		const w = mountWizard()
		w.vm.setAppType('hybrid')
		await w.vm.$nextTick()
		expect(w.vm.displayStep).toBe(1)
		w.vm.step = 4
		await w.vm.$nextTick()
		expect(w.vm.displayStep).toBe(2)
	})

	// --- virtual flow (default) -------------------------------------------

	it('virtual: visibleStepCount is 3 for a non-custom preset', async () => {
		const w = mountWizard()
		// default appType virtual, no preset → non-custom
		expect(w.vm.isHybrid).toBe(false)
		expect(w.vm.visibleStepCount).toBe(3)
	})

	it('virtual: goNext advances from step 1 to step 2', async () => {
		const w = mountWizard()
		w.vm.mergePayload({ _step1Valid: true })
		await w.vm.$nextTick()
		w.vm.goNext()
		await w.vm.$nextTick()
		expect(w.vm.step).toBe(2)
	})

	it('virtual: allStepsValid needs both step 1 and step 2 valid', async () => {
		const w = mountWizard()
		w.vm.mergePayload({ preset: 'single', _step1Valid: true, _step2Valid: false })
		await w.vm.$nextTick()
		expect(w.vm.allStepsValid).toBe(false)
		w.vm.mergePayload({ _step2Valid: true })
		await w.vm.$nextTick()
		expect(w.vm.allStepsValid).toBe(true)
	})
})
