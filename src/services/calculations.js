// SPDX-License-Identifier: EUPL-1.2
/**
 * calculations: author calculated fields against OpenRegister's engine.
 *
 * OpenRegister accepts a `calculation` key on a single schema property, the
 * `{type, expression}` object its `x-openregister-calculations` annotation
 * also holds. The expression is a JSON AST: a bare scalar is a literal,
 * `{prop: name}` reads a field, and `{op: [args]}` calls an operator from the
 * published catalogue. A save carrying a wrong declaration is refused with
 * 422 and a list of `{code, message}` errors whose message quotes the name.
 *
 * @module services/calculations
 * @spec openspec/specs/data-calculated-fields/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The key OpenRegister reads a property-level calculation from. */
export const CALCULATION_KEY = 'calculation'

/** The declaration types OpenRegister's validator accepts. */
export const CALCULATION_TYPES = ['string', 'integer', 'number', 'boolean', 'date']

/**
 * Load the operator catalogue OpenRegister publishes.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
 * @return {Promise<{operators: Array<object>, categories: Array<string>}|null>} The catalogue, or null when OpenRegister does not publish one.
 */
export async function loadOperatorCatalogue() {
	try {
		const { data } = await axios.get(
			generateUrl('/apps/openregister/api/schemas/calculation-operators'),
		)
		if (!data || !Array.isArray(data.operators)) {
			return null
		}
		return {
			operators: data.operators,
			categories: Array.isArray(data.categories) ? data.categories : [],
		}
	} catch {
		return null
	}
}

/**
 * Evaluate an unsaved declaration against a sample record. Nothing is saved.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
 * @param {object} declaration The `{type, expression}` declaration.
 * @param {object} sample The sample record's values.
 * @return {Promise<{ok: boolean, value: *, error: object|null}>} The value, or the refusal.
 */
export async function tryCalculation(declaration, sample) {
	try {
		const { data } = await axios.post(
			generateUrl('/apps/openregister/api/schemas/calculation-evaluate'),
			{ calculation: declaration, object: sample || {} },
		)
		return trialAnswer(data)
	} catch (e) {
		return trialFailure(e)
	}
}

/**
 * The trial result of an answered evaluation.
 *
 * @param {object|undefined} data The response body.
 * @return {{ok: boolean, value: unknown, error: object|null}} The value, or the refusal.
 */
function trialAnswer(data) {
	const ok = data?.ok === true
	return {
		ok,
		value: data?.value ?? null,
		error: ok ? null : (data?.error ?? null),
	}
}

/**
 * The trial result of a failed request: the refusal the server sent, or a
 * request failure carrying the error's message.
 *
 * @param {Error|object|null} e The rejection.
 * @return {{ok: boolean, value: null, error: object}} The refusal.
 */
function trialFailure(e) {
	return {
		ok: false,
		value: null,
		error: e?.response?.data?.error ?? {
			code: 'request-failed',
			message: e?.message || '',
		},
	}
}

/**
 * Map a refused save's errors onto the calculation names they quote.
 *
 * OpenRegister quotes the name in every message (`Calculation "total" ...`)
 * and lists the names of a cycle as `a -> b -> a`.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-a-refused-save-is-shown-on-its-field-req-bqcf-004
 * @param {Array<{code: string, message: string}>|unknown} errors The refusal list.
 * @param {Array<string>} names The calculation names to map onto.
 * @return {Object<string, Array<string>>} Messages per name.
 */
export function refusalsByProperty(errors, names) {
	const out = {}
	for (const error of Array.isArray(errors) ? errors : []) {
		const message = String(error?.message || '')
		for (const name of names.filter((n) => mentions(message, n))) {
			appendTo(out, { name, message })
		}
	}
	return out
}

/**
 * Whether a refusal message names a calculation: quoted, or as a step of a
 * cycle (`a -> b -> a`).
 *
 * @param {string} message The refusal message.
 * @param {string} name The calculation name.
 * @return {boolean} True when the message is about that name.
 */
function mentions(message, name) {
	return (
		message.includes(`"${name}"`)
		|| new RegExp(
			`(^|[\\s:])${escapeRegExp(name)} ->|-> ${escapeRegExp(name)}(\\W|$)`,
		).test(message)
	)
}

/**
 * Add a message to the list kept for a name.
 *
 * @param {Object<string, Array<string>>} out Messages per name.
 * @param {{name: string, message: string}} entry The name and its message.
 * @return {void}
 */
