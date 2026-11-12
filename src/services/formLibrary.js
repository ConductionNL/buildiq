// SPDX-License-Identifier: EUPL-1.2

/**
 * formLibrary: the network side of the form library and the two targets a
 * library form is used for (reuse-gallery-categories-and-form-library,
 * REQ-BQGL-002 to REQ-BQGL-005).
 *
 * Every call takes an axios-like client so the specs can hand in a fake. The
 * schema is written only through a PATCH of its `properties`, by its numeric
 * id: OpenRegister reads schemas by slug but writes them by id.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */

import defaultAxios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { FORM_LIBRARY_PATH, missingProperties } from './formCapture.js'
import { deepClone, rewriteSchemaRefs } from './templateCapture.js'

/** The two places a library form can go. */
export const FORM_TARGETS = ['form-page', 'registration-form']

/**
 * The results array of an OpenRegister list answer.
 *
 * @param {object|Array<object>|null} data The response body.
 * @return {Array<object>}
 */
function resultsOf(data) {
	if (Array.isArray(data)) {
		return data
	}
	return Array.isArray(data && data.results) ? data.results : []
}

/**
 * The object id OpenRegister writes a stored object under.
 *
 * @param {object} object A stored object.
 * @return {string}
 */
function objectId(object) {
	if (!object) {
		return ''
	}
	return String(selfIdOf(object) || object.uuid || object.id || '')
}

/**
 * The id a stored object carries in its `@self` block.
 *
 * @param {object} object A stored object.
 * @return {(string|number|undefined)}
 */
function selfIdOf(object) {
	const self = object['@self'] || {}
	return self.id
}

/**
 * Every library form the caller can see.
 *
 * @param {object} [client] Axios-like client.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
 */
export async function fetchLibraryForms(client = defaultAxios) {
	const { data } = await client.get(generateUrl(FORM_LIBRARY_PATH), {
		params: { _limit: 200 },
	})
	return resultsOf(data)
}

/**
 * Store a form-template record in the library.
 *
 * @param {object} record The record, from captureForm() or parseFormImport().
 * @param {object} [client] Axios-like client.
 * @return {Promise<object>} The stored record.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export async function createLibraryForm(record, client = defaultAxios) {
	const { data } = await client.post(generateUrl(FORM_LIBRARY_PATH), record)
	return data
}

/**
 * The library forms that match a search and a category, by name, description
 * and publisher.
 *
 * @param {Array<object>} forms The library forms.
 * @param {{query?: string, category?: string}} filter What to match.
 * @return {Array<object>}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
 */
export function filterLibraryForms(forms, { query = '', category = '' } = {}) {
	const filter = { needle: searchNeedle(query), category }
	return (Array.isArray(forms) ? forms : []).filter((form) =>
		matchesLibraryFilter(form, filter),
	)
}

/**
 * The search text as matched: trimmed and lower-cased, '' for none.
 *
 * @param {?string} query The search text.
 * @return {string}
 */
function searchNeedle(query) {
	return String(query || '')
		.trim()
		.toLowerCase()
}

/**
 * Whether a library form is in the category and mentions the search text.
 *
 * @param {?object} form A library form.
 * @param {{needle: string, category: string}} filter The lower-cased search text and the category.
 * @return {boolean}
 */
function matchesLibraryFilter(form, filter) {
	if (!form) {
		return false
	}
	if (filter.category && form.category !== filter.category) {
		return false
	}
	return filter.needle === '' || mentions(form, filter.needle)
}

/**
 * Whether a form's name, description or publisher contains the search text.
 *
 * @param {object} form A library form.
 * @param {string} needle The lower-cased search text.
 * @return {boolean}
 */
function mentions(form, needle) {
	return [form.name, form.description, form.publisher].some(
		(text) => typeof text === 'string' && text.toLowerCase().includes(needle),
	)
}

