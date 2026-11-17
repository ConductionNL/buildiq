/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCV-021 (row `data-required-validation`): a maker marks a field
 * required and sets length, pattern and range rules in the field editor, and
 * the rules land in the schema property, where every write is checked against
 * them. The check itself is JSON Schema, run here with Ajv to show the saved
 * schema refuses and accepts exactly the scenario's values.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md
 */
import { shallowMount } from '@vue/test-utils'
import Ajv from 'ajv'
import { describe, expect, it } from 'vitest'
import FieldEditor, { fieldsToSchema } from '../../../src/components/schema-editor/FieldEditor.vue'

const t = (app, text) => text

/**
 * Mount the editor on rows, apply edits through its own handlers, and return the rows it emits.
 *
 * @param {Array<object>} fields The starting rows.
 * @param {Function} edit The edits, given the editor's vm.
 * @return {Promise<Array<object>>} The rows after the edits.
 */
async function edited(fields, edit) {
	const wrapper = shallowMount(FieldEditor, {
		props: { fields, schemaSlugs: [] },
		global: { mocks: { t } },
	})
	const vm = wrapper.vm
	const apply = async (fn) => {
		fn(vm)
		const emitted = wrapper.emitted('update:fields')
		await wrapper.setProps({ fields: emitted[emitted.length - 1][0] })
	}
	await edit(apply)
	return wrapper.props('fields')
}

function row(name, type) {
	return { _key: name, name, type, required: false, default: null, description: '', validation: {} }
}

function validator(schemaParts) {
	return new Ajv({ allErrors: true }).compile({ type: 'object', properties: schemaParts.properties, required: schemaParts.required })
}

describe('REQ-BQCV-021 field validation rules', () => {
	it('a required postcode with a maximum length and a pattern is saved and enforced', async () => {
		const fields = await edited([row('postcode', 'string')], async (apply) => {
			await apply((vm) => vm.updateField(0, 'required', true))
			await apply((vm) => vm.updateValidation(0, 'maxLength', 7))
			await apply((vm) => vm.updateValidation(0, 'pattern', '^[1-9][0-9]{3} ?[A-Z]{2}$'))
		})

		const schema = fieldsToSchema(fields)
		expect(schema.required).toEqual(['postcode'])
		expect(schema.properties.postcode).toMatchObject({ type: 'string', maxLength: 7, pattern: '^[1-9][0-9]{3} ?[A-Z]{2}$' })

		const validate = validator(schema)
		expect(validate({ postcode: '12345' })).toBe(false)
		expect(validate.errors.map((e) => e.instancePath)).toContain('/postcode')
		expect(validate({})).toBe(false)
		expect(validate.errors[0].params.missingProperty).toBe('postcode')
		expect(validate({ postcode: '1234 AB' })).toBe(true)
	})

	it('a number range on age refuses 130 and accepts 40', async () => {
		const fields = await edited([row('age', 'integer')], async (apply) => {
			await apply((vm) => vm.updateValidation(0, 'minimum', 0))
			await apply((vm) => vm.updateValidation(0, 'maximum', 120))
		})

		const schema = fieldsToSchema(fields)
		expect(schema.properties.age).toMatchObject({ type: 'integer', minimum: 0, maximum: 120 })
		expect(schema.required).toEqual([])

		const validate = validator(schema)
		expect(validate({ age: 130 })).toBe(false)
		expect(validate.errors[0].instancePath).toBe('/age')
		expect(validate({ age: 40 })).toBe(true)
	})

	it('a cleared rule is removed from the saved property', async () => {
		const fields = await edited([row('postcode', 'string')], async (apply) => {
			await apply((vm) => vm.updateValidation(0, 'maxLength', 7))
			await apply((vm) => vm.updateValidation(0, 'maxLength', null))
		})

		expect(fieldsToSchema(fields).properties.postcode).not.toHaveProperty('maxLength')
	})
})
