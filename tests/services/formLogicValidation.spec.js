/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side manifest-form-logic validation (REQ-OBFEL-005),
 * plus the save-time `assignUnassignedFieldsToFinalStep` normalisation
 * helper (REQ-OBFEL-001).
 */
import { describe, expect, it } from 'vitest'
import {
	assignUnassignedFieldsToFinalStep,
	formMode,
	isSmartPasteFillable,
	validateFormLogic,
	VISIBLE_WHEN_OPS,
} from '../../src/services/manifestValidation/formLogic.js'

function withFormPage(config) {
	return { pages: [{ type: 'form', config }] }
}

describe('validateFormLogic', () => {
	it('passes a clean manifest with well-formed steps, conditions and validation', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'wantsContact', label: 'x', type: 'boolean' },
				{
					key: 'email',
					label: 'x',
					type: 'string',
					visibleWhen: { field: 'wantsContact', value: true },
					validation: {
						required: true,
						min: 5,
						max: 254,
						pattern: '^[^@]+@[^@]+$',
					},
				},
			],
			steps: [
				{
					id: 'contact',
					title: 'Contact',
					fields: ['wantsContact', 'email'],
				},
			],
		})
		expect(validateFormLogic(manifest)).toEqual([])
	})

	it('passes a manifest with no form pages at all', () => {
		expect(
			validateFormLogic({ pages: [{ type: 'index', config: {} }] }),
		).toEqual([])
		expect(validateFormLogic({})).toEqual([])
	})

	it('reports a dangling step field reference and a duplicate assignment as two errors', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'string' },
				{ key: 'b', type: 'string' },
			],
			steps: [
				{ id: 's1', title: 'S1', fields: ['a', 'ghost'] },
				{ id: 's2', title: 'S2', fields: ['a', 'b'] },
			],
		})
		const errors = validateFormLogic(manifest)
		expect(errors.some((e) => e.includes('dangling-step-field'))).toBe(true)
		expect(errors.some((e) => e.includes('duplicate-field-assignment'))).toBe(
			true,
		)
		errors.forEach((e) =>
			expect(e.startsWith('/pages/0/config/steps')).toBe(true),
		)
	})

	it('reports an unknown condition field and an off-allow-list op as two errors', () => {
		const manifest = withFormPage({
			fields: [
				{
					key: 'email',
					type: 'string',
					visibleWhen: { field: 'ghost', op: 'contains', value: 'x' },
				},
			],
		})
		const errors = validateFormLogic(manifest)
		expect(errors.some((e) => e.includes('dangling-condition-field'))).toBe(true)
		expect(errors.some((e) => e.includes('condition-op-not-allowed'))).toBe(true)
	})

	it('reports min-greater-than-max and a non-compiling pattern as separate errors', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'number', validation: { min: 10, max: 3 } },
				{ key: 'b', type: 'string', validation: { pattern: '[a-' } },
			],
		})
		const errors = validateFormLogic(manifest)
		expect(
			errors.some((e) => e.includes('validation-min-greater-than-max')),
		).toBe(true)
		expect(
			errors.some((e) => e.includes('validation-pattern-does-not-compile')),
		).toBe(true)
	})

	it('reports a step without a non-empty title', () => {
		const manifest = withFormPage({
			fields: [{ key: 'a', type: 'string' }],
			steps: [{ id: 's1', title: '', fields: ['a'] }],
		})
		expect(
			validateFormLogic(manifest).some((e) =>
				e.includes('step-title-required'),
			),
		).toBe(true)
	})

	it('reports a step with a non-array fields', () => {
		const manifest = withFormPage({
			fields: [{ key: 'a', type: 'string' }],
			steps: [{ id: 's1', title: 'S1', fields: 'not-an-array' }],
		})
		expect(
			validateFormLogic(manifest).some((e) =>
				e.includes('step-fields-not-array'),
			),
		).toBe(true)
	})

	it('reports duplicate step ids', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'string' },
				{ key: 'b', type: 'string' },
			],
			steps: [
				{ id: 'dup', title: 'S1', fields: ['a'] },
				{ id: 'dup', title: 'S2', fields: ['b'] },
			],
		})
		expect(
			validateFormLogic(manifest).some((e) => e.includes('duplicate-step-id')),
		).toBe(true)
	})

	it('reports a warning-level entry for a field carrying both validation and legacy flat keys', () => {
		const manifest = withFormPage({
			fields: [
				{
					key: 'a',
					type: 'string',
					required: true,
					pattern: '^x$',
					validation: { required: true },
				},
			],
		})
		const errors = validateFormLogic(manifest)
		expect(
			errors.some((e) =>
				e.includes(
					'buildiq.formLogic.warning.flat-and-structured-validation',
				),
			),
		).toBe(true)
	})

	it('does not warn when a field has only a validation object (no legacy flat keys)', () => {
		const manifest = withFormPage({
			fields: [{ key: 'a', type: 'string', validation: { required: true } }],
		})
		expect(validateFormLogic(manifest).some((e) => e.includes('warning'))).toBe(
			false,
		)
	})

	it('exposes the visibleWhen op allow-list', () => {
		expect(VISIBLE_WHEN_OPS).toEqual(['eq', 'neq', 'gt', 'gte', 'lt', 'lte'])
	})
})