/**
 * The schemas of a register, each with its `properties` inline.
 *
 * @param {string} register Register slug or id.
 * @param {object} [client] Axios-like client.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export async function fetchRegisterSchemas(register, client = defaultAxios) {
	if (!register) {
		return []
	}
	const { data } = await client.get(
		generateUrl(
			`/apps/openregister/api/registers/${encodeURIComponent(register)}/schemas`,
		),
	)
	return resultsOf(data)
}

/**
 * One schema of a register, by slug or id, or null.
 *
 * @param {string} register Register slug or id.
 * @param {string} schema Schema slug or id.
 * @param {object} [client] Axios-like client.
 * @return {Promise<object|null>}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
 */
export async function fetchRegisterSchema(register, schema, client = defaultAxios) {
	if (!register || !schema) {
		return null
	}
	const schemas = await fetchRegisterSchemas(register, client)
	return (
		schemas.find(
			(entry) =>
				entry
				&& (String(entry.slug) === String(schema)
					|| String(entry.id) === String(schema)),
		) || null
	)
}

/**
 * Add the properties a target schema lacks, keeping every property it has.
 *
 * @param {object} schema The stored schema (needs `id` and `properties`).
 * @param {object} additions Property name to definition.
 * @param {object} [client] Axios-like client.
 * @return {Promise<object>} The schema as stored.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export async function addSchemaProperties(schema, additions, client = defaultAxios) {
	const properties = { ...((schema && schema.properties) || {}) }
	for (const [name, definition] of Object.entries(additions || {})) {
		if (!Object.hasOwn(properties, name)) {
			properties[name] = deepClone(definition)
		}
	}
	const { data } = await client.patch(
		generateUrl(
			`/apps/openregister/api/schemas/${encodeURIComponent(String(schema.id))}`,
		),
		{ properties },
	)
	return data
}

/**
 * The form config with the library's canonical schema slug pointed at the
 * schema the maker mapped it to.
 *
 * @param {object} template The form-template record.
 * @param {string} schemaSlug The target schema's slug.
 * @return {object}
 */
function mappedForm(template, schemaSlug) {
	const form = deepClone((template && template.form) || {})
	const source = template && template.sourceSchema
	return isRemapped(source, schemaSlug)
		? rewriteSchemaRefs(form, { [source]: schemaSlug })
		: form
}

/**
 * Whether the form's source schema is mapped to another schema.
 *
 * @param {?string} source The library's canonical schema slug.
 * @param {string} schemaSlug The target schema's slug.
 * @return {boolean}
 */
function isRemapped(source, schemaSlug) {
	return Boolean(source && schemaSlug && source !== schemaSlug)
}

