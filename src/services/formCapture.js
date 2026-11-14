// SPDX-License-Identifier: EUPL-1.2

/**
 * Capture a form page or a registration form as a form library item
 * (reuse-gallery-categories-and-form-library, REQ-BQGL-002).
 *
 * Reuses the de-namespace machinery of "Save as template": the schema the form
 * writes to is stored under its canonical slug, and the definitions of the
 * properties the form's fields bind to travel with it, so another app can add
 * what it lacks. Only form configuration is copied; never object data.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */

import { deepClone, deNamespaceSlug, rewriteSchemaRefs } from './templateCapture.js'

/** The register slug and schema slug the library items live under. */
export const FORM_LIBRARY_PATH =
	'/apps/openregister/api/objects/buildiq/form-template'

/** The two kinds of form the library holds. */
export const FORM_KINDS = ['form-page', 'registration-form']

/** The parts of a form that are configuration, and so travel with it. */
const FORM_PARTS = [
	'fields',
	'steps',
	'sections',
	'formLogic',
	'presets',
	'confirmationText',
	'submitLabel',
]

/**
 * Raised when a form binds a field to a property its schema lacks.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export class FormBindingError extends Error {
	/**
	 * @param {Array<string>} missing The property names the schema lacks.
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
	 */
	constructor(missing) {
		super(`buildiq.formLibrary.missing.${missing.join(',')}`)
		this.name = 'FormBindingError'
		this.missing = missing
	}
}

/**
 * The property a field writes: `key` on a form page, `name` on a registration form.
 *
 * @param {object} field A field of the form.
 * @param {string} kind One of FORM_KINDS.
 * @return {string} The property name, or '' when the field binds nothing.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export function boundProperty(field, kind) {
	if (!field || typeof field !== 'object') {
		return ''
	}
	const name = kind === 'form-page' ? field.key : field.name
	return typeof name === 'string' ? name : ''
}

/**
 * Every property the form writes: its fields, then its presets, without repeats.
 *
 * @param {object} form The form configuration.
 * @param {string} kind One of FORM_KINDS.
 * @return {Array<string>} The property names, in first-use order.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export function boundProperties(form, kind) {
	const names = [
		...(Array.isArray(form?.fields) ? form.fields : []).map((field) =>
			boundProperty(field, kind),
		),
		...(Array.isArray(form?.presets) ? form.presets : []).map((preset) =>
			preset && typeof preset.field === 'string' ? preset.field : '',
		),
	]
	return [...new Set(names.filter((name) => name !== ''))]
}

/**
 * Build the form-template record for a form.
 *
 * @param {object} input What to capture.
 * @param {string} input.kind One of FORM_KINDS.
 * @param {object} input.form The form page config, or the registrationForm object.
 * @param {{slug: string, properties: object}} input.schema The schema the form writes to.
 * @param {string} input.appSlug The app the form is saved from.
 * @param {object} input.metadata slug, name, description, category, publisher, createdBy.
 * @return {object} The record to create in the form library.
 * @throws {FormBindingError} When a field binds a property the schema lacks.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export function captureForm({ kind, form, schema, appSlug, metadata }) {
	assertFormKind(kind)
	const properties = propertiesOf(schema)
	const bound = boundProperties(form, kind)
	assertBoundInSchema(bound, properties)
	const source = sourceSchemaOf(schema, appSlug)
	return {
		...recordIdentity(metadata),
		kind,
		form: capturedForm(form, source.rewrite),
		schemaFragment: schemaFragmentOf(bound, properties),
		sourceSchema: source.canonical,
		...recordProvenance(metadata, appSlug),
	}
}

/**
 * Refuse a form kind the library does not know.
 *
 * @param {string} kind The form kind.
 * @return {void}
 * @throws {Error} When the kind is not one of FORM_KINDS.
 */
function assertFormKind(kind) {
	if (!FORM_KINDS.includes(kind)) {
		throw new Error(`Unknown form kind: ${kind}`)
	}
}

/**
 * The property definitions of a schema, or none.
 *
 * @param {?{properties: object}} schema The schema.
 * @return {object}
 */
