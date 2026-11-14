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

describe('validateFormLogic device fields (forms-signature-audio-and-location, REQ-BQSA-002, REQ-BQSA-004)', () => {
	const form = (fields, mode) => ({ pages: [{ type: 'form', config: { ...(mode ? { mode } : {}), fields } }] })

	it('passes well-formed signature, audio and location fields on a create form', () => {
		expect(
			validateFormLogic(
				form(
					[
						{ key: 'handtekening', type: 'signature', allowTyped: false },
						{ key: 'spraaknotitie', type: 'audio', maxSeconds: 120 },
						{ key: 'plek', type: 'location', latField: 'lat', lngField: 'lng' },
					],
					'create',
				),
			),
		).toEqual([])
	})

	it('refuses audio and location on a public form, also when the mode is left at its public default', () => {
		const fields = [
			{ key: 'h', type: 'signature' },
			{ key: 's', type: 'audio' },
			{ key: 'p', type: 'location', latField: 'lat', lngField: 'lng' },
		]
		const expected = [
			'/pages/0/config/fields/1: buildiq.formLogic.error.device-field-on-public-form',
			'/pages/0/config/fields/2: buildiq.formLogic.error.device-field-on-public-form',
		]

		expect(validateFormLogic(form(fields, 'public'))).toEqual(expected)
		expect(validateFormLogic(form(fields))).toEqual(expected)
	})

	it('marks a signature without a mode, a bad audio length and an incomplete location', () => {
		expect(
			validateFormLogic(
				form(
					[
						{ key: 'h', type: 'signature', allowTyped: false, allowDrawn: false },
						{ key: 's', type: 'audio', maxSeconds: 4 },
						{ key: 't', type: 'audio', maxSeconds: 121 },
						{ key: 'u', type: 'audio', maxSeconds: '60' },
						{ key: 'v', type: 'audio', maxSeconds: 60, maxSize: 100 * 1024 },
						{ key: 'p', type: 'location', latField: 'lat' },
						{ key: 'q', type: 'location', latField: 'x', lngField: ' x ' },
					],
					'edit',
				),
			),
		).toEqual([
			'/pages/0/config/fields/0: buildiq.formLogic.error.signature-without-mode',
			'/pages/0/config/fields/1: buildiq.formLogic.error.audio-length-out-of-range',
			'/pages/0/config/fields/2: buildiq.formLogic.error.audio-length-out-of-range',
			'/pages/0/config/fields/3: buildiq.formLogic.error.audio-length-out-of-range',
			'/pages/0/config/fields/4: buildiq.formLogic.error.audio-length-too-long',
			'/pages/0/config/fields/5: buildiq.formLogic.error.location-without-fields',
			'/pages/0/config/fields/6: buildiq.formLogic.error.location-same-fields',
		])
	})
})

