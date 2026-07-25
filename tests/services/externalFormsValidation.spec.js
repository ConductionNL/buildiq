/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side runtime.externalForms[] validation.
 *
 * Spec: external-form-provisioning (REQ-EFP-001).
 */
import { describe, it, expect } from 'vitest'
import { validateExternalForms, EXTERNAL_FORM_STATUSES } from '../../src/services/manifestValidation/externalForms.js'

const validEntry = {
	id: 'ef-1',
	pageId: 'page-1',
	register: 'intake',
	schema: 'report',
	status: 'enabled',
	publicRead: false,
	organisationScope: null,
	portalPage: null,
	trackLinkAction: { enabled: false },
}

const withForms = (forms) => ({ runtime: { externalForms: forms } })

describe('validateExternalForms', () => {
	it('passes a valid entry', () => {
		expect(validateExternalForms(withForms([validEntry]))).toEqual([])
	})

	it('returns nothing when runtime.externalForms is absent', () => {
		expect(validateExternalForms({ runtime: {} })).toEqual([])
		expect(validateExternalForms({})).toEqual([])
	})

	it('round-trips losslessly when present (byte-identical manifest elsewhere)', () => {
		const manifest = withForms([validEntry])
		validateExternalForms(manifest)
		expect(manifest.runtime.externalForms[0]).toEqual(validEntry)
	})

	it('rejects a non-array externalForms', () => {
		const errs = validateExternalForms({ runtime: { externalForms: {} } })
		expect(errs.some((e) => e.includes('not-array'))).toBe(true)
	})

	it('rejects an entry missing register', () => {
		const { register, ...rest } = validEntry
		const errs = validateExternalForms(withForms([rest]))
		expect(errs.some((e) => e.includes('register-required'))).toBe(true)
	})

	it('rejects an entry missing schema', () => {
		const { schema, ...rest } = validEntry
		const errs = validateExternalForms(withForms([rest]))
		expect(errs.some((e) => e.includes('schema-required'))).toBe(true)
	})

	it('rejects an entry missing id', () => {
		const { id, ...rest } = validEntry
		const errs = validateExternalForms(withForms([rest]))
		expect(errs.some((e) => e.includes('id-required'))).toBe(true)
	})

	it('rejects a duplicate id (at most one entry per id)', () => {
		const errs = validateExternalForms(withForms([validEntry, { ...validEntry, pageId: 'page-2' }]))
		expect(errs.some((e) => e.includes('duplicate-id'))).toBe(true)
	})

	it('allows multiple entries targeting the same (register, schema) with distinct ids', () => {
		const errs = validateExternalForms(withForms([
			validEntry,
			{ ...validEntry, id: 'ef-2', pageId: 'page-2' },
		]))
		expect(errs).toEqual([])
	})

	it('rejects an unknown status value', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, status: 'live' }]))
		expect(errs.some((e) => e.includes('status-invalid'))).toBe(true)
	})

	it('rejects a non-boolean publicRead', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, publicRead: 'yes' }]))
		expect(errs.some((e) => e.includes('public-read-not-boolean'))).toBe(true)
	})

	it('rejects a non-boolean trackLinkAction.enabled', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, trackLinkAction: { enabled: 'yes' } }]))
		expect(errs.some((e) => e.includes('track-link-action-enabled-not-boolean'))).toBe(true)
	})

	it('accepts a populated portalPage object', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, portalPage: { objectId: 'uuid-1', portalPath: '/portal' } }]))
		expect(errs).toEqual([])
	})

	it('rejects a portalPage missing objectId', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, portalPage: { portalPath: '/portal' } }]))
		expect(errs.some((e) => e.includes('portal-page-object-id-required'))).toBe(true)
	})

	it('rejects an unknown top-level key', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, extra: true }]))
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})

	it('rejects an unknown nested key on portalPage', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, portalPage: { objectId: 'x', bogus: 1 } }]))
		expect(errs.some((e) => e.includes('portalPage') && e.includes('unknown-key'))).toBe(true)
	})

	it('accepts an organisationScope string or null', () => {
		expect(validateExternalForms(withForms([{ ...validEntry, organisationScope: 'org-uuid' }]))).toEqual([])
		expect(validateExternalForms(withForms([{ ...validEntry, organisationScope: null }]))).toEqual([])
	})

	it('rejects a non-string/non-null organisationScope', () => {
		const errs = validateExternalForms(withForms([{ ...validEntry, organisationScope: 42 }]))
		expect(errs.some((e) => e.includes('organisation-scope-invalid'))).toBe(true)
	})

	it('only knows enabled/disabled as statuses', () => {
		expect(EXTERNAL_FORM_STATUSES).toEqual(['enabled', 'disabled'])
	})
})
