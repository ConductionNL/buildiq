/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * FormFieldBuilder device fields: signature, audio and location with their
 * options (forms-signature-audio-and-location, REQ-BQSA-001, REQ-BQSA-002,
 * REQ-BQSA-004).
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

/**
 * Mount the builder.
 *
 * @param {Array<object>} modelValue - the fields.
 * @param {string} [formMode] - the form's mode.
 * @return {object} the wrapper.
 */
function mountBuilder(modelValue, formMode = 'create') {
	return mount(FormFieldBuilder, { props: { modelValue, formMode, showLogic: true } })
}

/**
 * The last emitted field list.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>}
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

describe('FormFieldBuilder device fields', () => {
	// The shared stub ignores placeholders; these hints carry numbers worth checking.
	const sharedT = globalThis.t
	beforeEach(() => {
		globalThis.t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match))
	})
	afterEach(() => {
		globalThis.t = sharedT
	})

	it('offers signature, audio and location as field types', () => {
		const wrapper = mountBuilder([{ key: 'a', label: 'A', type: 'string' }])
		const types = wrapper.find('select').findAll('option').map((option) => option.attributes('value'))

		expect(types).toEqual(expect.arrayContaining(['signature', 'audio', 'location']))
		expect(wrapper.find('[data-testid="device-options-0"]').exists()).toBe(false)
	})

	it('a signature that allows drawing only keeps typed signing off in the config', async () => {
		const wrapper = mountBuilder([{ key: 'handtekening', label: 'Handtekening', type: 'signature' }])

		expect(wrapper.find('[data-testid="signature-allow-drawn"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="signature-allow-typed"]').element.checked).toBe(true)

		await wrapper.find('[data-testid="signature-allow-typed"]').setValue(false)
		expect(last(wrapper)).toEqual([{ key: 'handtekening', label: 'Handtekening', type: 'signature', allowTyped: false }])
	})

	it('a signature without any mode is marked', () => {
		const wrapper = mountBuilder([{ key: 'h', type: 'signature', allowTyped: false, allowDrawn: false }])

		expect(wrapper.text()).toContain('Allow drawing, typing or both.')
	})

	it('an audio field writes its length and shows the size of a clip', async () => {
		const wrapper = mountBuilder([{ key: 'spraaknotitie', type: 'audio' }])

		expect(wrapper.find('[data-testid="audio-max-seconds"]').element.value).toBe('60')
		expect(wrapper.find('[data-testid="audio-size"]').text()).toBe('A clip of 60 seconds takes about 313 KB.')

		await wrapper.find('[data-testid="audio-max-seconds"]').setValue('120')
		expect(last(wrapper)).toEqual([{ key: 'spraaknotitie', type: 'audio', maxSeconds: 120 }])

		await wrapper.find('[data-testid="audio-max-seconds"]').setValue('')
		expect(last(wrapper)).toEqual([{ key: 'spraaknotitie', type: 'audio' }])
	})

	it('an audio field longer than the inline cap names the longest length that fits', () => {
		const wrapper = mountBuilder([{ key: 's', type: 'audio', maxSeconds: 60, maxSize: 200 * 1024 }])

		expect(wrapper.find('[data-testid="audio-size"]').text()).toBe('Too long to store with the record: at most 38 seconds fit.')
	})

	it('a location field writes its target properties and can switch the map pick off', async () => {
		const wrapper = mountBuilder([{ key: 'plek', type: 'location' }])
		expect(wrapper.text()).toContain('Name the latitude and the longitude property.')

		await wrapper.find('[data-testid="location-lat-field"]').setValue(' lat ')
		expect(last(wrapper)).toEqual([{ key: 'plek', type: 'location', latField: 'lat' }])

		await wrapper.setProps({ modelValue: last(wrapper) })
		await wrapper.find('[data-testid="location-lng-field"]').setValue('lng')
		await wrapper.setProps({ modelValue: last(wrapper) })
		await wrapper.find('[data-testid="location-allow-map-pick"]').setValue(false)
		expect(last(wrapper)).toEqual([{ key: 'plek', type: 'location', latField: 'lat', lngField: 'lng', allowMapPick: false }])

		await wrapper.setProps({ modelValue: last(wrapper) })
		expect(wrapper.text()).not.toContain('Name the latitude and the longitude property.')
		await wrapper.find('[data-testid="location-allow-map-pick"]').setValue(true)
		expect(last(wrapper)).toEqual([{ key: 'plek', type: 'location', latField: 'lat', lngField: 'lng' }])
	})

	it('a public form marks a location or audio field, and leaves a signature alone', () => {
		const wrapper = mountBuilder(
			[
				{ key: 'plek', type: 'location', latField: 'lat', lngField: 'lng' },
				{ key: 'h', type: 'signature' },
			],
			'public',
		)
		const message = 'A public form cannot use the microphone or the position: public forms belong to the portal.'

		expect(wrapper.find('[data-testid="device-options-0"]').text()).toContain(message)
		expect(wrapper.find('[data-testid="device-options-1"]').text()).not.toContain(message)
	})
})
