// SPDX-License-Identifier: EUPL-1.2

/**
 * Export and import of a library form as a JSON file
 * (reuse-gallery-categories-and-form-library, REQ-BQGL-005). Follows
 * blockExport.js: an envelope with `kind` and a schema version, local identity
 * stripped on the way out and again on the way in.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */

import { FORM_KINDS } from './formCapture.js'

/** The envelope kind of a form export. */
export const FORM_EXPORT_KIND = 'form-template'

/** The version of the envelope. */
export const FORM_EXPORT_SCHEMA_VERSION = '1.0'

/** The categories a library form can carry, as the formTemplate schema declares them. */
const CATEGORIES = ['government-services', 'internal-operations', 'citizen-engagement', 'field-work']

/**
 * A refused import. `code` is one of invalid-json, not-a-form, invalid-form.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
export class FormImportError extends Error {
	/**
	 * @param {string} code Why the file was refused.
	 */
	constructor(code) {
		super(`buildiq.formLibrary.import.error.${code}`)
		this.name = 'FormImportError'
		this.code = code
	}
}

/**
 * The library form without the fields that belong to this instance.
 *
 * @param {object} form A form-template record.
 * @return {object}
 */
function withoutIdentity(form) {
	// eslint-disable-next-line no-unused-vars
	const { uuid, id, createdBy, organisation, '@self': self, ...rest } = form || {}
	return rest
}

/**
 * Build the export envelope of a library form.
 *
 * @param {object} form A form-template record.
 * @return {{schemaVersion: string, kind: string, form: object}}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
export function exportFormPayload(form) {
	return {
		schemaVersion: FORM_EXPORT_SCHEMA_VERSION,
		kind: FORM_EXPORT_KIND,
		form: withoutIdentity(form),
	}
}

/**
 * Offer the export envelope as a file download.
 *
 * @param {object} form A form-template record.
 * @return {void}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
export function downloadFormExport(form) {
	const blob = new Blob([JSON.stringify(exportFormPayload(form), null, 2)], { type: 'application/json' })
	const link = document.createElement('a')
	link.href = URL.createObjectURL(blob)
	link.download = `${(form && form.slug) || 'form-template'}.json`
	link.click()
	URL.revokeObjectURL(link.href)
}

/**
 * Read a form export and return the library form to create.
 *
 * @param {string|object} input The file's text, or its parsed content.
 * @return {object} The form-template record, without local identity.
 * @throws {FormImportError} When the file is not a valid form export.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
 */
export function parseFormImport(input) {
	let data = input
	if (typeof input === 'string') {
		try {
			data = JSON.parse(input)
		} catch {
			throw new FormImportError('invalid-json')
		}
	}
	if (!data || typeof data !== 'object' || data.kind !== FORM_EXPORT_KIND || !data.form || typeof data.form !== 'object') {
		throw new FormImportError('not-a-form')
	}
	const form = withoutIdentity(data.form)
	const valid
		= typeof form.slug === 'string' && /^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(form.slug)
		&& typeof form.name === 'string' && form.name !== ''
		&& FORM_KINDS.includes(form.kind)
		&& CATEGORIES.includes(form.category)
		&& form.form && typeof form.form === 'object' && Array.isArray(form.form.fields)
		&& form.schemaFragment && typeof form.schemaFragment === 'object' && !Array.isArray(form.schemaFragment)
	if (!valid) {
		throw new FormImportError('invalid-form')
	}
	return form
}