/**
 * The manifest pages with a new form page for a library form appended, with an
 * id and a route no other page has.
 *
 * @param {Array<object>} pages The manifest's pages.
 * @param {object} template The form-template record.
 * @param {{register: string, schema: string}} target Where the form saves.
 * @return {Array<object>} A new array; the input is not changed.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export function withLibraryFormPage(pages, template, { register, schema }) {
	const list = Array.isArray(pages) ? pages : []
	const ids = new Set(list.map((page) => page && page.id))
	const routes = new Set(list.map((page) => page && page.route))
	const base = (template && template.slug) || 'form'
	let id = base
	let route = '/' + base
	let n = 2
	while (ids.has(id) || routes.has(route)) {
		id = `${base}-${n}`
		route = `/${base}-${n}`
		n += 1
	}
	const config = mappedForm(template, schema)
	config.submitEndpoint = `/apps/openregister/api/objects/${register}/${schema}`
	delete config.submitHandler
	return [...list, { id, route, type: 'form', title: template.name || id, config }]
}

/**
 * The body that saves a library form as a draft registration form for one
 * schema and type value. A draft that is never the default, like a copy.
 *
 * @param {object} template The form-template record.
 * @param {object} target The target: register, schema, typeProperty, typeValue, targetApp.
 * @return {object}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export function registrationFormFromLibrary(template, target) {
	const form = mappedForm(template, target.schema)
	const body = {
		name: template.name,
		audience: 'client',
		isDefault: false,
		status: 'draft',
		targetApp: target.targetApp,
		register: target.register,
		schema: target.schema,
		typeProperty: target.typeProperty,
		typeValue: target.typeValue,
	}
	for (const part of [
		'fields',
		'steps',
		'sections',
		'formLogic',
		'presets',
		'confirmationText',
	]) {
		if (form[part] !== undefined && form[part] !== null) {
			body[part] = form[part]
		}
	}
	return body
}

/**
 * What using a library form would change, before anything is written.
 *
 * @param {object} template The form-template record.
 * @param {object|null} targetSchema The stored target schema.
 * @return {{missing: object, missingNames: Array<string>}}
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export function planLibraryFormUse(template, targetSchema) {
	const missing = missingProperties(template, targetSchema)
	return { missing, missingNames: Object.keys(missing) }
}

/**
 * Use a library form: add the properties the target schema lacks when the
 * maker confirmed that, then write the form page or the registration form.
 * Nothing is written when a needed property is missing and was not confirmed.
 *
 * @param {object} input What to do.
 * @param {object} input.template The form-template record.
 * @param {string} input.target One of FORM_TARGETS.
 * @param {object} input.schema The stored target schema.
 * @param {string} input.register The register the schema lives in.
 * @param {boolean} input.addProperties Whether the maker confirmed adding the missing properties.
 * @param {object} [input.version] The application version, for a form page.
 * @param {object} [input.registration] typeProperty, typeValue and targetApp, for a registration form.
 * @param {function(object): Promise<object>} [input.saveRegistrationForm] Saves a registration form body.
 * @param {object} [client] Axios-like client.
 * @return {Promise<{added: Array<string>, page?: object, form?: object}>}
 * @throws {Error} `missing-properties` when properties are missing and not confirmed.
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
export async function useLibraryForm(input, client = defaultAxios) {
	const { target } = input
	if (!FORM_TARGETS.includes(target)) {
		throw new Error(`Unknown target: ${target}`)
	}
	const missingNames = await ensureSchemaProperties(input, client)
	if (target === 'form-page') {
		return { added: missingNames, page: await addFormPage(input, client) }
	}
	return { added: missingNames, form: await saveRegistration(input) }
}

/**
 * Add the properties the target schema lacks, when the maker confirmed that.
 *
 * @param {object} input What useLibraryForm was asked to do.
 * @param {object} client Axios-like client.
 * @return {Promise<Array<string>>} The names of the properties added.
 * @throws {Error} `missing-properties` when properties are missing and not confirmed.
 */
async function ensureSchemaProperties(input, client) {
	const { missing, missingNames } = planLibraryFormUse(input.template, input.schema)
	if (missingNames.length === 0) {
		return missingNames
	}
	if (input.addProperties !== true) {
		throw missingPropertiesError(missingNames)
	}
	await addSchemaProperties(input.schema, missing, client)
	return missingNames
}

/**
 * The error that stops a library form whose needed properties are missing.
 *
 * @param {Array<string>} missingNames The missing property names.
 * @return {Error}
 */
function missingPropertiesError(missingNames) {
	const error = new Error('missing-properties')
	error.missing = missingNames
	return error
}

/**
 * Append the library form as a page to the version manifest, by a PATCH of the
 * manifest only.
 *
 * @param {object} input What useLibraryForm was asked to do.
 * @param {object} client Axios-like client.
 * @return {Promise<object>} The page added.
 */
async function addFormPage(input, client) {
	const version = input.version || {}
	const pages = withLibraryFormPage(manifestPages(version), input.template, {
		register: input.register,
		schema: input.schema.slug,
	})
	const manifest = { ...(version.manifest || {}), pages }
	await client.patch(
		generateUrl(
			`/apps/openregister/api/objects/buildiq/applicationVersion/${encodeURIComponent(objectId(version))}`,
		),
		{ manifest },
	)
	return pages[pages.length - 1]
}

/**
 * The pages of a version's manifest, or none.
 *
 * @param {object} version The application version.
 * @return {Array<object>}
 */
function manifestPages(version) {
	return (version.manifest && version.manifest.pages) || []
}

/**
 * Save the library form as a draft registration form.
 *
 * @param {object} input What useLibraryForm was asked to do.
 * @return {Promise<object>} The saved form, or the body when the save returns none.
 */
async function saveRegistration(input) {
	const body = registrationFormFromLibrary(input.template, {
		...(input.registration || {}),
		register: input.register,
		schema: input.schema.slug,
	})
	const saved = await input.saveRegistrationForm(body)
	return (saved && saved.form) || body
}
