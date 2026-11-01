/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormLayoutBuilder.vue.
 *
 * What these assert is the thing the served form depends on: the form carries
 * the order, and the order it carries is the one on screen. A builder that
 * looked right and wrote nothing would serve the schema's property order, which
 * reshuffles the day somebody adds a property.
 *
 * Spec: registration-form-builder (REQ-OBRF-008).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormLayoutBuilder from '../../src/components/page-editor/fields/FormLayoutBuilder.vue'

const SECTIONS = [
	{ name: 'uw-gegevens', label: 'Uw gegevens', order: 1 },
	{ name: 'uw-plan', label: 'Uw plan', order: 2 },
]

const FIELDS = [
	{ name: 'naam', label: 'Naam', section: 'uw-gegevens', order: 1 },
	{ name: 'bsn', label: 'BSN', section: 'uw-gegevens', order: 2 },
	{ name: 'adres', label: 'Adres', section: 'uw-gegevens', order: 3 },
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
		props: {
			sections: SECTIONS,
			fields: FIELDS,
			properties: ['naam', 'bsn', 'adres', 'toelichting', 'intakeChannel'],
			...props,
		},
	})
}

/**
 * The last `update:fields` payload.
 *
 * @param {object} wrapper - the mounted builder.
 * @return {Array<object>} The emitted fields.
 */
function lastFields(wrapper) {
	const emitted = wrapper.emitted('update:fields')
	return emitted[emitted.length - 1][0]
}

describe('FormLayoutBuilder', () => {
	it('groups the fields under the sections the form declares', () => {
		const wrapper = mountBuilder()
		const headings = wrapper
			.findAll('.form-layout__group-title')
			.map((node) => node.text())

		expect(headings).toEqual(['Outside every section', 'Uw gegevens', 'Uw plan'])
	})

	it('moving a field down rewrites the order inside its own section', async () => {
		const wrapper = mountBuilder()

		// The second field of "Uw gegevens", moved down one place.
		await wrapper
			.findAll('[aria-label="Move field down"]')
			.at(1)
			.trigger('click')

		const fields = lastFields(wrapper)
		const order = Object.fromEntries(
			fields.map((field) => [field.name, field.order]),
		)

		expect(order).toEqual({ naam: 1, bsn: 3, adres: 2, toelichting: 1 })
	})

	it('moving a field never disturbs another section', async () => {
		const wrapper = mountBuilder()

		await wrapper
			.findAll('[aria-label="Move field down"]')
			.at(0)
			.trigger('click')

		const moved = lastFields(wrapper).find(
			(field) => field.name === 'toelichting',
		)

		expect(moved).toEqual(FIELDS[3])
	})

	it('assigning a field to another section puts it at the end of it', async () => {
		const wrapper = mountBuilder()
		const pickers = wrapper.findAll('select')

		// The "Section" picker of the first field of "Uw gegevens". Each field
		// row carries a property picker, a type picker and a section picker.
		const sectionPicker = pickers.at(2)
		await sectionPicker.setValue('uw-plan')

		const fields = lastFields(wrapper)
		const moved = fields.find((field) => field.name === 'naam')

		expect(moved.section).toBe('uw-plan')
		expect(moved.order).toBe(2)
	})

	it('a section that still holds fields cannot be deleted', async () => {
		const wrapper = mountBuilder()
		const remove = wrapper.findAll('[aria-label="Delete section"]').at(0)

		expect(remove.attributes('disabled')).toBeDefined()
		expect(remove.attributes('title')).toContain('somewhere else')

		await remove.trigger('click')

		expect(wrapper.emitted('update:sections')).toBeUndefined()
	})

	it('an empty section can be deleted, and the rest are renumbered', async () => {
		const wrapper = mountBuilder({ fields: [] })

		await wrapper.findAll('[aria-label="Delete section"]').at(0).trigger('click')

		const emitted = wrapper.emitted('update:sections')
		expect(emitted[emitted.length - 1][0]).toEqual([
			{ name: 'uw-plan', label: 'Uw plan', order: 1 },
		])
	})

	it('renaming a section carries its fields with it', async () => {
		const wrapper = mountBuilder()

		await wrapper.findAll('input[type="text"]').at(0).setValue('Uw adres')

		const carried = lastFields(wrapper).filter(
			(field) => field.section === 'uw-adres',
		)

		expect(carried).toHaveLength(3)
	})

	it('offers the target schema properties as a picker, and free text without them', () => {
		const withSchema = mountBuilder()
		expect(withSchema.findAll('option').map((o) => o.text())).toContain(
			'intakeChannel',
		)

		const withoutSchema = mountBuilder({ properties: null })
		const inputs = withoutSchema.findAll('input[type="text"]')

		// Two section rows plus a property and a label input per field.
		expect(inputs.length).toBeGreaterThan(SECTIONS.length * 2)
	})

	it('a new field lands at the end of the unsectioned group', async () => {
		const wrapper = mountBuilder()

		await wrapper.findAll('button').at(-1).trigger('click')

		const added = lastFields(wrapper).at(-1)
		expect(added.order).toBe(1)
		expect(added.section).toBeUndefined()
	})
})
