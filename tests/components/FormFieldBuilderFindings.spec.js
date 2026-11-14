/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormFieldBuilder.vue: a refused save lands its finding on
 * the field it names (decision 179/189).
 *
 * Spec: openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../src/components/page-editor/fields/FormFieldBuilder.vue'
import { findingsByField } from '../../src/services/formDestinationCheck.js'

describe('FormFieldBuilder findings', () => {
	it('shows a 422 finding under the field it names and nowhere else', () => {
		// The shape the destination check answers with.
		const findings = [
			{
				page: 'p1',
				pageIndex: 0,
				field: 'email',
				property: 'email',
				code: 'type-mismatch',
				message: 'Email must be a string.',
			},
		]
		const wrapper = mount(FormFieldBuilder, {
			props: {
				modelValue: [
					{ key: 'name', type: 'text' },
					{ key: 'email', type: 'text' },
				],
				findings: findingsByField(findings),
			},
		})

		const marks = wrapper.findAll('.form-field-builder__finding')
		expect(marks).toHaveLength(1)
		expect(marks[0].text()).toContain('Email must be a string.')
		// It sits in the email row (the second remove button's row), not the name row.
		const removes = wrapper.findAll('.form-field-builder__remove')
		expect(removes).toHaveLength(2)
		expect(removes[0].element.parentElement.contains(marks[0].element)).toBe(
			false,
		)
		expect(removes[1].element.parentElement.contains(marks[0].element)).toBe(
			true,
		)
	})
})
