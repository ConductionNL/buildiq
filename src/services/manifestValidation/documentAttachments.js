// SPDX-License-Identifier: EUPL-1.2
/**
 * documentAttachments — app-side validation of the manifest v2
 * `runtime.documents[]` array (Docudesk document templates, REQ-DDT-001).
 * The canonical `app-manifest-v2.schema.json` carries `runtime` with
 * `additionalProperties: true`, so the library validator accepts the
 * `documents` branch; this module supplies the strict shape + cross-reference
 * checks openbuild needs, surfaced through the `useManifestValidator`
 * pipeline (the same mechanism the workflow/connector siblings use).
 *
 * Returned errors are `<pointer>: <i18n-error-code>` strings so the existing
 * path-prefix → inline-mark mechanism (REQ-OBPD-011) lights up the offending
 * editor entry.
 *
 * Unlike the workflow sibling (one attachment per schema), MULTIPLE document
 * attachments per schema are allowed — an object commonly produces several
 * letters — so the uniqueness key is the *(schema, label)* pair, not `schema`.
 *
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-001
 */

import { schemaKnown } from './manifestSchemas.js'

/**
 * Closed enum of supported Docudesk output formats, pinned against the
 * deployed Docudesk `CorrespondenceService::VALID_FORMATS` 2026-06-15. Shared
 * single-source-of-truth with the dialog's format picker (task 2.1).
 */
export const DOCUMENT_FORMATS = Object.freeze(['pdf', 'docx', 'html', 'email'])

/** The only keys a document-attachment entry may carry. */
const ALLOWED_KEYS = Object.freeze([
	'id',
	'schema',
	'templateId',
	'templateName',
	'label',
	'format',
	'filenameTemplate',
])

/** Loose UUID check (8-4-4-4-12 hex). */
const UUID_RE =
	/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/

/**
 * Whether a value is a non-null, non-array object.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a value is a string with more than whitespace in it.
 *
 * @param {unknown} value - the value to test.
 * @return {boolean}
 */
function isFilledString(value) {
	return typeof value === 'string' && value.trim() !== ''
}

/**
 * One `<entry pointer>: <code>` error string.
 *
 * @param {{pointer: string}} ctx - the entry context.
 * @param {string} code - the i18n error code.
 * @return {string}
 */
function documentError(ctx, code) {
	return `${ctx.pointer}: ${code}`
}

/**
 * Unknown keys on the entry.
 *
 * @param {object} doc - the document entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function keyErrors(doc, ctx) {
	return Object.keys(doc)
		.filter((key) => !ALLOWED_KEYS.includes(key))
		.map((key) => `${ctx.pointer}/${key}: openbuild.document.error.unknown-key`)
}

/**
 * id: required, at most one entry per id. Records the id as seen.
 *
 * @param {object} doc - the document entry.
 * @param {{pointer: string, idx: number, seenIds: Map}} ctx - the entry context.
 * @return {string[]}
 */
function idErrors(doc, ctx) {
	if (!isFilledString(doc.id)) {
		return [documentError(ctx, 'openbuild.document.error.id-required')]
	}
	const duplicate = ctx.seenIds.has(doc.id)
	ctx.seenIds.set(doc.id, ctx.idx)
	return duplicate
		? [documentError(ctx, 'openbuild.document.error.duplicate-id')]
		: []
}

/**
 * schema: required and known to the manifest.
 *
 * @param {object} doc - the document entry.
 * @param {{pointer: string, manifest: object}} ctx - the entry context.
 * @return {string[]}
 */
function schemaErrors(doc, ctx) {
	if (!isFilledString(doc.schema)) {
		return [documentError(ctx, 'openbuild.document.error.schema-required')]
	}
	if (!schemaKnown(ctx.manifest, doc.schema)) {
		return [documentError(ctx, 'openbuild.document.error.unknown-schema')]
	}
	return []
}

