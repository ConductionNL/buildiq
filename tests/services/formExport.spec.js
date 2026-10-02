/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: a library form travels between organisations as a file (REQ-BQGL-005).
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */

import { describe, expect, it } from 'vitest'
import {
	exportFormPayload,
	FORM_EXPORT_KIND,
	FORM_EXPORT_SCHEMA_VERSION,
	FormImportError,
	parseFormImport,
} from '../../src/services/formExport.js'

const libraryForm = {
	uuid: 'u-1',
	createdBy: 'maker',
	'@self': { id: 'u-1', organisation: 'org-a' },
	slug: 'aanvraag-energiesubsidie',
	name: 'Aanvraag energiesubsidie',
	category: 'citizen-engagement',
	kind: 'registration-form',
	publisher: 'Gemeente Voorbeeld',
	form: { fields: [{ name: 'naam', label: 'Naam' }] },
	schemaFragment: { naam: { type: 'string' } },
}

describe('formExport (REQ-BQGL-005)', () => {
	it('exports with the form-template envelope and without local identity', () => {
		const payload = exportFormPayload(libraryForm)

		expect(payload.kind).toBe(FORM_EXPORT_KIND)
		expect(payload.kind).toBe('form-template')
		expect(payload.schemaVersion).toBe(FORM_EXPORT_SCHEMA_VERSION)
		expect(payload.form).not.toHaveProperty('uuid')
		expect(payload.form).not.toHaveProperty('createdBy')
		expect(payload.form).not.toHaveProperty('@self')
	})

	it('round-trips: an imported export is the same library form, keeping its category and publisher', () => {
		const imported = parseFormImport(JSON.stringify(exportFormPayload(libraryForm)))

		expect(imported.slug).toBe('aanvraag-energiesubsidie')
		expect(imported.category).toBe('citizen-engagement')
		expect(imported.publisher).toBe('Gemeente Voorbeeld')
		expect(imported.form).toEqual(libraryForm.form)
		expect(imported).not.toHaveProperty('uuid')
	})

	it('refuses a component block export', () => {
		const block = { schemaVersion: '1.0', kind: 'component-block', block: { slug: 'x', fragment: {} } }

		expect(() => parseFormImport(JSON.stringify(block))).toThrow(FormImportError)
		try {
			parseFormImport(block)
		} catch (e) {
			expect(e.code).toBe('not-a-form')
		}
	})

	it('refuses a file that is not JSON, and an envelope whose form config is broken', () => {
		expect(() => parseFormImport('{nope')).toThrow(FormImportError)

		const noFields = exportFormPayload({ ...libraryForm, form: {} })
		expect(() => parseFormImport(noFields)).toThrow(FormImportError)

		const badKind = exportFormPayload({ ...libraryForm, kind: 'page' })
		expect(() => parseFormImport(badKind)).toThrow(FormImportError)

		const badCategory = exportFormPayload({ ...libraryForm, category: 'anything' })
		expect(() => parseFormImport(badCategory)).toThrow(FormImportError)
	})
})