describe('assignUnassignedFieldsToFinalStep', () => {
	it('appends unassigned field keys to the final step', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'string' },
				{ key: 'b', type: 'string' },
				{ key: 'c', type: 'string' },
			],
			steps: [
				{ id: 's1', title: 'S1', fields: ['a'] },
				{ id: 's2', title: 'S2', fields: ['b'] },
			],
		})
		const next = assignUnassignedFieldsToFinalStep(manifest)
		expect(next.pages[0].config.steps[1].fields).toEqual(['b', 'c'])
		expect(next.pages[0].config.steps[0].fields).toEqual(['a'])
	})

	it('is a no-op when steps is absent', () => {
		const manifest = withFormPage({ fields: [{ key: 'a', type: 'string' }] })
		expect(assignUnassignedFieldsToFinalStep(manifest)).toBe(manifest)
	})

	it('is a no-op when every field is already assigned', () => {
		const manifest = withFormPage({
			fields: [{ key: 'a', type: 'string' }],
			steps: [{ id: 's1', title: 'S1', fields: ['a'] }],
		})
		expect(assignUnassignedFieldsToFinalStep(manifest)).toBe(manifest)
	})

	it('never mutates the input manifest', () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'string' },
				{ key: 'b', type: 'string' },
			],
			steps: [{ id: 's1', title: 'S1', fields: ['a'] }],
		})
		const snapshot = JSON.parse(JSON.stringify(manifest))
		assignUnassignedFieldsToFinalStep(manifest)
		expect(manifest).toEqual(snapshot)
	})

	it("the resulting manifest satisfies validateFormLogic's partition checks", () => {
		const manifest = withFormPage({
			fields: [
				{ key: 'a', type: 'string' },
				{ key: 'b', type: 'string' },
			],
			steps: [{ id: 's1', title: 'S1', fields: ['a'] }],
		})
		const next = assignUnassignedFieldsToFinalStep(manifest)
		expect(validateFormLogic(next)).toEqual([])
	})
})

