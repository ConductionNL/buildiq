/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CreateApplicationWizard/Step1Basics.vue.
 *
 * Covers spec openbuilt-app-creation-wizard task 6.2:
 *   - name input auto-derives slug via toKebabCase
 *   - manually overriding slug blocks auto-derivation
 *   - leading-underscore slug rejected (slugError set)
 *   - invalid-char slug rejected
 *   - isValid false when name empty
 *   - isValid false when slug has errors
 *   - isValid true when name non-empty and slug valid
 *   - isValid change emits update:payload with _step1Valid
 *   - Advanced toggle shows/hides slug input
 *   - description input emits update:payload
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Step1Basics from '../../../src/dialogs/CreateApplicationWizard/Step1Basics.vue'

/**
 * Build a default payload.
 *
 * @param {object} overrides
 * @return {object}
 */
function makePayload(overrides = {}) {
	return {
		name: '',
		slug: '',
		description: '',
		icon: null,
		iconDark: null,
		preset: '',
		versions: [],
		_step1Valid: false,
		...overrides,
	}
}

/**
 * Mount helper.
 *
 * @param {object} payloadOverrides
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountStep1(payloadOverrides = {}) {
	return mount(Step1Basics, {
		propsData: { payload: makePayload(payloadOverrides) },
	})
}

describe('Step1Basics.vue — spec task 6.2', () => {

	// -------------------------------------------------------------------------
	// Name → slug auto-derivation
	// -------------------------------------------------------------------------

	it('typing a name auto-derives the slug', async () => {
		const wrapper = mountStep1()
		const nameInput = wrapper.find('#wizard-app-name')
		await nameInput.setValue('My Permit Tracker')
		await wrapper.vm.$nextTick()

		// The component emits update:payload with { name, slug }
		const emitted = wrapper.emitted('update:payload')
		expect(emitted).toBeTruthy()
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.name).toBe('My Permit Tracker')
		expect(lastEmit.slug).toBe('my-permit-tracker')
	})

	it('typing a simple CamelCase name derives lowercase slug', async () => {
		const wrapper = mountStep1()
		const nameInput = wrapper.find('#wizard-app-name')
		await nameInput.setValue('HelloWorld')
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.slug).toBe('helloworld')
	})

	it('typing a name with accents strips them in slug', async () => {
		const wrapper = mountStep1()
		const nameInput = wrapper.find('#wizard-app-name')
		await nameInput.setValue('Évaluation App')
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.slug).toBe('evaluation-app')
	})

	// -------------------------------------------------------------------------
	// Manual slug override
	// -------------------------------------------------------------------------

	it('showing Advanced reveals the slug input', async () => {
		const wrapper = mountStep1()
		// Advanced hidden by default
		expect(wrapper.find('#wizard-app-slug').exists()).toBe(false)

		await wrapper.find('.wizard-step1__advanced-toggle').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('#wizard-app-slug').exists()).toBe(true)
	})

	it('manually editing the slug blocks auto-derivation on next name change', async () => {
		const wrapper = mountStep1({ slug: 'manual-slug' })
		// Simulate user opening Advanced and manually editing the slug
		wrapper.vm.slugManuallyEdited = true

		const nameInput = wrapper.find('#wizard-app-name')
		await nameInput.setValue('New Name')
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		// Slug should NOT be overwritten
		expect(lastEmit.slug).toBeUndefined()
	})

	it('editing the slug field sets slugManuallyEdited and emits', async () => {
		const wrapper = mountStep1()
		await wrapper.find('.wizard-step1__advanced-toggle').trigger('click')
		await wrapper.vm.$nextTick()

		const slugInput = wrapper.find('#wizard-app-slug')
		await slugInput.setValue('custom-slug')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.slugManuallyEdited).toBe(true)
		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.slug).toBe('custom-slug')
	})

	// -------------------------------------------------------------------------
	// slugError computed
	// -------------------------------------------------------------------------

	it('slugError is null for a valid slug', () => {
		const wrapper = mountStep1({ slug: 'my-app' })
		expect(wrapper.vm.slugError).toBeNull()
	})

	it('slugError is null when slug is empty (isValid handles the empty-name guard)', () => {
		const wrapper = mountStep1({ slug: '' })
		// validateSlug('') returns valid:false but slugError guards against empty explicitly
		// The component checks: if (!this.payload.slug) return null
		expect(wrapper.vm.slugError).toBeNull()
	})

	it('slugError is set for leading-underscore slug', () => {
		const wrapper = mountStep1({ slug: '_internal' })
		expect(wrapper.vm.slugError).toBeTruthy()
		expect(wrapper.vm.slugError).toContain('reserved for openbuilt system use')
	})

	it('slugError is set for slug with invalid characters', () => {
		const wrapper = mountStep1({ slug: 'my app!' })
		expect(wrapper.vm.slugError).toBeTruthy()
		expect(wrapper.vm.slugError).toContain('hyphens only')
	})

	it('slugError is set for single-character slug', () => {
		const wrapper = mountStep1({ slug: 'a' })
		expect(wrapper.vm.slugError).toBeTruthy()
	})

	it('slug chip has error class when slugError is set', async () => {
		const wrapper = mountStep1({ slug: '_bad' })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.wizard-step1__slug-chip--error').exists()).toBe(true)
	})

	it('slug chip has no error class for valid slug', async () => {
		const wrapper = mountStep1({ slug: 'my-app' })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.wizard-step1__slug-chip--error').exists()).toBe(false)
	})

	// -------------------------------------------------------------------------
	// isValid computed
	// -------------------------------------------------------------------------

	it('isValid is false when name is empty', () => {
		const wrapper = mountStep1({ name: '', slug: 'my-app' })
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is false when slug is empty', () => {
		const wrapper = mountStep1({ name: 'My App', slug: '' })
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is false when slug has an error', () => {
		const wrapper = mountStep1({ name: 'My App', slug: '_invalid' })
		expect(wrapper.vm.isValid).toBe(false)
	})

	it('isValid is true when name is non-empty and slug is valid', () => {
		const wrapper = mountStep1({ name: 'My App', slug: 'my-app' })
		expect(wrapper.vm.isValid).toBe(true)
	})

	// -------------------------------------------------------------------------
	// _step1Valid emission via watcher
	// -------------------------------------------------------------------------

	it('emits _step1Valid:true when isValid transitions to true', async () => {
		const wrapper = mountStep1({ name: '', slug: '' })

		// Simulate child event that would set payload externally — instead mutate vm
		await wrapper.setProps({ payload: makePayload({ name: 'Good App', slug: 'good-app' }) })
		await wrapper.vm.$nextTick()
		// The watcher on isValid fires and emits
		const emitted = wrapper.emitted('update:payload') || []
		const validEmit = emitted.find(e => '_step1Valid' in e[0])
		expect(validEmit).toBeTruthy()
		expect(validEmit[0]._step1Valid).toBe(true)
	})

	// -------------------------------------------------------------------------
	// Description
	// -------------------------------------------------------------------------

	it('typing description emits update:payload with description key', async () => {
		const wrapper = mountStep1()
		const textarea = wrapper.find('#wizard-app-description')
		await textarea.setValue('A great app for everything')
		await wrapper.vm.$nextTick()

		const emitted = wrapper.emitted('update:payload')
		const lastEmit = emitted[emitted.length - 1][0]
		expect(lastEmit.description).toBe('A great app for everything')
	})

	// -------------------------------------------------------------------------
	// Slug chip display value
	// -------------------------------------------------------------------------

	it('slug chip shows em-dash when slug is empty', () => {
		const wrapper = mountStep1({ slug: '' })
		expect(wrapper.find('.wizard-step1__slug-chip').text()).toContain('—')
	})

	it('slug chip shows slug value when set', () => {
		const wrapper = mountStep1({ slug: 'my-app' })
		expect(wrapper.find('.wizard-step1__slug-chip').text()).toBe('my-app')
	})
})