function propertiesOf(schema) {
	return (schema && schema.properties) || {}
}

/**
 * Refuse a form bound to a property its schema lacks.
 *
 * @param {Array<string>} bound The properties the form writes.
 * @param {object} properties The schema's property definitions.
 * @return {void}
 * @throws {FormBindingError} Naming every property the schema lacks.
 */
function assertBoundInSchema(bound, properties) {
	const missing = bound.filter((name) => !Object.hasOwn(properties, name))
	if (missing.length > 0) {
		throw new FormBindingError(missing)
	}
}

/**
 * The library's canonical slug for the form's schema, and the rewrite that
 * points the form's schema references at it.
 *
 * @param {?{slug: string}} schema The schema the form writes to.
 * @param {string} appSlug The app the form is saved from.
 * @return {{canonical: string, rewrite: object}}
 */
function sourceSchemaOf(schema, appSlug) {
	const sourceSlug = (schema && schema.slug) || ''
	const canonical = sourceSlug ? deNamespaceSlug(sourceSlug, appSlug).slug : ''
	const rewrite = sourceSlug ? { [sourceSlug]: canonical } : {}
	return { canonical, rewrite }
}

/**
 * The form parts the library keeps, cloned, with their schema references
 * rewritten; always with a field list.
 *
 * @param {?object} form The form configuration.
 * @param {object} rewrite Source schema slug to canonical slug.
 * @return {object}
 */
function capturedForm(form, rewrite) {
	const captured = {}
	for (const part of FORM_PARTS) {
		if (hasPart(form, part)) {
			captured[part] = rewriteSchemaRefs(deepClone(form[part]), rewrite)
		}
	}
	captured.fields = Array.isArray(captured.fields) ? captured.fields : []
	return captured
}

/**
 * Whether a form sets a part (neither undefined nor null).
 *
 * @param {?object} form The form configuration.
 * @param {string} part One of FORM_PARTS.
 * @return {boolean}
 */
function hasPart(form, part) {
	return Boolean(form) && form[part] !== undefined && form[part] !== null
}

/**
 * The definitions of the bound properties, cloned.
 *
 * @param {Array<string>} bound The properties the form writes.
 * @param {object} properties The schema's property definitions.
 * @return {object}
 */
function schemaFragmentOf(bound, properties) {
	const schemaFragment = {}
	for (const name of bound) {
		schemaFragment[name] = deepClone(properties[name])
	}
	return schemaFragment
}

/**
 * The record's leading metadata: slug, name, description and category.
 *
 * @param {object} metadata The metadata the maker entered.
 * @return {object}
 */
function recordIdentity(metadata) {
	return {
		slug: metadata.slug,
		name: metadata.name,
		description: metadata.description || '',
		category: metadata.category,
	}
}

/**
 * The record's trailing metadata: publisher, version, the app it came from and,
 * when known, who created it.
 *
 * @param {object} metadata The metadata the maker entered.
 * @param {string} appSlug The app the form is saved from.
 * @return {object}
 */
function recordProvenance(metadata, appSlug) {
	const provenance = {
		publisher: metadata.publisher || '',
		version: metadata.version || '1.0.0',
		sourceApplicationSlug: appSlug || '',
	}
	if (metadata.createdBy) {
		provenance.createdBy = metadata.createdBy
	}
	return provenance
}

/**
 * The properties a target schema lacks for a library form, with the stored
 * definitions to add them from (REQ-BQGL-004).
 *
 * @param {object} template The form-template record.
 * @param {{properties: object}|null} targetSchema The schema the form is added to.
 * @return {object} Property name to definition, for every property the target lacks.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export function missingProperties(template, targetSchema) {
	const have = propertiesOf(targetSchema)
	const fragment = (template && template.schemaFragment) || {}
	const missing = {}
	for (const name of templateBound(template)) {
		if (!Object.hasOwn(have, name)) {
			missing[name] = deepClone(fragment[name] || { type: 'string' })
		}
	}
	return missing
}

/**
 * Every property a library form writes.
 *
 * @param {?object} template The form-template record.
 * @return {Array<string>}
 */
function templateBound(template) {
	return boundProperties(template && template.form, template && template.kind)
}
