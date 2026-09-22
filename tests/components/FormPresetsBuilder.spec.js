/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPresetsBuilder.vue.
 *
 * The hidden preset is the point of the row: the citizen's form records that
 * the application came in through the portal, and the citizen is never asked a
 * question whose answer was already decided.
 *
 * Spec: registration-form-builder (REQ-OBRF-005).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import FormPresetsBuilder from '../../src/components/page-editor/fields/FormPresetsBuilder.vue'

/**
 * Mount the builder over a set of presets.
 *
 * @param {object} props - props to override.
 * @return {object} The mounted wrapper.
 */
function mountBuilder(props = {}) {
	return mount(FormPresetsBuilder, {
		props: {
			presets: [{ field: 'intakeChannel', value: 'portal', hidden: false }],
			properties: ['intakeChannel', 'applicantRole'],
			...props,
		},
	})
}

describe('FormPresetsBuilder', () => {
	it('hides a preset from the filer without touching the rest of it', async () => {
		const wrapper = mountBuilder()

		await wrapper.find('input[type="checkbox"]').setValue(true)

		const emitted = wrapper.emitted('update:presets')
		expect(emitted[emitted.length - 1][0]).toEqual([
			{ field: 'intakeChannel', value: 'portal', hidden: true },
		])
	})

	it('picks the property off the target schema rather than asking for a typed name', () => {
		const wrapper = mountBuilder()
		const options = wrapper.findAll('option').map((node) => node.text())

		expect(options).toContain('applicantRole')
	})

	it('falls back to free text when the target schema could not be read', () => {
		const wrapper = mountBuilder({ properties: null })

		// An empty picker would look like a schema that declares nothing.
		expect(wrapper.find('select').exists()).toBe(false)
		expect(wrapper.findAll('input[type="text"]').length).toBe(2)
	})

	it('adds and removes a preset', async () => {
		const wrapper = mountBuilder()
		const buttons = wrapper.findAll('button')

		await buttons.at(-1).trigger('click')
		expect(wrapper.emitted('update:presets')[0][0]).toHaveLength(2)

		await wrapper.find('[aria-label="Remove preset"]').trigger('click')
		const emitted = wrapper.emitted('update:presets')
		expect(emitted[emitted.length - 1][0]).toEqual([])
	})
})