function appendTo(out, entry) {
	out[entry.name] = [...(out[entry.name] || []), entry.message]
}

/**
 * Escape a string for use inside a regular expression.
 *
 * @param {string} value Raw text.
 * @return {string} Escaped text.
 */
function escapeRegExp(value) {
	return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/**
 * Read the property-level calculations out of a schema's properties.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
 * @param {object} properties The schema's `properties` map.
 * @return {Object<string, {type: string, expression: *}>} Declarations by property name.
 */
export function calculationsFromProperties(properties) {
	const out = {}
	for (const [name, prop] of Object.entries(properties || {})) {
		const calc = calculationOf(prop)
		if (calc) {
			out[name] = { type: calc.type, expression: calc.expression }
		}
	}
	return out
}

/**
 * The `calculation` object a property carries, or null.
 *
 * @param {unknown} prop A schema property.
 * @return {object|null} The declaration.
 */
function calculationOf(prop) {
	const calc = prop && typeof prop === 'object' ? prop[CALCULATION_KEY] : null
	return calc && typeof calc === 'object' ? calc : null
}

/**
 * The JSON Schema property a new calculated field of a type gets.
 *
 * @param {string} type A declaration type.
 * @return {object} The property's type keys.
 */
function propertyForType(type) {
	if (type === 'date') {
		return { type: 'string', format: 'date' }
	}
	return { type: CALCULATION_TYPES.includes(type) ? type : 'string' }
}

/**
 * Write calculations onto a schema's properties.
 *
 * Every existing `calculation` key is dropped first, so a removed calculation
 * leaves its property as a plain field. A calculation on a name the schema
 * does not have yet adds that property, typed from the declaration, at the
 * end of the property order. The input is not changed.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
 * @param {object} properties The composed `properties` map.
 * @param {Array<string>} order The composed property order.
 * @param {Object<string, {type: string, expression: *}>} calculations Declarations by name.
 * @return {{properties: object, order: Array<string>}} The new map and order.
 */
export function applyCalculations(properties, order, calculations) {
	const target = {
		properties: withoutCalculations(properties),
		order: [...(order || [])],
	}
	for (const entry of Object.entries(calculations || {})) {
		if (entry[0]) {
			placeCalculation(target, entry)
		}
	}
	return target
}

/**
 * Copies of the properties with every `calculation` key dropped.
 *
 * @param {object} properties The composed `properties` map.
 * @return {object} The copied map.
 */
function withoutCalculations(properties) {
	const out = {}
	for (const [name, prop] of Object.entries(properties || {})) {
		const copy = { ...prop }
		delete copy[CALCULATION_KEY]
		out[name] = copy
	}
	return out
}

/**
 * Write one calculation onto the target, adding its property (typed from the
 * declaration, at the end of the order) when the schema does not have it.
 *
 * @param {{properties: object, order: Array<string>}} target The map and order being written.
 * @param {[string, {type: string, expression: unknown}]} entry The name and its declaration.
 * @return {void}
 */
function placeCalculation(target, entry) {
	const [name, calc] = entry
	if (!target.properties[name]) {
		target.properties[name] = propertyForType(calc.type)
		if (!target.order.includes(name)) {
			target.order.push(name)
		}
	}
	target.properties[name][CALCULATION_KEY] = {
		type: calc.type,
		expression: calc.expression,
	}
}

/**
 * The field names an expression reads, in first-read order.
 *
 * @spec openspec/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
 * @param {unknown} expression The expression AST.
 * @return {Array<string>} Field names.
 */
export function referencedFields(expression) {
	const seen = []
	const walk = (node) => {
		if (!node || typeof node !== 'object') {
			return
		}
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		const op = Object.keys(node)[0]
		if (op === 'prop') {
			noteField(seen, propName(node[op]))
			return
		}
		walk(node[op])
	}
	walk(expression)
	return seen
}

/**
 * The field name a `prop` node reads.
 *
 * @param {unknown} args The node's arguments.
 * @return {unknown} The name.
 */
function propName(args) {
	return Array.isArray(args) ? args[0] : args
}

/**
 * Note a field name once, in first-read order.
 *
 * @param {Array<string>} seen The names read so far.
 * @param {unknown} name The name a `prop` node reads.
 * @return {void}
 */
function noteField(seen, name) {
	if (typeof name === 'string' && name !== '' && !seen.includes(name)) {
		seen.push(name)
	}
}