/**
 * templateId, templateName and label.
 *
 * @param {object} doc - the document entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function templateErrors(doc, ctx) {
	const errors = []
	if (typeof doc.templateId !== 'string' || !UUID_RE.test(doc.templateId)) {
		errors.push(
			documentError(ctx, 'openbuild.document.error.template-id-invalid'),
		)
	}
	if (!isFilledString(doc.templateName)) {
		errors.push(
			documentError(ctx, 'openbuild.document.error.template-name-required'),
		)
	}
	if (!isFilledString(doc.label)) {
		errors.push(documentError(ctx, 'openbuild.document.error.label-required'))
	}
	return errors
}

/**
 * format (optional, within the pinned set when present) and filenameTemplate
 * (optional string).
 *
 * @param {object} doc - the document entry.
 * @param {{pointer: string}} ctx - the entry context.
 * @return {string[]}
 */
function outputErrors(doc, ctx) {
	const errors = []
	if (doc.format !== undefined && !DOCUMENT_FORMATS.includes(doc.format)) {
		errors.push(
			documentError(ctx, 'openbuild.document.error.format-unsupported'),
		)
	}
	if (
		doc.filenameTemplate !== undefined
		&& typeof doc.filenameTemplate !== 'string'
	) {
		errors.push(
			documentError(ctx, 'openbuild.document.error.filename-template-invalid'),
		)
	}
	return errors
}

/** The per-entry checks, in the order their errors are reported. */
const DOCUMENT_CHECKS = Object.freeze([
	keyErrors,
	idErrors,
	schemaErrors,
	templateErrors,
	outputErrors,
])

/**
 * The (schema, label) uniqueness key of an entry, or null when either is not
 * a string.
 *
 * @param {unknown} doc - the document entry.
 * @return {string|null}
 */
function labelKey(doc) {
	if (doc && typeof doc.schema === 'string' && typeof doc.label === 'string') {
		return `${doc.schema}\u0000${doc.label}`
	}
	return null
}

/**
 * Every error of one document entry. Counts its (schema, label) key.
 *
 * @param {unknown} doc - the document entry.
 * @param {object} ctx - the entry context: pointer, idx, manifest, seenIds
 *   and labelKeyCounts.
 * @return {string[]}
 */
function documentErrors(doc, ctx) {
	if (!isPlainObject(doc)) {
		return [documentError(ctx, 'openbuild.document.error.invalid-shape')]
	}
	const errors = DOCUMENT_CHECKS.flatMap((check) => check(doc, ctx))
	const key = labelKey(doc)
	if (key !== null) {
		ctx.labelKeyCounts.set(key, (ctx.labelKeyCounts.get(key) || 0) + 1)
	}
	return errors
}

/**
 * Mark every entry that shares a (schema, label) pair with another.
 *
 * @param {Array} documents - the documents array.
 * @param {Map<string, number>} labelKeyCounts - entries per (schema, label) key.
 * @return {string[]}
 */
function duplicateLabelErrors(documents, labelKeyCounts) {
	const errors = []
	documents.forEach((doc, idx) => {
		const key = labelKey(doc)
		if (key !== null && (labelKeyCounts.get(key) || 0) > 1) {
			errors.push(
				`/runtime/documents/${idx}: openbuild.document.error.duplicate-label`,
			)
		}
	})
	return errors
}

/**
 * Validate the `runtime.documents[]` array of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/specs/docudesk-document-templates/spec.md#req-ddt-001
 */
export function validateDocumentAttachments(manifest) {
	const documents = manifest && manifest.runtime && manifest.runtime.documents
	if (documents === undefined) {
		return []
	}
	if (!Array.isArray(documents)) {
		return ['/runtime/documents: openbuild.document.error.not-array']
	}
	const seenIds = new Map()
	const labelKeyCounts = new Map()
	const errors = documents.flatMap((doc, idx) =>
		documentErrors(doc, {
			pointer: `/runtime/documents/${idx}`,
			idx,
			manifest,
			seenIds,
			labelKeyCounts,
		}),
	)
	return errors.concat(duplicateLabelErrors(documents, labelKeyCounts))
}
