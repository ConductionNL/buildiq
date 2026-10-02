/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `FieldEditor.vue` (REQ-OBSD-003).
 *
 * The component is controlled — `fields` is a prop and `update:fields`
 * carries the next array. These tests drive its methods directly via
 * the exposed component instance, then assert the emitted update
 * payloads. Mounted stubs render placeholders for NcButton /
 * NcSelect / NcTextField / NcCheckboxRadioSwitch so the test does not
 * depend on Nextcloud-vue's full DOM tree.
 *
 * Covers:
 *  - Add field — appends a default `string` field row.
 *  - Remove field — confirm-dialog gated; only fires after confirm.
 *  - Reorder (move up / move down) — order is preserved in the emitted
 *    payload.
 *  - Type-change resets the validation map.
 *  - Required toggle propagates as a field-level boolean.
 *  - `nameError()` flags missing names, invalid patterns, duplicates.
 *  - `schemaToFields` / `fieldsToSchema` round-trip preserves shape.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldEditor, {
	fieldsToSchema,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled', 'ariaLabel'],
		template:
			'<button :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'error', 'helperText', 'placeholder'],
		template:
			'<label class="nc-textfield-stub" :data-label="label" :data-error="error"><input :value="value" @input="$emit(\'update:value\', $event.target.value)" /><span class="helper">{{ helperText }}</span></label>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'value', 'options', 'clearable'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'type'],
		template:
			'<label class="nc-cbrs-stub"><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>',
	},
	DeleteFieldDialog: {
		name: 'DeleteFieldDialog',
		props: ['open', 'fieldName'],
		template:
			'<div class="delete-field-stub" :data-open="open" :data-name="fieldName" />',
	},
}

function defaultField(overrides = {}) {
	return {
		_key: `f-${Math.random().toString(36).slice(2, 7)}`,
		name: 'subject',
		type: 'string',
		required: false,
		default: null,
		description: '',
		validation: {},
		...overrides,
	}
}

