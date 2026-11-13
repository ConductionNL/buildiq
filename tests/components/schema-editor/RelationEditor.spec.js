/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `RelationEditor.vue` and its block helpers.
 *
 * The component is controlled: `relations` is a prop and every edit emits
 * the next array on `update:relations`. Edits are driven through the
 * stubbed child fields so the template's own handlers are exercised.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RelationEditor, {
	editorToRelations,
	relationsToEditor,
} from '../../../src/components/schema-editor/RelationEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'ariaLabel'],
		emits: ['click'],
		template: '<button :aria-label="ariaLabel" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'clearable', 'label', 'trackBy'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
	PlusIcon: true,
	DeleteIcon: true,
}

const row = (overrides = {}) => ({ _key: 'r1', name: 'owner', target: 'person', cardinality: 'many', inverseOf: '', ...overrides })

const mountWith = (props) => mount(RelationEditor, { props, global: { stubs } })

function lastEmit (wrapper) {
	const events = wrapper.emitted('update:relations')
	return events[events.length - 1][0]
}

describe('RelationEditor', () => {
	it('shows the empty message and no rows without relations', () => {
		const wrapper = mountWith({ relations: [], schemaSlugs: ['person'] })

		expect(wrapper.find('.buildiq-relation-editor__empty').text()).toBe('No relations yet.')
		expect(wrapper.find('.buildiq-relation-editor__rows').exists()).toBe(false)
	})

	it('adds a blank relation targeting the first schema', async () => {
		const wrapper = mountWith({ relations: [row()], schemaSlugs: ['person', 'company'] })

		await wrapper.findAll('button')[0].trigger('click')

		const next = lastEmit(wrapper)
		expect(next).toHaveLength(2)
		expect(next[0]).toEqual(row())
		expect(next[1]).toMatchObject({ name: '', target: 'person', cardinality: 'one', inverseOf: '' })
		expect(next[1]._key).toMatch(/^rel-\d+$/)
	})

	it('adds a relation with an empty target when no schemas exist', () => {
		const wrapper = mountWith({ relations: [], schemaSlugs: [] })

		wrapper.vm.addRelation()

		expect(lastEmit(wrapper)[0].target).toBe('')
	})

	it('updates the name and inverse-of fields of one row without mutating the prop', async () => {
		const relations = [row(), row({ _key: 'r2', name: 'members' })]
		const wrapper = mountWith({ relations, schemaSlugs: ['person'] })
		const inputs = wrapper.findAll('input')

		await inputs[0].setValue('manager')
		expect(lastEmit(wrapper)[0]).toEqual(row({ name: 'manager' }))
		expect(lastEmit(wrapper)[1]).toEqual(row({ _key: 'r2', name: 'members' }))

		await inputs[1].setValue('managedBy')
		expect(lastEmit(wrapper)[0]).toEqual(row({ inverseOf: 'managedBy' }))
		expect(relations[0].name).toBe('owner')
	})

	it('maps a picked target and cardinality option to its value, with fallbacks on clear', () => {
		const wrapper = mountWith({ relations: [row()], schemaSlugs: ['person', 'company'] })
		const [target, cardinality] = wrapper.findAllComponents({ name: 'NcSelect' })

		target.vm.$emit('update:modelValue', { value: 'company', label: 'company' })
		expect(lastEmit(wrapper)[0].target).toBe('company')
		target.vm.$emit('update:modelValue', null)
		expect(lastEmit(wrapper)[0].target).toBe('')

		cardinality.vm.$emit('update:modelValue', { value: 'one', label: 'One' })
		expect(lastEmit(wrapper)[0].cardinality).toBe('one')
		cardinality.vm.$emit('update:modelValue', null)
		expect(lastEmit(wrapper)[0].cardinality).toBe('one')
	})

	it('selects the matching options and falls back to the first cardinality', () => {
		const wrapper = mountWith({ relations: [row()], schemaSlugs: ['person'] })
		const [target, cardinality] = wrapper.findAllComponents({ name: 'NcSelect' })

		expect(target.props('modelValue')).toEqual({ value: 'person', label: 'person' })
		expect(target.props('options')).toEqual([{ value: 'person', label: 'person' }])
		expect(cardinality.props('modelValue')).toEqual({ value: 'many', label: 'Many' })
		expect(cardinality.props('options')).toEqual([{ value: 'one', label: 'One' }, { value: 'many', label: 'Many' }])
		expect(wrapper.vm.schemaOption('unknown')).toBeNull()
		expect(wrapper.vm.cardinalityOption('several')).toEqual({ value: 'one', label: 'One' })
	})

	it('removes the clicked row', async () => {
		const wrapper = mountWith({ relations: [row(), row({ _key: 'r2', name: 'members' })], schemaSlugs: ['person'] })

		await wrapper.find('button[aria-label="Remove relation"]').trigger('click')

		expect(lastEmit(wrapper)).toEqual([row({ _key: 'r2', name: 'members' })])
	})
})

describe('relationsToEditor', () => {
	it('returns no rows for a block that is not an array', () => {
		expect(relationsToEditor(null)).toEqual([])
		expect(relationsToEditor({ name: 'x' })).toEqual([])
	})

	it('fills defaults and reads the snake_case inverse', () => {
		const rows = relationsToEditor([
			{ name: 'owner', target: 'person', cardinality: 'many', inverseOf: 'owns' },
			{ inverse_of: 'children' },
		])

		expect(rows.map(({ _key, ...rest }) => rest)).toEqual([
			{ name: 'owner', target: 'person', cardinality: 'many', inverseOf: 'owns' },
			{ name: '', target: '', cardinality: 'one', inverseOf: 'children' },
		])
		expect(rows[0]._key).not.toBe(rows[1]._key)
	})
})

describe('editorToRelations', () => {
	it('serialises nothing for no rows', () => {
		expect(editorToRelations(null)).toBeNull()
		expect(editorToRelations([])).toBeNull()
	})

	it('drops incomplete rows and the editor key, keeping inverseOf only when set', () => {
		expect(
			editorToRelations([
				row({ inverseOf: 'owns' }),
				row({ _key: 'r2', name: 'members', cardinality: 'one' }),
				row({ _key: 'r3', name: '' }),
				row({ _key: 'r4', target: '' }),
			]),
		).toEqual([
			{ name: 'owner', target: 'person', cardinality: 'many', inverseOf: 'owns' },
			{ name: 'members', target: 'person', cardinality: 'one' },
		])
	})

	it('round-trips a block through the editor', () => {
		const block = [
			{ name: 'owner', target: 'person', cardinality: 'many', inverseOf: 'owns' },
			{ name: 'parent', target: 'node', cardinality: 'one' },
		]

		expect(editorToRelations(relationsToEditor(block))).toEqual(block)
	})
})
