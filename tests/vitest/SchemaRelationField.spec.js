/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for relation fields in the schema designer
 * (capability-requirements-backfill T03, REQ-BQCV-003): a maker gives a
 * field the relation type, picks the target schema and the cardinality; the
 * saved property carries `x-openregister-relation` with that target and
 * cardinality, a many relation is stored as a list, and the Relations section
 * lists every relation field with its target and cardinality.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-schema-designer/spec.md#requirement-a-maker-links-one-object-type-to-another-req-bqcv-003
 */
import { mount, shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldEditor, { fieldsToSchema, schemaToFields } from '../../src/components/schema-editor/FieldEditor.vue'
import RelationEditor, { relationsFromFields } from '../../src/components/schema-editor/RelationEditor.vue'

const t = (app, text) => text

/**
 * Mount the field editor with one new row, make it a relation through the
 * editor's own handlers, and return the rows it emits.
 *
 * @param {string} name The field name.
 * @param {string} target The target schema.
 * @param {string} cardinality `one` or `many`.
 * @return {Promise<Array<object>>} The rows.
 */
async function relationField(name, target, cardinality) {
	const wrapper = shallowMount(FieldEditor, {
		props: { fields: [], schemaSlugs: ['person', 'request', 'project'] },
		global: { mocks: { t } },
	})
	const apply = async (fn) => {
		fn(wrapper.vm)
		await wrapper.setProps({ fields: wrapper.emitted('update:fields').at(-1)[0] })
	}
	await apply((vm) => vm.addField())
	await apply((vm) => vm.updateField(0, 'name', name))
	await apply((vm) => vm.updateField(0, 'type', 'relation'))
	await apply((vm) => vm.updateValidation(0, 'target', target))
	await apply((vm) => vm.updateValidation(0, 'cardinality', cardinality))
	return wrapper.props('fields')
}

describe('REQ-BQCV-003 relation fields', () => {
	it('a request points at the person who made it: applicant is a relation to person with cardinality one', async () => {
		const fields = await relationField('applicant', 'person', 'one')
		const saved = fieldsToSchema(fields, 'request').properties.applicant

		expect(saved.type).toBe('string')
		expect(saved).not.toHaveProperty('items')
		expect(saved['x-openregister-relation']).toEqual({ target: 'person', cardinality: 'one' })
	})

	it('the Relations section lists applicant with target person', async () => {
		const fields = await relationField('applicant', 'person', 'one')
		expect(relationsFromFields(fields)).toEqual([{ name: 'applicant', target: 'person', cardinality: 'one' }])

		const section = mount(RelationEditor, {
			props: { relations: [], fieldRelations: relationsFromFields(fields), schemaSlugs: ['person'] },
			global: { mocks: { t } },
		})
		const rows = section.findAll('[data-testid="relation-editor-field-relation"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('applicant')
		expect(rows[0].text()).toContain('person')
		expect(rows[0].text()).toContain('One')
		expect(section.text()).not.toContain('No relations yet.')
	})

	it('a many relation keeps a list: members is stored as a list of person references', async () => {
		const fields = await relationField('members', 'person', 'many')
		const saved = fieldsToSchema(fields, 'project').properties.members

		expect(saved.type).toBe('array')
		expect(saved.items).toEqual({ type: 'string' })
		expect(saved['x-openregister-relation']).toEqual({ target: 'person', cardinality: 'many' })
		expect(relationsFromFields(fields)).toEqual([{ name: 'members', target: 'person', cardinality: 'many' }])
	})

	it('a saved many relation loads back as a relation row and saves the same', () => {
		const stored = {
			properties: {
				members: {
					type: 'array',
					items: { type: 'string' },
					'x-openregister-relation': { target: 'person', cardinality: 'many' },
				},
			},
		}
		const fields = schemaToFields(stored)
		expect(fields[0].type).toBe('relation')
		expect(fields[0].validation).toMatchObject({ target: 'person', cardinality: 'many' })
		expect(fieldsToSchema(fields).properties.members).toEqual(stored.properties.members)
	})

	it('turning a many relation back into one stores a single reference again', () => {
		const fields = schemaToFields({
			properties: {
				members: { type: 'array', items: { type: 'string' }, 'x-openregister-relation': { target: 'person', cardinality: 'many' } },
			},
		})
		fields[0].validation = { ...fields[0].validation, cardinality: 'one' }
		const saved = fieldsToSchema(fields).properties.members
		expect(saved.type).toBe('string')
		expect(saved).not.toHaveProperty('items')
	})

	it('the Relations section shows no field rows when the schema has no relation field', () => {
		expect(relationsFromFields([{ name: 'title', type: 'text', validation: {} }])).toEqual([])
		const section = mount(RelationEditor, { props: { relations: [] }, global: { mocks: { t } } })
		expect(section.find('[data-testid="relation-editor-field-relations"]').exists()).toBe(false)
		expect(section.text()).toContain('No relations yet.')
	})
})
