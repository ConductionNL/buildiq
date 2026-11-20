// SPDX-License-Identifier: EUPL-1.2
/**
 * pageCodes validation (pages-qr-and-barcodes, design D5): scan on index
 * pages, scan fields on forms, code widgets on detail pages.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-index-pages-can-scan-to-find-a-record-req-bqqr-001
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-forms-can-fill-a-field-from-a-scan-req-bqqr-002
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
 */
import { describe, expect, it } from 'vitest'
import { holdsDigits, validatePageCodes } from '../../src/services/manifestValidation/pageCodes.js'

const SCHEMAS = {
	apparaat: {
		properties: {
			serienummer: { type: 'string' },
			ean: { type: 'string', pattern: '^[0-9]{13}$' },
			artikelnummer: { type: 'integer' },
		},
	},
}

/**
 * A manifest with one page.
 *
 * @param {object} page - the page.
 * @param {object|undefined} schemas - the embedded schemas.
 * @return {object}
 */
function manifest(page, schemas = SCHEMAS) {
	return { pages: [page], ...(schemas ? { schemas } : {}) }
}

describe('scan on an index page', () => {
	const page = (scan) => ({ id: 'apparaten', type: 'index', config: { schema: 'apparaat', scan } })

	it('accepts a scan on a field of the schema that opens or filters', () => {
		expect(validatePageCodes(manifest(page({ enabled: true, field: 'serienummer', onMatch: 'open' })))).toEqual([])
		expect(validatePageCodes(manifest(page({ enabled: true, field: 'serienummer', onMatch: 'filter' })))).toEqual([])
	})

	it('refuses a field the schema does not have, and a missing field or match action', () => {
		expect(validatePageCodes(manifest(page({ enabled: true, field: 'kenteken', onMatch: 'open' })))).toEqual([
			'/pages/0/config/scan/field: buildiq.pageCodes.error.scan-field-unknown',
		])
		expect(validatePageCodes(manifest(page({ enabled: true, onMatch: 'show' })))).toEqual([
			'/pages/0/config/scan/field: buildiq.pageCodes.error.scan-field-required',
			'/pages/0/config/scan/onMatch: buildiq.pageCodes.error.scan-on-match-invalid',
		])
	})

	it('checks nothing more on a scan that is off, but still its shape', () => {
		expect(validatePageCodes(manifest(page({ enabled: false })))).toEqual([])
		expect(validatePageCodes(manifest(page({ enabled: 'yes', extra: 1 })))).toEqual([
			'/pages/0/config/scan/extra: buildiq.pageCodes.error.scan-unknown-key',
			'/pages/0/config/scan/enabled: buildiq.pageCodes.error.scan-enabled-not-boolean',
		])
		expect(validatePageCodes(manifest(page('on')))).toEqual(['/pages/0/config/scan: buildiq.pageCodes.error.scan-not-object'])
	})

	it('cannot disprove a field when the schema is not embedded', () => {
		expect(validatePageCodes(manifest(page({ enabled: true, field: 'anything', onMatch: 'open' }), null))).toEqual([])
	})
})

describe('scan field on a form', () => {
	const form = (mode, field) => ({ id: 'pakket', type: 'form', config: { mode, fields: [{ key: 'naam', type: 'string' }, field] } })

	it('accepts a scan field with known formats on a form that is not public', () => {
		expect(validatePageCodes(manifest(form('authenticated', { key: 'trackingcode', type: 'scan', formats: ['code128', 'qr'] })))).toEqual([])
	})

	it('refuses a scan field on a public form, including one that leaves the mode at its default', () => {
		const field = { key: 'trackingcode', type: 'scan', formats: ['qr'] }
		expect(validatePageCodes(manifest(form('public', field)))).toEqual(['/pages/0/config/fields/1/type: buildiq.pageCodes.error.scan-on-public-form'])
		expect(validatePageCodes(manifest(form(undefined, field)))).toEqual(['/pages/0/config/fields/1/type: buildiq.pageCodes.error.scan-on-public-form'])
	})

	it('refuses no formats and an unknown format', () => {
		expect(validatePageCodes(manifest(form('authenticated', { key: 'c', type: 'scan' })))).toEqual([
			'/pages/0/config/fields/1/formats: buildiq.pageCodes.error.formats-required',
		])
		expect(validatePageCodes(manifest(form('authenticated', { key: 'c', type: 'scan', formats: ['qr', 'pdf417'] })))).toEqual([
			'/pages/0/config/fields/1/formats/1: buildiq.pageCodes.error.format-unknown',
		])
	})
})

