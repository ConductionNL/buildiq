/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row-level tests for `FieldEditor.vue`, driven through the template.
 *
 * The stubs emit `update:modelValue` and `click` the way the real Nextcloud
 * components do, so each test covers the template binding and the handler
 * behind it: reordering, editing the common columns, every type's
 * validation inputs (with their number parsing), and the remove dialog.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldEditor from '../../../src/components/schema-editor/FieldEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'ariaLabel'],
		template: '<button :aria-label="ariaLabel" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText', 'placeholder'],
		template: '<input :value="modelValue" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options', 'clearable'],
		template: '<div class="nc-select-stub" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		template: '<label><slot /></label>',
	},
	DeleteFieldDialog: {
		name: 'DeleteFieldDialog',
		props: ['open', 'fieldName'],
		template: '<div class="dialog-stub" />',
	},
	PlusIcon: true,
	DeleteIcon: true,
	ChevronUpIcon: true,
	ChevronDownIcon: true,
}

function fields () {
  return [
	{ _key: 'f1', name: 'title', type: 'string', required: false, description: '', validation: { format: 'email', minLength: 2 } },
	{ _key: 'f2', name: 'count', type: 'integer', required: true, description: '', validation: { minimum: 1 } },
	{ _key: 'f3', name: 'tags', type: 'array', required: false, description: '', validation: {} },
	{ _key: 'f4', name: 'owner', type: 'relation', required: false, description: '', validation: { target: 'person' } },
]
}

/**
 * Mount the editor with one field of each validation family.
 *
 * @return {object} Wrapper.
 */
function mountEditor() {
	return mount(FieldEditor, {
		propsData: { fields: fields(), schemaSlugs: ['person', 'case'] },
		stubs,
		mocks: { t: (app, text) => text },
	})
}

/**
 * The n-th component of a kind whose label prop matches.
 *
 * @param {object} wrapper Wrapper.
 * @param {string} kind Component name.
 * @param {string} label Label.
 * @param {number} at Index among matches.
 * @return {object} Component wrapper.
 */
function byLabel(wrapper, kind, label, at = 0) {
	const prop = { NcSelect: 'inputLabel', NcButton: 'ariaLabel' }[kind] || 'label'
	return wrapper.findAllComponents({ name: kind }).filter((c) => c.props(prop) === label).at(at)
}

/**
 * The fields array of the n-th emission.
 *
 * @param {object} wrapper Wrapper.
 * @param {number} n Emission index.
 * @return {Array<object>} Fields.
 */
function emitted(wrapper, n) {
	return wrapper.emitted('update:fields')[n][0]
}

