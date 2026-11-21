/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the relation pickers of FieldEditor and RelationEditor: the target
 * schema and cardinality options, and the option each stored value selects
 * (no schema option for an unknown slug, the first cardinality otherwise).
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldEditor from '../../../src/components/schema-editor/FieldEditor.vue'
import RelationEditor from '../../../src/components/schema-editor/RelationEditor.vue'

describe.each([
	['FieldEditor', FieldEditor, { fields: [] }],
	['RelationEditor', RelationEditor, { relations: [] }],
])('%s relation pickers', (_name, Editor, props) => {
	const wrapper = shallowMount(Editor, {
		props: { ...props, schemaSlugs: ['person', 'case'] },
		global: { mocks: { t: (_app, text) => `t:${text}` } },
	})

	it('offers the schema slugs and the two cardinalities', () => {
		expect(wrapper.vm.schemaOptions).toEqual([
			{ value: 'person', label: 'person' },
			{ value: 'case', label: 'case' },
		])
		expect(wrapper.vm.cardinalityOptions).toEqual([
			{ value: 'one', label: 't:One' },
			{ value: 'many', label: 't:Many' },
		])
	})

	it('selects the option of a stored value', () => {
		expect(wrapper.vm.schemaOption('case')).toEqual({ value: 'case', label: 'case' })
		expect(wrapper.vm.schemaOption('nope')).toBe(null)
		expect(wrapper.vm.cardinalityOption('many')).toEqual({ value: 'many', label: 't:Many' })
		expect(wrapper.vm.cardinalityOption(undefined)).toEqual({ value: 'one', label: 't:One' })
	})
})
