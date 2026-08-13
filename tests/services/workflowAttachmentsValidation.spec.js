/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side workflow-attachment validation.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-001).
 */
import { describe, it, expect } from 'vitest'
import { validateWorkflowAttachments } from '../../src/services/manifestValidation/workflowAttachments.js'

const UUID = '11111111-2222-3333-4444-555555555555'

const baseManifest = (workflows) => ({
	schemas: {
		kapaanvraag: {
			properties: { zaakUrl: { type: 'string' }, count: { type: 'integer' } },
		},
	},
	runtime: { workflows },
})

const validEntry = {
	id: 'kap-handling',
	schema: 'kapaanvraag',
	caseTypeUuid: UUID,
	caseTypeName: 'Kapvergunning',
	trigger: 'on-create',
	linkProperty: 'zaakUrl',
}

describe('validateWorkflowAttachments', () => {
	it('passes a valid attachment', () => {
		expect(validateWorkflowAttachments(baseManifest([validEntry]))).toEqual([])
	})
	it('returns nothing when runtime.workflows is absent', () => {
		expect(validateWorkflowAttachments({ schemas: {} })).toEqual([])
	})
	it('rejects a second attachment on the same schema', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([validEntry, { ...validEntry, id: 'kap-2' }]),
		)
		expect(errs.some((e) => e.includes('duplicate-schema-attachment'))).toBe(
			true,
		)
	})
	it('rejects a duplicate id', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([validEntry, { ...validEntry, schema: 'other' }]),
		)
		expect(errs.some((e) => e.includes('duplicate-id'))).toBe(true)
	})
	it('rejects a missing linkProperty on the schema', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, linkProperty: 'nope' }]),
		)
		expect(errs.some((e) => e.includes('link-property-missing'))).toBe(true)
	})
	it('rejects a non-string linkProperty', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, linkProperty: 'count' }]),
		)
		expect(errs.some((e) => e.includes('link-property-not-string'))).toBe(true)
	})
	it('rejects an invalid caseTypeUuid', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, caseTypeUuid: 'not-a-uuid' }]),
		)
		expect(errs.some((e) => e.includes('casetype-uuid-invalid'))).toBe(true)
	})
	it('rejects an unknown trigger', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, trigger: 'on-update' }]),
		)
		expect(errs.some((e) => e.includes('trigger-unsupported'))).toBe(true)
	})
	it('rejects an unknown key', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, bogus: 1 }]),
		)
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})
	it('rejects an unknown schema', () => {
		const errs = validateWorkflowAttachments(
			baseManifest([{ ...validEntry, schema: 'ghost', linkProperty: 'x' }]),
		)
		expect(errs.some((e) => e.includes('schema-unknown'))).toBe(true)
	})
})
