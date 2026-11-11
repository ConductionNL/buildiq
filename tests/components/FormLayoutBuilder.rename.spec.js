/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning FormLayoutBuilder.vue's `renameSection`: the reference
 * follows the heading while it is still the derived one (or the section had
 * no heading), stays put once set by hand, falls back to "sectie", and stays
 * unique.
 *
 * Spec: registration-form-builder (REQ-OBRF-008).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormLayoutBuilder from '../../src/components/page-editor/fields/FormLayoutBuilder.vue'

function rename(sections, fields, index, label) {
	const wrapper = mount(FormLayoutBuilder, { props: { sections, fields, properties: [] } })
	wrapper.vm.renameSection(index, label)
	const s = wrapper.emitted('update:sections')
	const f = wrapper.emitted('update:fields')
	return { sections: s[s.length - 1][0], fields: f ? f[f.length - 1][0] : null, emittedFields: !!f }
}

const field = (name, section) => ({ name, label: name, section, order: 1 })

describe('FormLayoutBuilder renameSection', () => {
	it('moves a derived reference and its fields along with the heading', () => {
		const out = rename(
			[{ name: 'uw-gegevens', label: 'Uw gegevens', order: 1 }],
			[field('naam', 'uw-gegevens')],
			0,
			'Uw adres',
		)
		expect(out.sections[0]).toMatchObject({ name: 'uw-adres', label: 'Uw adres' })
		expect(out.fields[0].section).toBe('uw-adres')
	})

	it('keeps a reference set by hand', () => {
		const out = rename([{ name: 'eigen', label: 'Uw gegevens', order: 1 }], [field('naam', 'eigen')], 0, 'Iets anders')
		expect(out.sections[0]).toMatchObject({ name: 'eigen', label: 'Iets anders' })
		expect(out.emittedFields).toBe(false)
	})

	it('names a section without a heading after its first one', () => {
		const out = rename([{ name: 'whatever', label: '', order: 1 }], [], 0, 'Nieuw')
		expect(out.sections[0].name).toBe('nieuw')
	})

	it('falls back to "sectie" for a heading with no letters', () => {
		const out = rename([{ name: 'oud', label: 'Oud', order: 1 }], [], 0, '!!!')
		expect(out.sections[0].name).toMatch(/^sectie/)
	})

	it('keeps the reference unique against the other sections', () => {
		const out = rename(
			[
				{ name: 'plan', label: 'Plan', order: 1 },
				{ name: 'oud', label: 'Oud', order: 2 },
			],
			[],
			1,
			'Plan',
		)
		expect(out.sections[1].name).not.toBe('plan')
		expect(out.sections[0].name).toBe('plan')
	})

	it('treats a missing section as one without a heading', () => {
		const out = rename([{ name: 'a', label: 'A', order: 1 }], [], 3, 'Extra')
		expect(out.sections.some((s) => s.label === 'Extra' && s.name === 'extra')).toBe(true)
	})
})
