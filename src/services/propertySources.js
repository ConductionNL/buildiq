// SPDX-License-Identifier: EUPL-1.2
/**
 * propertySources: the registry providers a field can take its value from.
 *
 * integriq publishes them at `GET /api/property-sources` (BAG, BRP, KvK, ...),
 * and OpenRegister accepts a property declaring one under
 * `x-openregister-property-source: {provider, mode, config}`. This module
 * fetches the list and holds the pure helpers the field editor and the schema
 * designer share: read a declaration, write one, describe it for the fields
 * list, and map a refused save onto the field it names.
 *
 * The integriq path is resolved with `fleetAppPath`, never a literal app id.
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { fleetAppPath } from './fleetAppId.js'

/** The property key a registry source is declared under. */
export const PROPERTY_SOURCE_KEY = 'x-openregister-property-source'

/** The modes OpenRegister accepts: look it up every time, or use it as a starting value. */
export const PROPERTY_SOURCE_MODES = Object.freeze(['live', 'default'])

/** The field types a registry source is offered for. */
export const PROPERTY_SOURCE_TYPES = Object.freeze(['string', 'object'])

/**
 * Fetch the providers integriq publishes.
 *
 * Any failure (integriq not installed, a 404, a network error, an answer
 * without a list) is the typed result `{ available: false, results: [] }`,
 * never a throw: the designer then keeps existing declarations as they are.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-an-existing-declaration-survives-a-session-without-integriq-req-bqrf-002
 * @param {object} [opts] Options.
 * @param {object} [opts.client] An axios-like client, for tests.
 * @return {Promise<{available: boolean, results: Array<object>}>} The providers.
 */
export async function listPropertySources(opts = {}) {
	const client = opts.client || axios
	try {
		const response = await client.get(
			generateUrl(fleetAppPath('integriq', '/api/property-sources')),
		)
		const results = response?.data?.results
		if (!Array.isArray(results)) {
			return { available: false, results: [] }
		}
		return {
			available: true,
			results: results.filter((entry) => entry && typeof entry.id === 'string'),
		}
	} catch {
		return { available: false, results: [] }
	}
}

/**
 * The declaration a loaded property carries, or null when it carries none.
 *
 * A declaration that is not an object is kept as null here; the raw property
 * still carries it, and OpenRegister judges it on save.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
 * @param {object} prop The loaded property.
 * @return {{provider: string, mode: string, config: object}|null} The declaration.
 */
export function sourceFromProperty(prop) {
	const raw = prop && prop[PROPERTY_SOURCE_KEY]
	if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
		return null
	}
	return {
		provider: typeof raw.provider === 'string' ? raw.provider : '',
		mode: typeof raw.mode === 'string' ? raw.mode : 'live',
		config: isPlainObject(raw.config) ? clone(raw.config) : {},
	}
}

/**
 * The declaration a field row writes: provider and mode, with `config` carried
 * through from the row (`{}` for a new one). Null for a row without a source.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-a-maker-declares-that-a-field-takes-its-value-from-a-registry-req-bqrf-001
 * @param {object|null} source The row's source.
 * @return {{provider: string, mode: string, config: object}|null} The declaration.
 */
export function propertySourceOf(source) {
	if (!source || typeof source !== 'object') {
		return null
	}
	return {
		provider: String(source.provider || ''),
		mode: typeof source.mode === 'string' && source.mode !== '' ? source.mode : 'live',
		config: isPlainObject(source.config) ? clone(source.config) : {},
	}
}

/**
 * The text the fields list shows for a source: "{label}, live" or
 * "{label}, starting value". The provider id stands in for a label integriq
 * did not publish.
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003
 * @param {object|null} source The row's source.
 * @param {Array<object>} providers The providers integriq publishes.
 * @return {string} The text, empty without a source.
 */
export function sourceSummary(source, providers) {
	if (!source || !source.provider) {
		return ''
	}
	const label = providerLabel(source.provider, providers)
	if (source.mode === 'default') {
		return t('buildiq', '{label}, starting value', { label })
	}
	return t('buildiq', '{label}, live', { label })
}

/**
 * The label of a provider, or its id when integriq did not publish it.
 *
 * @param {string} id The provider id.
 * @param {Array<object>} providers The providers integriq publishes.
 * @return {string} The label.
 */
export function providerLabel(id, providers) {
	const found = (Array.isArray(providers) ? providers : []).find(
		(entry) => entry.id === id,
	)
	return (found && found.label) || id
}

/**
 * Map a refused schema save onto the fields whose property-source declaration
 * it names. OpenRegister quotes the property path in the message
 * (`'x-openregister-property-source' at 'properties.kvkNummer' ...`).
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md#requirement-the-fields-table-shows-the-registry-source-and-a-refusal-lands-on-its-field-req-bqrf-003
 * @param {object|string|null} err The store's error for the save.
 * @param {Array<string>} names The field names of the schema.
 * @return {Object<string, Array<string>>} Messages per field name.
 */
export function sourceRefusalsByField(err, names) {
	const out = {}
	for (const message of refusalMessages(err)) {
		if (!message.includes(PROPERTY_SOURCE_KEY)) {
			continue
		}
		for (const name of names) {
			if (name && namesPath(message, name)) {
				out[name] = [...(out[name] || []), message]
			}
		}
	}
	return out
}

/**
 * Every message a refused save carries: the field list, the details, the message.
 *
 * @param {object|string|null} err The store's error.
 * @return {Array<string>} The messages.
 */
function refusalMessages(err) {
	if (typeof err === 'string') {
		return [err]
	}
	if (!err || typeof err !== 'object') {
		return []
	}
	const messages = []
	for (const entry of Array.isArray(err.fields) ? err.fields : []) {
		if (entry && entry.message) messages.push(String(entry.message))
	}
	for (const key of ['details', 'message']) {
		if (typeof err[key] === 'string' && err[key] !== '') messages.push(err[key])
	}
	return messages
}

/**
 * Whether a message quotes the path of a property: `'name'`, or a dotted path
 * ending in it (`'properties.name'`).
 *
 * @param {string} message The refusal message.
 * @param {string} name The property name.
 * @return {boolean} True when the message is about that property.
 */
function namesPath(message, name) {
	const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	return new RegExp(`'(?:[^'\\s]*[./])?${escaped}'`).test(message)
}

/**
 * Whether a value is a plain object.
 *
 * @param {unknown} value The value.
 * @return {boolean} True for a non-array object.
 */
function isPlainObject(value) {
	return !!value && typeof value === 'object' && !Array.isArray(value)
}

/**
 * A deep copy of JSON data.
 *
 * @param {object} value The value.
 * @return {object} The copy.
 */
function clone(value) {
	return JSON.parse(JSON.stringify(value))
}
