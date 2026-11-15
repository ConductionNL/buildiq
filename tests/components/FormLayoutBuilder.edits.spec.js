/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormLayoutBuilder.vue: the edits not covered by the grouping
 * and rename specs — adding and moving sections, typing a reference by hand,
 * writing and clearing field properties, moving a field out of every section,
 * removing one, and the per-field destination findings.
 *
 * Spec: registration-form-builder (REQ-OBRF-008).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormLayoutBuilder from '../../src/components/page-editor/fields/FormLayoutBuilder.vue'
import InlineFieldMark from '../../src/components/page-editor/fields/InlineFieldMark.vue'

const SECTIONS = [
	{ name: 'uw-gegevens', label: 'Uw gegevens', order: 1 },
	{ name: 'uw-plan', label: 'Uw plan', order: 2 },
]

const FIELDS = [
	{ name: 'naam', label: 'Naam', section: 'uw-gegevens', order: 1, required: true },
	{ name: 'bsn', label: 'BSN', section: 'uw-gegevens', order: 2 },
	{ name: 'toelichting', label: 'Toelichting', section: 'uw-plan', order: 1 },
]

/**
 * Mount the builder over a layout.
 *
 * @param {object} props - props to override.
 * @return {object} The mounted wrapper.
 */
function mountBuilder(props = {}) {
	return mount(FormLayoutBuilder, {
		props: { sections: SECTIONS, fields: FIELDS, ...props },
	})
}

/**
 * The last payload of an event.
 *
 * @param {object} wrapper - the mounted builder.
 * @param {string} event - the event name.
 * @return {*} The payload.
 */
function last(wrapper, event) {
	const emitted = wrapper.emitted(event)
	return emitted[emitted.length - 1][0]
}

/**
 * The field rows in the order they render.
 *
 * @param {object} wrapper - the mounted builder.
 * @return {Array<object>} The rows of the Fields fieldset.
 */
function fieldRows(wrapper) {
	return wrapper.findAll('.form-layout__group .form-layout__row')
}

/**
 * The section rows.
 *
 * @param {object} wrapper - the mounted builder.
 * @return {Array<object>} The rows of the Sections fieldset.
 */
function sectionRows(wrapper) {
	return wrapper.findAll('fieldset').at(0).findAll('.form-layout__row')
}