// @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-smart-paste-is-validated-req-bqsp-002
describe('validateFormLogic: smart paste (REQ-BQSP-002)', () => {
	const fields = [
		{ key: 'naam', label: 'Naam', type: 'string' },
		{ key: 'adres', label: 'Adres', type: 'textarea' },
		{ key: 'telefoon', label: 'Telefoon', type: 'string' },
		{ key: 'bijlage', label: 'Bijlage', type: 'file' },
		{ key: 'contact', label: 'Contact', type: 'relation' },
	]

	it('passes smart paste on three fillable fields of a create form', () => {
		const manifest = withFormPage({
			mode: 'create',
			fields,
			smartPaste: { enabled: true, fields: ['naam', 'adres', 'telefoon'], hint: 'Paste an email' },
		})
		expect(validateFormLogic(manifest)).toEqual([])
	})

	it('names a field key the form does not have, so a removed field is caught', () => {
		const manifest = withFormPage({
			mode: 'create',
			fields: fields.filter((f) => f.key !== 'telefoon'),
			smartPaste: { enabled: true, fields: ['naam', 'telefoon'] },
		})
		expect(validateFormLogic(manifest)).toEqual([
			'/pages/0/config/smartPaste/fields/1: buildiq.formLogic.error.smart-paste-unknown-field(telefoon)',
		])
	})

	it('refuses a field of a type the fill cannot set', () => {
		const manifest = withFormPage({
			mode: 'edit',
			fields,
			smartPaste: { enabled: true, fields: ['naam', 'bijlage', 'contact'] },
		})
		expect(validateFormLogic(manifest)).toEqual([
			'/pages/0/config/smartPaste/fields/1: buildiq.formLogic.error.smart-paste-unfillable-type(bijlage)',
			'/pages/0/config/smartPaste/fields/2: buildiq.formLogic.error.smart-paste-unfillable-type(contact)',
		])
	})

	it('refuses smart paste switched on with no fields', () => {
		expect(
			validateFormLogic(withFormPage({ mode: 'create', fields, smartPaste: { enabled: true, fields: [] } })),
		).toEqual(['/pages/0/config/smartPaste/fields: buildiq.formLogic.error.smart-paste-no-fields'])
		expect(
			validateFormLogic(withFormPage({ mode: 'create', fields, smartPaste: { enabled: true } })),
		).toEqual(['/pages/0/config/smartPaste/fields: buildiq.formLogic.error.smart-paste-no-fields'])
	})

	it('refuses smart paste on a public form, and on a form without a mode, which renders public', () => {
		const expected = ['/pages/0/config/smartPaste/enabled: buildiq.formLogic.error.smart-paste-public-form']
		expect(
			validateFormLogic(withFormPage({ mode: 'public', fields, smartPaste: { enabled: true, fields: ['naam'] } })),
		).toEqual(expected)
		expect(
			validateFormLogic(withFormPage({ fields, smartPaste: { enabled: true, fields: ['naam'] } })),
		).toEqual(expected)
	})

	it('lets a switched-off setting keep its fields on a public form, but still checks them', () => {
		expect(
			validateFormLogic(withFormPage({ mode: 'public', fields, smartPaste: { enabled: false, fields: ['naam'] } })),
		).toEqual([])
		expect(
			validateFormLogic(withFormPage({ mode: 'public', fields, smartPaste: { enabled: false, fields: ['ghost'] } })),
		).toEqual(['/pages/0/config/smartPaste/fields/0: buildiq.formLogic.error.smart-paste-unknown-field(ghost)'])
	})

	it('refuses a malformed setting', () => {
		expect(validateFormLogic(withFormPage({ mode: 'create', fields, smartPaste: true }))).toEqual([
			'/pages/0/config/smartPaste: buildiq.formLogic.error.smart-paste-invalid-shape',
		])
		expect(
			validateFormLogic(withFormPage({ mode: 'create', fields, smartPaste: { enabled: false, fields: 'naam', hint: 3 } })),
		).toEqual([
			'/pages/0/config/smartPaste/hint: buildiq.formLogic.error.smart-paste-hint-not-string',
			'/pages/0/config/smartPaste/fields: buildiq.formLogic.error.smart-paste-fields-not-array',
		])
	})

	it('reads the mode and which fields are fillable', () => {
		expect(formMode({})).toBe('public')
		expect(formMode({ mode: 'edit' })).toBe('edit')
		expect(isSmartPasteFillable({ key: 'naam', type: 'string' })).toBe(true)
		expect(isSmartPasteFillable({ key: 'naam' })).toBe(true)
		expect(isSmartPasteFillable({ key: 'handtekening', type: 'signature' })).toBe(false)
		expect(isSmartPasteFillable('naam')).toBe(false)
	})
})
