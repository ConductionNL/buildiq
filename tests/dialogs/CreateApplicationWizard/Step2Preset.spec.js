/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard/Step2Preset.vue.
 *
 * Covers spec openbuilt-app-creation-wizard task 6.3:
 *   - renders 4 preset cards
 *   - clicking single emits preset + 1-item versions array
 *   - clicking dev-prod emits preset + 2-item versions array
 *   - clicking dev-staging-prod emits preset + 3-item versions array
 *   - clicking custom emits preset:custom without overwriting existing versions
 *   - clicking custom with empty versions seeds a Production default row
 *   - selected card gets --selected class
 *   - isValid false until a preset is selected; true after
 *   - _step2Valid is emitted via watcher when isValid transitions
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Step2Preset from '../../../src/dialogs/CreateApplicationWizard/Step2Preset.vue'

/**
 * Build a default payload.
 *
 * @param {object} overrides
 * @return {object}
 */
function makePayload(overrides = {}) {
	return {
		preset: '',
		versions: [],
		...overrides,
	}
}

/**
 * Mount helper.
 *
 * @param {object} payloadOverrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountStep2(payloadOverrides = {}) {
	return mount(Step2Preset, {
		propsData: { payload: makePayload(payloadOverrides) },
	})
}

describe('Step2Preset.vue — spec task 6.3', () => {

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	it('renders 4 preset cards', () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		expect(cards.length).toBe(4)
	})

	it('no card is selected when preset is empty', () => {
		const wrapper = mountStep2()
		expect(wrapper.find('.wizard-step2__preset-card--selected').exists()).toBe(false)
	})

	// -------------------------------------------------------------------------
	// Preset selection — canned presets
	// -------------------------------------------------------------------------

	it('clicking single emits preset:single and 1-item versions', async () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		// single is the first card
		await cards.at(0).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		expect(emitted).toBeTruthy()
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.preset).toBe('single')
		expect(lastEmit.versions).toHaveLength(1)
		expect(lastEmit.versions[0].slug).toBe('production')
	})

	it('clicking dev-prod emits preset:dev-prod and 2-item versions', async () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(1).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.preset).toBe('dev-prod')
		expect(lastEmit.versions).toHaveLength(2)
		expect(lastEmit.versions[0].slug).toBe('development')
		expect(lastEmit.versions[1].slug).toBe('production')
	})

	it('clicking dev-staging-prod emits preset:dev-staging-prod and 3-item versions', async () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(2).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.preset).toBe('dev-staging-prod')
		expect(lastEmit.versions).toHaveLength(3)
		expect(lastEmit.versions[0].slug).toBe('development')
		expect(lastEmit.versions[1].slug).toBe('staging')
		expect(lastEmit.versions[2].slug).toBe('production')
	})

	// -------------------------------------------------------------------------
	// Custom preset
	// -------------------------------------------------------------------------

	it('clicking custom emits preset:custom', async () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(3).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.preset).toBe('custom')
	})

	it('clicking custom with empty versions seeds a Production default row', async () => {
		const wrapper = mountStep2({ versions: [] })
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(3).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.versions).toHaveLength(1)
		expect(lastEmit.versions[0].slug).toBe('production')
	})

	it('clicking custom with existing versions does not overwrite them', async () => {
		const existing = [
			{ name: 'Dev', slug: 'dev' },
			{ name: 'Prod', slug: 'prod' },
		]
		const wrapper = mountStep2({ versions: existing })
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(3).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		// No versions key in emit → existing not overwritten
		expect(lastEmit.versions).toBeUndefined()
	})

	// -------------------------------------------------------------------------
	// Selected state
	// -------------------------------------------------------------------------

	it('selected card gets --selected class', async () => {
		const wrapper = mountStep2({ preset: 'single' })
		// The first card (single) should have --selected
		await wrapper.vm.$nextTick()
		const selectedCards = wrapper.findAll('.wizard-step2__preset-card--selected')
		expect(selectedCards.length).toBe(1)
	})

	it('aria-pressed is true on the selected card', async () => {
		const wrapper = mountStep2({ preset: 'dev-prod' })
		await wrapper.vm.$nextTick()
		const allCards = wrapper.findAll('.wizard-step2__preset-card')
		const pressedCards = allCards.wrappers.filter(c => c.attributes('aria-pressed') === 'true')
		expect(pressedCards.length).toBe(1)
	})

	// -------------------------------------------------------------------------
	// isValid / _step2Valid emission
	// -------------------------------------------------------------------------

	it('isValid is false when no preset selected', () => {
		const wrapper = mountStep2()
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is true when preset is non-empty', () => {
		const wrapper = mountStep2({ preset: 'single' })
		expect(wrapper.vm.isValid).toBe(true)
	})

	it('emits _step2Valid:true when preset selected', async () => {
		const wrapper = mountStep2()
		await wrapper.setProps({ payload: makePayload({ preset: 'single' }) })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload') || []
		const validEmit = emitted.find(e => '_step2Valid' in e[0])
		expect(validEmit).toBeTruthy()
		expect(validEmit[0]._step2Valid).toBe(true)
	})

	it('emits _step2Valid:false when preset cleared', async () => {
		const wrapper = mountStep2({ preset: 'single' })
		await wrapper.setProps({ payload: makePayload({ preset: '' }) })
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload') || []
		const validEmit = emitted.find(e => e[0]._step2Valid === false)
		expect(validEmit).toBeTruthy()
	})

	// -------------------------------------------------------------------------
	// Version copies are independent (not the same reference)
	// -------------------------------------------------------------------------

	it('selectPreset returns independent version copies (not original array refs)', async () => {
		const wrapper = mountStep2()
		const cards = wrapper.findAll('.wizard-step2__preset-card')
		await cards.at(1).trigger('click')

		const emitted = wrapper.emitted('update:payload')
		const versions = emitted[emitted.length - 1][0].versions
		// Mutating should not affect a future selectPreset call
		versions[0].slug = 'MUTATED'

		await cards.at(1).trigger('click')
		const emitted2 = wrapper.emitted('update:payload')
		const versions2 = emitted2[emitted2.length - 1][0].versions
		expect(versions2[0].slug).toBe('development')
	})
})