describe('FieldEditor rows', () => {
	it('move up / down swap neighbours; the first cannot go up and the last cannot go down', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcButton', 'Move up', 1).vm.$emit('click')
		byLabel(wrapper, 'NcButton', 'Move down', 0).vm.$emit('click')
		wrapper.vm.moveUp(0)
		wrapper.vm.moveDown(3)

		expect(wrapper.emitted('update:fields')).toHaveLength(2)
		expect(emitted(wrapper, 0).map((f) => f.name)).toEqual(['count', 'title', 'tags', 'owner'])
		expect(emitted(wrapper, 1).map((f) => f.name)).toEqual(['count', 'title', 'tags', 'owner'])
		expect(byLabel(wrapper, 'NcButton', 'Move up', 0).props('disabled')).toBe(true)
		expect(byLabel(wrapper, 'NcButton', 'Move down', 3).props('disabled')).toBe(true)
	})

	it('editing name, required and description changes only that column', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcTextField', 'Name', 0).vm.$emit('update:modelValue', 'subject')
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).at(0).vm.$emit('update:modelValue', true)
		byLabel(wrapper, 'NcTextField', 'Description', 2).vm.$emit('update:modelValue', 'Labels')

		expect(emitted(wrapper, 0)[0]).toEqual({ ...fields()[0], name: 'subject' })
		expect(emitted(wrapper, 1)[0]).toEqual({ ...fields()[0], required: true })
		expect(emitted(wrapper, 2)[2]).toEqual({ ...fields()[2], description: 'Labels' })
	})

	it('changing the type resets validation; a cleared type falls back to string', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcSelect', 'Type', 0).vm.$emit('update:modelValue', { value: 'number' })
		byLabel(wrapper, 'NcSelect', 'Type', 1).vm.$emit('update:modelValue', null)

		expect(emitted(wrapper, 0)[0]).toMatchObject({ type: 'number', validation: {} })
		expect(emitted(wrapper, 1)[1]).toMatchObject({ type: 'string', validation: {} })
	})

	it('string inputs set format and pattern, parse lengths as integers, and blank removes the rule', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcTextField', 'Format (optional)').vm.$emit('update:modelValue', '')
		byLabel(wrapper, 'NcTextField', 'Pattern (regex, optional)').vm.$emit('update:modelValue', '^[a-z]+$')
		byLabel(wrapper, 'NcTextField', 'Min length').vm.$emit('update:modelValue', '7.9')
		byLabel(wrapper, 'NcTextField', 'Max length').vm.$emit('update:modelValue', 'many')

		expect(emitted(wrapper, 0)[0].validation).toEqual({ minLength: 2 })
		expect(emitted(wrapper, 1)[0].validation).toEqual({ format: 'email', minLength: 2, pattern: '^[a-z]+$' })
		expect(emitted(wrapper, 2)[0].validation).toEqual({ format: 'email', minLength: 7 })
		expect(emitted(wrapper, 3)[0].validation).toEqual({ format: 'email', minLength: 2 })
		expect(byLabel(wrapper, 'NcTextField', 'Min length').props('modelValue')).toBe('2')
		expect(byLabel(wrapper, 'NcTextField', 'Max length').props('modelValue')).toBe('')
	})

	it('number inputs parse decimals and drop what is not a number', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcTextField', 'Minimum').vm.$emit('update:modelValue', '')
		byLabel(wrapper, 'NcTextField', 'Maximum').vm.$emit('update:modelValue', '2.5')
		byLabel(wrapper, 'NcTextField', 'Multiple of').vm.$emit('update:modelValue', 'abc')

		expect(emitted(wrapper, 0)[1].validation).toEqual({})
		expect(emitted(wrapper, 1)[1].validation).toEqual({ minimum: 1, maximum: 2.5 })
		expect(emitted(wrapper, 2)[1].validation).toEqual({ minimum: 1 })
		expect(byLabel(wrapper, 'NcTextField', 'Minimum').props('modelValue')).toBe('1')
	})

	it('array inputs set the items type (string when cleared) and integer bounds', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcSelect', 'Items type').vm.$emit('update:modelValue', { value: 'integer' })
		byLabel(wrapper, 'NcSelect', 'Items type').vm.$emit('update:modelValue', null)
		byLabel(wrapper, 'NcTextField', 'Min items').vm.$emit('update:modelValue', '1')
		byLabel(wrapper, 'NcTextField', 'Max items').vm.$emit('update:modelValue', '10')

		expect(emitted(wrapper, 0)[2].validation).toEqual({ itemsType: 'integer' })
		expect(emitted(wrapper, 1)[2].validation).toEqual({ itemsType: 'string' })
		expect(emitted(wrapper, 2)[2].validation).toEqual({ minItems: 1 })
		expect(emitted(wrapper, 3)[2].validation).toEqual({ maxItems: 10 })
	})

	it('relation inputs set target, cardinality and inverse, with their fallbacks', () => {
		const wrapper = mountEditor()

		expect(byLabel(wrapper, 'NcSelect', 'Target schema').props('modelValue')).toEqual({ value: 'person', label: 'person' })
		expect(byLabel(wrapper, 'NcSelect', 'Cardinality').props('modelValue')).toEqual({ value: 'one', label: 'One' })

		byLabel(wrapper, 'NcSelect', 'Target schema').vm.$emit('update:modelValue', null)
		byLabel(wrapper, 'NcSelect', 'Cardinality').vm.$emit('update:modelValue', { value: 'many' })
		byLabel(wrapper, 'NcSelect', 'Cardinality').vm.$emit('update:modelValue', null)
		byLabel(wrapper, 'NcTextField', 'Inverse-of property (optional)').vm.$emit('update:modelValue', 'cases')

		expect(emitted(wrapper, 0)[3].validation).toEqual({})
		expect(emitted(wrapper, 1)[3].validation).toEqual({ target: 'person', cardinality: 'many' })
		expect(emitted(wrapper, 2)[3].validation).toEqual({ target: 'person', cardinality: 'one' })
		expect(emitted(wrapper, 3)[3].validation).toEqual({ target: 'person', inverseOf: 'cases' })
	})

	it('an unknown target schema selects nothing', () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [{ ...fields()[3], validation: { target: 'gone' } }], schemaSlugs: ['person'] },
			stubs,
			mocks: { t: (app, text) => text },
		})

		expect(byLabel(wrapper, 'NcSelect', 'Target schema').props('modelValue')).toBeNull()
	})

	it('remove asks first, names the field, and removes it on confirm', async () => {
		const wrapper = mountEditor()
		const dialog = wrapper.findComponent({ name: 'DeleteFieldDialog' })

		wrapper.findAllComponents({ name: 'NcButton' }).filter((b) => b.text() === 'Remove field').at(2).vm.$emit('click')
		await wrapper.vm.$nextTick()

		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('fieldName')).toBe('tags')
		expect(wrapper.emitted('update:fields')).toBeUndefined()

		dialog.vm.$emit('confirm')
		await wrapper.vm.$nextTick()

		expect(emitted(wrapper, 0).map((f) => f.name)).toEqual(['title', 'count', 'owner'])
		expect(dialog.props('open')).toBe(false)
	})

	it('cancel and closing the dialog remove nothing; a stray confirm only closes', async () => {
		const wrapper = mountEditor()
		const dialog = wrapper.findComponent({ name: 'DeleteFieldDialog' })

		wrapper.vm.requestRemove(0)
		dialog.vm.$emit('cancel')
		await wrapper.vm.$nextTick()
		expect(dialog.props('open')).toBe(false)

		wrapper.vm.requestRemove(1)
		dialog.vm.$emit('update:open', false)
		await wrapper.vm.$nextTick()
		expect(dialog.props('open')).toBe(false)

		wrapper.vm.cancelRemove()
		dialog.vm.$emit('confirm')
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:fields')).toBeUndefined()
		expect(dialog.props('open')).toBe(false)
	})

	it('a nameless field is offered for removal as (unnamed)', async () => {
		const wrapper = mount(FieldEditor, {
			propsData: { fields: [{ ...fields()[0], name: '' }] },
			stubs,
			mocks: { t: (app, text) => text },
		})

		wrapper.vm.requestRemove(0)
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent({ name: 'DeleteFieldDialog' }).props('fieldName')).toBe('(unnamed)')
	})
})
