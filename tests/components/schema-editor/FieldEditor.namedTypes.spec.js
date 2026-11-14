/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Named field types and the choice editor of `FieldEditor.vue`
 * (data-field-types-and-choice-lists, REQ-BQFT-001..004).
 *
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-the-designer-offers-named-field-types-req-bqft-001
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-choice-field-has-inline-or-shared-options-req-bqft-002
 * @spec openspec/changes/data-field-types-and-choice-lists/specs/schema-designer-field-types/spec.md#requirement-a-user-field-points-at-a-nextcloud-user-req-bqft-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/services/conceptSchemes.js', () => ({
	listConceptSchemes: vi.fn(),
	listSchemeOptions: vi.fn(),
}))

import FieldEditor, {
	fieldsToSchema,
	NAMED_TYPES,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'
import { listConceptSchemes, listSchemeOptions } from '../../../src/services/conceptSchemes.js'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'ariaLabel', 'variant'],
		template: '<button :disabled="disabled" :aria-label="ariaLabel" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input :data-label="label" :value="modelValue" />' },
	NcSelect: { name: 'NcSelect', props: ['inputLabel', 'modelValue', 'options'], template: '<div class="nc-select-stub" :data-label="inputLabel" />' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', props: ['modelValue', 'value', 'type'], template: '<label class="radio"><slot /></label>' },
	DeleteFieldDialog: { name: 'DeleteFieldDialog', template: '<div />' },
}

/**
 * A row of the given type.
 *
 * @param {string} type The named type.
 * @param {object} validation Its validation values.
 * @return {object} The row.
 */
function row(type, validation = {}) {
	return { _key: 'k-' + type, name: 'f', type, required: false, default: null, description: '', validation }
}

/**
 * Save one row and load it back.
 *
 * @param {object} field The row.
 * @return {{saved: object, reloaded: object}} The stored property and the row read from it.
 */
function roundTrip(field) {
	const saved = fieldsToSchema([field]).properties.f
	const [reloaded] = schemaToFields({ properties: { f: saved } })
	return { saved, reloaded }
}