describe('FormLayoutBuilder edits', () => {
	it('says there are no sections yet, and adds the first one as "sectie"', async () => {
		const wrapper = mountBuilder({ sections: [], fields: [] })

		expect(wrapper.text()).toContain('No sections yet.')
		const add = wrapper.findAll('button').find((b) => b.text() === 'Add section')
		await add.trigger('click')

		expect(last(wrapper, 'update:sections')).toEqual([
			{ name: 'sectie', label: '', order: 1 },
		])
	})

	it('numbers a new section past the names already taken', async () => {
		const wrapper = mountBuilder({
			sections: [
				{ name: 'sectie', label: '', order: 1 },
				{ name: 'sectie-2', label: '', order: 2 },
			],
			fields: [],
		})

		await wrapper.findAll('button').find((b) => b.text() === 'Add section').trigger('click')

		expect(last(wrapper, 'update:sections').map((s) => [s.name, s.order])).toEqual([
			['sectie', 1],
			['sectie-2', 2],
			['sectie-3', 3],
		])
	})

	it('typing a reference by hand kebab-cases it and carries the fields along', async () => {
		const wrapper = mountBuilder()

		const reference = sectionRows(wrapper).at(1).findAll('input').at(1)
		await reference.setValue('Het Plan')

		expect(last(wrapper, 'update:sections')[1]).toEqual({ name: 'het-plan', label: 'Uw plan', order: 2 })
		expect(last(wrapper, 'update:fields').map((f) => f.section)).toEqual([
			'uw-gegevens',
			'uw-gegevens',
			'het-plan',
		])
	})

	it('moves a section up and down, renumbering, and the end buttons are disabled', async () => {
		const wrapper = mountBuilder()
		const first = sectionRows(wrapper).at(0).findAll('button')
		const second = sectionRows(wrapper).at(1).findAll('button')

		expect(first.at(0).attributes('disabled')).toBeDefined()
		expect(second.at(1).attributes('disabled')).toBeDefined()

		await first.at(1).trigger('click')
		expect(last(wrapper, 'update:sections').map((s) => [s.name, s.order])).toEqual([
			['uw-plan', 1],
			['uw-gegevens', 2],
		])

		await second.at(0).trigger('click')
		expect(wrapper.emitted('update:sections')).toHaveLength(2)
		expect(last(wrapper, 'update:sections')[0].name).toBe('uw-plan')
	})

	it('ignores a move past either end', () => {
		const wrapper = mountBuilder()

		wrapper.vm.moveSection(0, -1)
		wrapper.vm.moveSection(1, 1)

		expect(wrapper.emitted('update:sections')).toBeUndefined()
	})

	it('names why a full section cannot be deleted, and refuses it even when called', () => {
		const wrapper = mountBuilder({
			sections: [...SECTIONS, { name: 'leeg', label: 'Leeg', order: 3 }],
		})
		const deletes = sectionRows(wrapper).map((row) => row.findAll('button').at(2))

		expect(deletes[0].attributes('title')).toBe(
			'Move the fields in this section somewhere else before deleting it.',
		)
		expect(deletes[2].attributes('title')).toBe('Delete section')
		expect(deletes[2].attributes('disabled')).toBeUndefined()

		wrapper.vm.removeSection(0)
		expect(wrapper.emitted('update:sections')).toBeUndefined()
	})

	it('writes a label and a type onto one field', async () => {
		const wrapper = mountBuilder()
		const row = fieldRows(wrapper).at(1)

		await row.findAll('input').at(1).setValue('Burgerservicenummer')
		expect(last(wrapper, 'update:fields')[1]).toEqual({
			name: 'bsn',
			label: 'Burgerservicenummer',
			section: 'uw-gegevens',
			order: 2,
		})

		await row.findAll('select').at(0).setValue('number')
		expect(last(wrapper, 'update:fields')[1].type).toBe('number')
	})

	it('a free-text property is used when the schema could not be read', async () => {
		const wrapper = mountBuilder()
		const input = fieldRows(wrapper).at(0).find('input[type="text"]')

		expect(input.element.value).toBe('naam')
		await input.setValue('voornaam')

		expect(last(wrapper, 'update:fields')[0].name).toBe('voornaam')
	})

	it('clearing a value drops the key, except the name which stays an empty string', async () => {
		const wrapper = mountBuilder()
		const row = fieldRows(wrapper).at(0)

		await row.find('input[type="checkbox"]').setValue(false)
		expect(last(wrapper, 'update:fields')[0]).toEqual({
			name: 'naam',
			label: 'Naam',
			section: 'uw-gegevens',
			order: 1,
		})

		await row.find('input[type="text"]').setValue('')
		expect(last(wrapper, 'update:fields')[0].name).toBe('')

		await row.findAll('input').at(1).setValue('')
		expect(last(wrapper, 'update:fields')[0]).not.toHaveProperty('label')
	})

	it('ticking "must be answered" stores required', async () => {
		const wrapper = mountBuilder()

		await fieldRows(wrapper).at(1).find('input[type="checkbox"]').setValue(true)

		expect(last(wrapper, 'update:fields')[1].required).toBe(true)
	})

	it('moving a field out of every section drops its section and puts it last there', async () => {
		const wrapper = mountBuilder({
			fields: [...FIELDS, { name: 'kanaal', label: 'Kanaal', order: 1 }],
		})
		// The unsectioned group renders first.
		const naamRow = fieldRows(wrapper).at(1)

		await naamRow.findAll('select').at(1).setValue('')

		const fields = last(wrapper, 'update:fields')
		expect(fields[0]).not.toHaveProperty('section')
		expect(fields[0].order).toBe(2)
		expect(fields[3].order).toBe(1)
		expect(fields[1].order).toBe(1)
	})

	it('moves a field up inside its section and refuses a move past the edge', async () => {
		const wrapper = mountBuilder()
		const bsnButtons = fieldRows(wrapper).at(1).findAll('button')

		await bsnButtons.at(0).trigger('click')
		expect(last(wrapper, 'update:fields').map((f) => [f.name, f.order])).toEqual([
			['naam', 2],
			['bsn', 1],
			['toelichting', 1],
		])

		const group = wrapper.vm.groups[1]
		wrapper.vm.moveField(group, 0, -1)
		wrapper.vm.moveField(group, 1, 1)
		expect(wrapper.emitted('update:fields')).toHaveLength(1)
	})

	it('removes a field and renumbers what is left of its section', async () => {
		const wrapper = mountBuilder()

		await fieldRows(wrapper).at(0).findAll('button').at(2).trigger('click')

		expect(last(wrapper, 'update:fields')).toEqual([
			{ name: 'bsn', label: 'BSN', section: 'uw-gegevens', order: 1 },
			{ name: 'toelichting', label: 'Toelichting', section: 'uw-plan', order: 1 },
		])
	})

	it('a field naming an undeclared section sits outside every section', () => {
		const wrapper = mountBuilder({
			fields: [{ name: 'zwerver', section: 'weg', order: 1 }, null],
		})

		const outside = wrapper.vm.groups[0]
		expect(outside.label).toBe('Outside every section')
		expect(outside.fields.map((entry) => entry.index)).toEqual([0, 1])
		expect(outside.fields[1].field).toEqual({})
		expect(wrapper.vm.groups[1].fields).toEqual([])
		expect(wrapper.text()).toContain('Nothing here yet.')
	})

	it('shows each destination finding on its own field', () => {
		const wrapper = mountBuilder({
			findings: { bsn: [{ message: 'BSN is not in the schema' }, { message: 'BSN is required there' }] },
		})

		const marks = fieldRows(wrapper).at(1).findAllComponents(InlineFieldMark)
		expect(marks).toHaveLength(2)
		expect(marks.at(0).props('error')).toEqual({ hasError: true, message: 'BSN is not in the schema' })
		expect(fieldRows(wrapper).at(0).findAllComponents(InlineFieldMark)).toHaveLength(0)
	})
})
