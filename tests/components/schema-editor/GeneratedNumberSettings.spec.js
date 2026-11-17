/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the automatic-number setting of a text field
 * (data-generated-number-field, REQ-OBSD-009): the live example, the stored
 * keyword, the round trip through `schemaToFields` / `fieldsToSchema`, and
 * the formats OpenRegister would refuse.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import {
	fieldsToSchema,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'
import GeneratedNumberSettings from '../../../src/components/schema-editor/GeneratedNumberSettings.vue'
import {
	exampleNumber,
	formatProblem,
	generatedKeyword,
} from '../../../src/components/schema-editor/generatedNumber.js'

const stubs = {
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText', 'placeholder'],
		template:
			'<label class="nc-textfield-stub" :data-label="label" :data-error="error"><span class="helper">{{ helperText }}</span></label>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		template: '<label class="nc-cbrs-stub"><slot /></label>',
	},
}

const JULY_2026 = new Date(2026, 6, 14)

/**
 * Mount the setting.
 *
 * @param {object|null} modelValue The setting.
 * @return {object} The wrapper.
 */
function mountSetting(modelValue) {
	return mount(GeneratedNumberSettings, {
		props: { modelValue, schemaSlug: 'factuur', now: JULY_2026 },
		global: { stubs },
	})
}

describe('generated number field (REQ-OBSD-009)', () => {
	it('shows INV-2026-0001 for INV-{year}-{seq:4} and stores the keyword', () => {
		const setting = { enabled: true, sequence: 'factuur', format: 'INV-{year}-{seq:4}', resetOn: 'year' }
		const wrapper = mountSetting(setting)

		// The test t() returns the source string, so the example is read from the component.
		expect(wrapper.vm.example).toBe('INV-2026-0001')
		expect(wrapper.find('.buildiq-generated-number__example').exists()).toBe(true)
		expect(exampleNumber('F{month}/{seq}', JULY_2026, 12)).toBe('F07/12')
		expect(generatedKeyword(setting, 'factuur')).toEqual({ sequence: 'factuur', format: 'INV-{year}-{seq:4}', resetOn: 'year' })
		expect(generatedKeyword({ ...setting, sequence: ' ' }, 'factuur').sequence).toBe('factuur')
	})

	it('switching on starts from the schema slug and {seq:5}; switching off clears it', async () => {
		const wrapper = mountSetting(null)
		wrapper.vm.toggle(true)
		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual({ enabled: true, sequence: 'factuur', format: '{seq:5}', resetOn: 'never' })

		wrapper.vm.toggle(false)
		expect(wrapper.emitted('update:modelValue')[1][0]).toBeNull()
	})

	it('reads the keyword back and writes it only while the setting is on', () => {
		const block = { sequence: 'factuur', format: 'INV-{year}-{seq:4}', resetOn: 'year' }
		const fields = schemaToFields({ properties: { nummer: { type: 'string', title: 'Nummer', 'x-openregister-generated': block } } })

		expect(fields[0].generated).toEqual({ enabled: true, ...block })
		expect(fieldsToSchema(fields, 'factuur').properties.nummer).toEqual({ type: 'string', title: 'Nummer', 'x-openregister-generated': block })

		const off = fieldsToSchema([{ ...fields[0], generated: null }], 'factuur').properties.nummer
		expect(off).toEqual({ type: 'string', title: 'Nummer' })

		const asNumber = fieldsToSchema([{ ...fields[0], type: 'integer' }], 'factuur').properties.nummer
		expect(asNumber['x-openregister-generated']).toBeUndefined()

		const made = fieldsToSchema([{ name: 'nummer', type: 'string', validation: {}, generated: { enabled: true, sequence: '', format: 'F-{seq:3}', resetOn: 'never' } }], 'factuur')
		expect(made.properties.nummer['x-openregister-generated']).toEqual({ sequence: 'factuur', format: 'F-{seq:3}', resetOn: 'never' })
	})

	it('refuses a format without a counter, with the reason, and does not save it', () => {
		expect(formatProblem('INV-{year}', 'never')).toEqual({ code: 'no-counter' })
		expect(formatProblem('INV-{seq:4}', 'year')).toEqual({ code: 'year-reset-without-year' })
		expect(formatProblem('INV-{yyyy}-{seq:4}', 'never')).toEqual({ code: 'unknown-placeholder', placeholder: '{yyyy}' })
		expect(formatProblem('INV-{year}-{seq:4}', 'year')).toBeNull()

		const wrapper = mountSetting({ enabled: true, sequence: 'factuur', format: 'INV-{year}', resetOn: 'never' })
		const format = wrapper.findAll('.nc-textfield-stub')[1]
		expect(format.attributes('data-error')).toBe('true')
		expect(format.find('.helper').text()).toBe('The format needs a counter such as {seq:4}, or every record gets the same number.')
		expect(wrapper.find('.buildiq-generated-number__example').exists()).toBe(false)

		const block = { sequence: 'factuur', format: 'INV-{year}-{seq:4}', resetOn: 'year' }
		const [field] = schemaToFields({ properties: { nummer: { type: 'string', 'x-openregister-generated': block } } })
		const refusedEdit = { ...field, generated: { ...field.generated, format: 'INV-{year}' } }
		expect(fieldsToSchema([refusedEdit], 'factuur').properties.nummer['x-openregister-generated']).toEqual(block)

		const refusedNew = { name: 'nummer', type: 'string', validation: {}, generated: { enabled: true, sequence: 'factuur', format: 'INV', resetOn: 'never' } }
		expect(fieldsToSchema([refusedNew], 'factuur').properties.nummer['x-openregister-generated']).toBeUndefined()
	})
})