describe('REQ-BQFT-001: named field types', () => {
	const table = [
		['text', {}, { type: 'string' }],
		['longtext', {}, { type: 'string', format: 'textarea' }],
		['number', {}, { type: 'number' }],
		['integer', {}, { type: 'integer' }],
		['boolean', {}, { type: 'boolean' }],
		['date', {}, { type: 'string', format: 'date' }],
		['datetime', {}, { type: 'string', format: 'date-time' }],
		['choice', { options: [{ value: 'open', label: 'Open' }, { value: 'closed', label: '' }] }, { type: 'string', enum: ['open', 'closed'], 'x-enum-labels': { open: 'Open' } }],
		['choices', { options: [{ value: 'a', label: '' }] }, { type: 'array', items: { type: 'string', enum: ['a'] } }],
		['file', {}, { type: 'file' }],
		['files', {}, { type: 'array', items: { type: 'file' } }],
		['user', {}, { type: 'string', referenceType: 'nextcloud-user' }],
		['users', {}, { type: 'array', items: { type: 'string', referenceType: 'nextcloud-user' } }],
		['relation', { target: 'person' }, { type: 'string', 'x-openregister-relation': { target: 'person', cardinality: 'one' } }],
		['array', { itemsType: 'integer' }, { type: 'array', items: { type: 'integer' } }],
		['object', {}, { type: 'object' }],
	]

	it('the picker offers every named type of design D1, in order', () => {
		expect(NAMED_TYPES.map((type) => type.value)).toEqual(table.map(([type]) => type))
		expect(NAMED_TYPES.map((type) => type.label)).toEqual([
			'Text', 'Long text', 'Number', 'Whole number', 'Yes or no', 'Date', 'Date and time', 'Choice',
			'Several choices', 'File', 'Several files', 'User', 'Several users', 'Relation', 'List', 'Group',
		])
	})

	it.each(table)('%s is stored as its shape and opens as itself again', (type, validation, expected) => {
		const { saved, reloaded } = roundTrip(row(type, validation))
		expect(saved).toEqual(expected)
		expect(reloaded.type).toBe(type)
	})

	it('a maker adding a date field stores type string with format date', () => {
		expect(fieldsToSchema([{ ...row('date'), name: 'decidedOn' }]).properties.decidedOn).toEqual({ type: 'string', format: 'date' })
	})

	it('an existing date field opens as a date, not a string with a free-text format', () => {
		const [field] = schemaToFields({ properties: { startsOn: { type: 'string', format: 'date' } } })
		expect(field.type).toBe('date')
		expect(field.validation).toEqual({})
	})

	it('a property no named type fits opens as its JSON type and keeps its format', () => {
		const [field] = schemaToFields({ properties: { mail: { type: 'string', format: 'email' } } })
		expect(field.type).toBe('string')
		expect(fieldsToSchema([field]).properties.mail).toEqual({ type: 'string', format: 'email' })

		const wrapper = mount(FieldEditor, { props: { fields: [field] }, global: { stubs, mocks: { t: (app, s) => s } } })
		const options = wrapper.vm.typeOptionsFor(field).map((o) => o.value)
		expect(options).toContain('string')
		expect(options.slice(0, NAMED_TYPES.length)).toEqual(NAMED_TYPES.map((type) => type.value))
		expect(wrapper.vm.typeOptionsFor(row('date')).map((o) => o.value)).not.toContain('string')
	})

	it('a user stored with the older format: user opens as a user and saves referenceType', () => {
		const [field] = schemaToFields({ properties: { assignee: { type: 'string', format: 'user', title: 'Assignee' } } })
		expect(field.type).toBe('user')
		expect(fieldsToSchema([field]).properties.assignee).toEqual({ type: 'string', referenceType: 'nextcloud-user', title: 'Assignee' })
	})

	it('a list of users opens as several users', () => {
		const [field] = schemaToFields({ properties: { team: { type: 'array', items: { type: 'string', referenceType: 'nextcloud-user' } } } })
		expect(field.type).toBe('users')
	})

	it('changing a choice to a whole number clears the choice slots, keeps the rest', () => {
		const [field] = schemaToFields({ properties: { f: { type: 'string', enum: ['a'], 'x-enum-labels': { a: 'A' }, title: 'F' } } })
		const saved = fieldsToSchema([{ ...field, type: 'integer', validation: {} }]).properties.f
		expect(saved).toEqual({ type: 'integer', title: 'F' })
	})

	it('changing several choices to a plain list clears the item choice slots', () => {
		const [field] = schemaToFields({ properties: { f: { type: 'array', items: { type: 'string', enum: ['a'], title: 'i' } } } })
		expect(field.type).toBe('choices')
		const saved = fieldsToSchema([{ ...field, type: 'array', validation: { itemsType: 'string' } }]).properties.f
		expect(saved).toEqual({ type: 'array', items: { type: 'string', title: 'i' } })
	})

	it('an enum an import set on a number passes through a save untouched (REQ-BQFT-004)', () => {
		const imported = { type: 'number', enum: [1, 2], minimum: 0 }
		expect(fieldsToSchema(schemaToFields({ properties: { f: imported } })).properties.f).toEqual(imported)
	})
})