describe('code widget on a detail page', () => {
	const detail = (widget) => ({ id: 'apparaat-detail', type: 'detail', config: { schema: 'apparaat', widgets: [{ id: 'notes', type: 'custom' }, widget] } })

	it('accepts a QR code of the address and a code of a fitting field', () => {
		expect(validatePageCodes(manifest(detail({ id: 'qr', type: 'code', format: 'qr', source: 'address' })))).toEqual([])
		expect(validatePageCodes(manifest(detail({ id: 'bc', type: 'code', format: 'code128', source: 'field', field: 'serienummer' })))).toEqual([])
		expect(validatePageCodes(manifest(detail({ id: 'ean', type: 'code', format: 'ean13', source: 'field', field: 'ean' })))).toEqual([])
		expect(validatePageCodes(manifest(detail({ id: 'ean', type: 'code', format: 'ean13', source: 'field', field: 'artikelnummer' })))).toEqual([])
	})

	it('refuses a Code 128 or EAN-13 of the address', () => {
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'code128', source: 'address' })))).toEqual([
			'/pages/0/config/widgets/1/source: buildiq.pageCodes.error.address-needs-qr',
		])
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'ean13', source: 'address' })))).toEqual([
			'/pages/0/config/widgets/1/source: buildiq.pageCodes.error.address-needs-qr',
		])
	})

	it('refuses an EAN-13 of a field that is not digits', () => {
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'ean13', source: 'field', field: 'serienummer' })))).toEqual([
			'/pages/0/config/widgets/1/field: buildiq.pageCodes.error.ean13-field-not-digits',
		])
	})

	it('refuses an unknown format, source or field, and a missing field', () => {
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'datamatrix', source: 'nowhere' })))).toEqual([
			'/pages/0/config/widgets/1/format: buildiq.pageCodes.error.format-unknown',
			'/pages/0/config/widgets/1/source: buildiq.pageCodes.error.source-invalid',
		])
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'qr', source: 'field', field: 'kenteken' })))).toEqual([
			'/pages/0/config/widgets/1/field: buildiq.pageCodes.error.code-field-unknown',
		])
		expect(validatePageCodes(manifest(detail({ type: 'code', format: 'qr', source: 'field' })))).toEqual([
			'/pages/0/config/widgets/1/field: buildiq.pageCodes.error.code-field-required',
		])
	})
})

describe('holdsDigits', () => {
	it('takes integers and digit patterns, not free text or a broken pattern', () => {
		expect(holdsDigits({ type: 'integer' })).toBe(true)
		expect(holdsDigits({ type: 'string', pattern: '^\\d{13}$' })).toBe(true)
		expect(holdsDigits({ type: 'string' })).toBe(false)
		expect(holdsDigits({ type: 'string', pattern: '^.*$' })).toBe(false)
		expect(holdsDigits({ type: 'string', pattern: '(' })).toBe(false)
		expect(holdsDigits(null)).toBe(false)
	})
})

describe('pages without scanning or codes', () => {
	it('say nothing', () => {
		expect(validatePageCodes({ pages: [{ type: 'index', config: { schema: 'apparaat' } }, { type: 'dashboard', config: { widgets: [{ type: 'code' }] } }] })).toEqual([])
		expect(validatePageCodes({})).toEqual([])
		expect(validatePageCodes(null)).toEqual([])
	})
})