describe('FieldEditor', () => {
	it('REQ-OBSD-003: addField emits update:fields with a new default-string row', async () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [], schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.addField()
		const emitted = wrapper.emitted('update:fields')
		expect(emitted).toBeTruthy()
		const next = emitted[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].type).toBe('string')
		expect(next[0].required).toBe(false)
		expect(next[0].validation).toEqual({})
		expect(typeof next[0]._key).toBe('string')
	})

	it('REQ-OBSD-003 + REQ-OBSD-008: requestRemove opens the dialog; only confirm fires the removal', async () => {
		const fields = [defaultField({ name: 'one' }), defaultField({ name: 'two' })]
		const wrapper = mount(FieldEditor, {
			propsData: { fields, schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.requestRemove(1)
		expect(wrapper.vm.removeDialogOpen).toBe(true)
		expect(wrapper.vm.pendingRemoveName).toBe('two')
		// No emit yet — the dialog is open.
		expect(wrapper.emitted('update:fields')).toBeUndefined()

		// Confirm the removal.
		wrapper.vm.confirmRemove()
		const emitted = wrapper.emitted('update:fields')
		expect(emitted).toBeTruthy()
		const next = emitted[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].name).toBe('one')
		expect(wrapper.vm.removeDialogOpen).toBe(false)
	})

	it('REQ-OBSD-003: cancelRemove dismisses the dialog without emitting', async () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [defaultField()], schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.requestRemove(0)
		wrapper.vm.cancelRemove()
		expect(wrapper.vm.removeDialogOpen).toBe(false)
		expect(wrapper.emitted('update:fields')).toBeUndefined()
	})

	it('REQ-OBSD-003: moveUp swaps adjacent fields in the emitted order', () => {
		const fields = [
			defaultField({ name: 'a' }),
			defaultField({ name: 'b' }),
			defaultField({ name: 'c' }),
		]
		const wrapper = mount(FieldEditor, {
			propsData: { fields, schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.moveUp(2)
		const next = wrapper.emitted('update:fields')[0][0]
		expect(next.map((f) => f.name)).toEqual(['a', 'c', 'b'])
	})

	it('REQ-OBSD-003: moveDown swaps adjacent fields and is a no-op at the tail', () => {
		const fields = [defaultField({ name: 'a' }), defaultField({ name: 'b' })]
		const wrapper = mount(FieldEditor, {
			propsData: { fields, schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.moveDown(0)
		const next = wrapper.emitted('update:fields')[0][0]
		expect(next.map((f) => f.name)).toEqual(['b', 'a'])

		// tail no-op
		wrapper.vm.moveDown(1)
		// Second invocation should still produce an emit — but with the same order
		const second = wrapper.emitted('update:fields')[1]
		expect(second).toBeUndefined()
	})

	it('REQ-OBSD-003: type-change resets validation map (no leftover string format on a number)', () => {
		const wrapper = mount(FieldEditor, {
			propsData: {
				fields: [
					defaultField({
						validation: { format: 'email', maxLength: 100 },
					}),
				],
				schemaSlugs: [],
			},
			stubs,
		})
		wrapper.vm.updateField(0, 'type', 'integer')
		const next = wrapper.emitted('update:fields')[0][0]
		expect(next[0].type).toBe('integer')
		expect(next[0].validation).toEqual({})
	})

	it('REQ-OBSD-003: required toggle propagates as a field-level boolean', () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [defaultField()], schemaSlugs: [] },
			stubs,
		})
		wrapper.vm.updateField(0, 'required', true)
		const next = wrapper.emitted('update:fields')[0][0]
		expect(next[0].required).toBe(true)
	})

	it('REQ-OBSD-003: updateValidation deletes the key when the value is empty', () => {
		const wrapper = mount(FieldEditor, {
			propsData: {
				fields: [defaultField({ validation: { format: 'email' } })],
				schemaSlugs: [],
			},
			stubs,
		})
		wrapper.vm.updateValidation(0, 'format', '')
		const next = wrapper.emitted('update:fields')[0][0]
		expect(next[0].validation).toEqual({})
	})

	it('REQ-OBSD-003: nameError flags missing, invalid, and duplicate names', () => {
		const fields = [
			defaultField({ name: 'good' }),
			defaultField({ name: 'good' }), // duplicate
			defaultField({ name: '1bad' }), // pattern violation
			defaultField({ name: '' }), // missing
		]
		const wrapper = mount(FieldEditor, {
			propsData: { fields, schemaSlugs: [] },
			stubs,
		})
		expect(wrapper.vm.nameError(fields[0], 0)).toContain('unique')
		expect(wrapper.vm.nameError(fields[1], 1)).toContain('unique')
		expect(wrapper.vm.nameError(fields[2], 2)).toContain(
			'Name must start with a letter',
		)
		expect(wrapper.vm.nameError(fields[3], 3)).toContain('Name is required')
	})

	it('renders an empty hint when fields is empty', () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [], schemaSlugs: [] },
			stubs,
		})
		expect(wrapper.find('.buildiq-field-editor__empty').exists()).toBe(true)
	})

	describe('schemaToFields / fieldsToSchema round-trip', () => {
		it('preserves order, type, and required flag', () => {
			const schema = {
				properties: {
					subject: { type: 'string', maxLength: 200 },
					age: { type: 'integer', minimum: 0 },
				},
				required: ['subject'],
				'x-property-order': ['subject', 'age'],
			}
			const fields = schemaToFields(schema)
			expect(fields.map((f) => f.name)).toEqual(['subject', 'age'])
			expect(fields[0].required).toBe(true)
			expect(fields[1].type).toBe('integer')

			const { properties, required, order } = fieldsToSchema(fields)
			expect(order).toEqual(['subject', 'age'])
			expect(properties.subject.type).toBe('string')
			expect(properties.subject.maxLength).toBe(200)
			expect(properties.age.minimum).toBe(0)
			expect(required).toEqual(['subject'])
		})

		it('encodes relation fields with x-openregister-relation', () => {
			const schema = {
				properties: {
					owner: {
						type: 'string',
						'x-openregister-relation': {
							target: 'user',
							cardinality: 'one',
						},
					},
				},
				required: [],
			}
			const fields = schemaToFields(schema)
			expect(fields[0].type).toBe('relation')
			expect(fields[0].validation.target).toBe('user')

			const { properties } = fieldsToSchema(fields)
			expect(properties.owner['x-openregister-relation']).toMatchObject({
				target: 'user',
				cardinality: 'one',
			})
		})
	})

	// REQ-BQFT-004 (buildiq#990): a save keeps every property key the editor
	// does not own. Before the fix `propertyFromField()` rebuilt each property
	// from an empty object, so an imported `enum`, a `title` and any `x-` key
	// were gone after the first designer save.
	describe('REQ-BQFT-004: a save keeps the keys the designer does not edit', () => {
		const importedSchema = () => ({
			properties: {
				status: {
					type: 'string',
					title: 'Status',
					description: 'Where the permit stands',
					enum: ['open', 'granted', 'refused'],
					minLength: 2,
					'x-openregister-dependent-values': {
						field: 'kind',
						values: { tree: ['open', 'granted'] },
					},
				},
			},
			required: ['status'],
			'x-property-order': ['status'],
		})

		function mountWith(fields) {
			return mount(FieldEditor, {
				propsData: { fields, schemaSlugs: [] },
				stubs,
			})
		}

		it('an unedited field keeps enum, title and x- keys through a round trip', () => {
			const { properties } = fieldsToSchema(schemaToFields(importedSchema()))
			expect(properties.status).toEqual(importedSchema().properties.status)
		})

		it('an edited description and type still win over the loaded property', async () => {
			const wrapper = mountWith(schemaToFields(importedSchema()))
			wrapper.vm.updateField(0, 'description', 'Edited by the maker')
			const edited = wrapper.emitted('update:fields')[0][0]
			const { properties } = fieldsToSchema(edited)
			expect(properties.status.description).toBe('Edited by the maker')
			expect(properties.status.enum).toEqual(['open', 'granted', 'refused'])
			expect(properties.status.title).toBe('Status')

			await wrapper.setProps({ fields: edited })
			wrapper.vm.updateField(0, 'type', 'integer')
			const retyped = wrapper.emitted('update:fields')[1][0]
			const saved = fieldsToSchema(retyped).properties.status
			expect(saved.type).toBe('integer')
			// The previous type's own slot is cleared on a type change.
			expect(saved.minLength).toBeUndefined()
			expect(saved.title).toBe('Status')
			expect(saved['x-openregister-dependent-values']).toEqual(
				importedSchema().properties.status[
					'x-openregister-dependent-values'
				],
			)
		})

		it('clearing a key the editor owns still removes it', async () => {
			const wrapper = mountWith(schemaToFields(importedSchema()))
			wrapper.vm.updateValidation(0, 'minLength', '')
			const next = wrapper.emitted('update:fields')[0][0]
			await wrapper.setProps({ fields: next })
			wrapper.vm.updateField(0, 'description', '')
			const cleared = wrapper.emitted('update:fields')[1][0]
			const saved = fieldsToSchema(cleared).properties.status
			expect(saved.minLength).toBeUndefined()
			expect(saved.description).toBeUndefined()
			expect(saved.enum).toEqual(['open', 'granted', 'refused'])
		})

		it('a new field row with no loaded property saves only what the editor holds', () => {
			const { properties } = fieldsToSchema([
				{
					_key: 'k1',
					name: 'note',
					type: 'string',
					required: false,
					default: null,
					description: '',
					validation: { maxLength: 10 },
				},
			])
			expect(properties.note).toEqual({ type: 'string', maxLength: 10 })
		})
	})
})