describe('REQ-BQFT-002: inline or shared options', () => {
	it('inline options keep their order and labels, and drop empty values', () => {
		const { saved, reloaded } = roundTrip(row('choice', {
			options: [{ value: 'b', label: 'Bee' }, { value: '', label: 'ignored' }, { value: 'a', label: '' }],
		}))
		expect(saved).toEqual({ type: 'string', enum: ['b', 'a'], 'x-enum-labels': { b: 'Bee' } })
		expect(reloaded.validation.options).toEqual([{ value: 'b', label: 'Bee' }, { value: 'a', label: '' }])
	})

	it('a shared list stores conceptScheme and no enum', () => {
		const [field] = schemaToFields({ properties: { f: { type: 'string', enum: ['x'], 'x-enum-labels': { x: 'X' } } } })
		const saved = fieldsToSchema([{ ...field, name: 'municipality', validation: { conceptScheme: 'gemeenten' } }]).properties.municipality
		expect(saved).toEqual({ type: 'string', conceptScheme: 'gemeenten' })
		expect(schemaToFields({ properties: { m: saved } })[0].validation).toEqual({ conceptScheme: 'gemeenten' })
	})

	describe('the editor', () => {
		beforeEach(() => {
			listConceptSchemes.mockReset()
			listSchemeOptions.mockReset()
		})

		/**
		 * Mount over the given rows.
		 *
		 * @param {Array<object>} fields The rows.
		 * @return {object} The wrapper.
		 */
		function mountWith(fields) {
			return mount(FieldEditor, { props: { fields }, global: { stubs, mocks: { t: (app, s) => s } } })
		}

		/**
		 * The validation of the last emitted row.
		 *
		 * @param {object} wrapper The wrapper.
		 * @return {object} The validation.
		 */
		function lastValidation(wrapper) {
			const emitted = wrapper.emitted('update:fields')
			return emitted[emitted.length - 1][0][0].validation
		}

		it('adds, edits, moves and removes options', async () => {
			const wrapper = mountWith([row('choice', { options: [{ value: 'a', label: 'A' }] })])
			wrapper.vm.addOption(0)
			expect(lastValidation(wrapper).options).toEqual([{ value: 'a', label: 'A' }, { value: '', label: '' }])

			await wrapper.setProps({ fields: [row('choice', { options: [{ value: 'a', label: 'A' }, { value: '', label: '' }] })] })
			wrapper.vm.updateOption(0, 1, 'value', 'b')
			expect(lastValidation(wrapper).options[1]).toEqual({ value: 'b', label: '' })

			await wrapper.setProps({ fields: [row('choice', { options: [{ value: 'a', label: 'A' }, { value: 'b', label: '' }] })] })
			wrapper.vm.moveOption(0, 1, -1)
			expect(lastValidation(wrapper).options.map((o) => o.value)).toEqual(['b', 'a'])
			const before = wrapper.emitted('update:fields').length
			wrapper.vm.moveOption(0, 0, -1)
			expect(wrapper.emitted('update:fields')).toHaveLength(before + 1)
			expect(lastValidation(wrapper).options.map((o) => o.value)).toEqual(['a', 'b'])

			wrapper.vm.removeOption(0, 0)
			expect(lastValidation(wrapper).options).toEqual([{ value: 'b', label: '' }])
		})

		it('switching to a shared list drops the inline options, and back drops the list', async () => {
			listConceptSchemes.mockResolvedValue([])
			const wrapper = mountWith([row('choice', { options: [{ value: 'a', label: '' }] })])
			wrapper.vm.setChoiceSource(0, true)
			expect(lastValidation(wrapper)).toEqual({ conceptScheme: '' })
			expect(listConceptSchemes).toHaveBeenCalledTimes(1)

			await wrapper.setProps({ fields: [row('choice', { conceptScheme: 'gemeenten' })] })
			wrapper.vm.setChoiceSource(0, false)
			expect(lastValidation(wrapper)).toEqual({ options: [] })
		})

		it('picking a shared list stores its slug and shows its options read-only', async () => {
			listConceptSchemes.mockResolvedValue([{ value: 'gemeenten', label: 'Gemeenten', uri: 'https://example.org/gemeenten' }])
			listSchemeOptions.mockResolvedValue([{ value: '0363', label: 'Amsterdam' }, { value: '0599', label: 'Rotterdam' }])
			const wrapper = mountWith([row('choice', { conceptScheme: '' })])
			await wrapper.vm.loadSchemes()

			wrapper.vm.pickScheme(0, wrapper.vm.schemes[0])
			expect(lastValidation(wrapper)).toEqual({ conceptScheme: 'gemeenten' })
			await flushPromises()
			expect(listSchemeOptions).toHaveBeenCalledWith('https://example.org/gemeenten')

			await wrapper.setProps({ fields: [row('choice', { conceptScheme: 'gemeenten' })] })
			const shared = wrapper.find('.buildiq-field-editor__shared-options')
			expect(shared.findAll('li').map((li) => li.text())).toEqual(['Amsterdam', 'Rotterdam'])
			expect(shared.find('input').exists()).toBe(false)
		})

		it('a schema that already uses a shared list loads it on open', async () => {
			listConceptSchemes.mockResolvedValue([{ value: 'gemeenten', label: 'Gemeenten', uri: 'u:g' }])
			listSchemeOptions.mockResolvedValue([{ value: '1', label: 'One' }])
			const wrapper = mountWith([row('choice', { conceptScheme: 'gemeenten' })])
			await flushPromises()
			expect(listSchemeOptions).toHaveBeenCalledWith('u:g')
			expect(wrapper.vm.schemeOptions.gemeenten).toEqual([{ value: '1', label: 'One' }])
		})

		it('a list that cannot be loaded says so', async () => {
			listConceptSchemes.mockRejectedValue(new Error('down'))
			const wrapper = mountWith([row('choice', { conceptScheme: '' })])
			await wrapper.vm.loadSchemes()
			expect(wrapper.vm.schemesError).toBe('The shared lists could not be loaded.')
		})
	})
})
