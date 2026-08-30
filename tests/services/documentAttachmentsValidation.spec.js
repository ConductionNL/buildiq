/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side document-attachment validation.
 *
 * Spec: docudesk-document-templates (REQ-DDT-001).
 */
import { describe, it, expect } from 'vitest'
import {
	validateDocumentAttachments,
	DOCUMENT_FORMATS,
} from '../../src/services/manifestValidation/documentAttachments.js'

const UUID = '11111111-2222-3333-4444-555555555555'

const baseManifest = (documents) => ({
	schemas: { kapaanvraag: { properties: { dossiernummer: { type: 'string' } } } },
	runtime: { documents },
})

const validEntry = {
	id: 'kap-confirm',
	schema: 'kapaanvraag',
	templateId: UUID,
	templateName: 'Bevestigingsbrief',
	label: 'Generate confirmation letter',
}

describe('validateDocumentAttachments', () => {
	it('passes a valid attachment', () => {
		expect(validateDocumentAttachments(baseManifest([validEntry]))).toEqual([])
	})

	it('returns nothing when runtime.documents is absent', () => {
		expect(validateDocumentAttachments({ schemas: {} })).toEqual([])
	})

	it('accepts two attachments on the same schema with distinct labels', () => {
		const errs = validateDocumentAttachments(
			baseManifest([
				validEntry,
				{ ...validEntry, id: 'kap-besluit', label: 'Generate besluit' },
			]),
		)
		expect(errs).toEqual([])
	})

	it('rejects a duplicate (schema, label) pair on both entries', () => {
		const errs = validateDocumentAttachments(
			baseManifest([validEntry, { ...validEntry, id: 'kap-2' }]),
		)
		const dup = errs.filter((e) => e.includes('duplicate-label'))
		expect(dup.length).toBe(2)
	})

	it('rejects a duplicate id', () => {
		const errs = validateDocumentAttachments(
			baseManifest([
				validEntry,
				{ ...validEntry, schema: 'kapaanvraag', label: 'Other' },
			]),
		)
		expect(errs.some((e) => e.includes('duplicate-id'))).toBe(true)
	})

	it('rejects a foreign schema', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, schema: 'not-in-this-app' }]),
		)
		expect(errs.some((e) => e.includes('unknown-schema'))).toBe(true)
	})

	it('rejects a non-UUID templateId', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, templateId: 'not-a-uuid' }]),
		)
		expect(errs.some((e) => e.includes('template-id-invalid'))).toBe(true)
	})

	it('rejects an empty label', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, label: '  ' }]),
		)
		expect(errs.some((e) => e.includes('label-required'))).toBe(true)
	})

	it('rejects a missing templateName', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, templateName: '' }]),
		)
		expect(errs.some((e) => e.includes('template-name-required'))).toBe(true)
	})

	it('rejects a format outside the pinned set', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, format: 'rtf' }]),
		)
		expect(errs.some((e) => e.includes('format-unsupported'))).toBe(true)
	})

	it('accepts every pinned format', () => {
		DOCUMENT_FORMATS.forEach((format) => {
			expect(
				validateDocumentAttachments(
					baseManifest([{ ...validEntry, format }]),
				),
			).toEqual([])
		})
	})

	it('rejects an unknown key', () => {
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, foo: 'bar' }]),
		)
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})

	it('rejects a non-array documents block', () => {
		const errs = validateDocumentAttachments({ runtime: { documents: 'nope' } })
		expect(errs.some((e) => e.includes('not-array'))).toBe(true)
	})

	it('accepts an optional filenameTemplate string and rejects a non-string', () => {
		expect(
			validateDocumentAttachments(
				baseManifest([
					{ ...validEntry, filenameTemplate: 'x-{{dossiernummer}}.pdf' },
				]),
			),
		).toEqual([])
		const errs = validateDocumentAttachments(
			baseManifest([{ ...validEntry, filenameTemplate: 42 }]),
		)
		expect(errs.some((e) => e.includes('filename-template-invalid'))).toBe(true)
	})
})
