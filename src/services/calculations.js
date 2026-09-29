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
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md
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
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
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
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
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
		return {
			ok: data?.ok === true,
			value: data?.value ?? null,
			error: data?.ok === true ? null : (data?.error ?? null),
		}
	} catch (e) {
		const body = e?.response?.data
		return {
			ok: false,
			value: null,
			error: body?.error ?? { code: 'request-failed', message: e?.message || '' },
		}
	}
}

/**
 * Map a refused save's errors onto the calculation names they quote.
 *
 * OpenRegister quotes the name in every message (`Calculation "total" ...`)
 * and lists the names of a cycle as `a -> b -> a`.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-refused-save-is-shown-on-its-field-req-bqcf-004
 * @param {Array<{code: string, message: string}>|*} errors The refusal list.
 * @param {Array<string>} names The calculation names to map onto.
 * @return {Object<string, Array<string>>} Messages per name.
 */
export function refusalsByProperty(errors, names) {
	const out = {}
	if (!Array.isArray(errors)) {
		return out
	}
	for (const error of errors) {
		const message = String(error?.message || '')
		for (const name of names) {
			const quoted = message.includes(`"${name}"`)
			const inCycle = new RegExp(`(^|[\\s:])${escapeRegExp(name)} ->|-> ${escapeRegExp(name)}(\\s|$)`).test(message)
			if (quoted || inCycle) {
				out[name] = [...(out[name] || []), message]
			}
		}
	}
	return out
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
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
 * @param {object} properties The schema's `properties` map.
 * @return {Object<string, {type: string, expression: *}>} Declarations by property name.
 */
export function calculationsFromProperties(properties) {
	const out = {}
	for (const [name, prop] of Object.entries(properties || {})) {
		const calc = prop && typeof prop === 'object' ? prop[CALCULATION_KEY] : null
		if (calc && typeof calc === 'object') {
			out[name] = { type: calc.type, expression: calc.expression }
		}
	}
	return out
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
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
 * @param {object} properties The composed `properties` map.
 * @param {Array<string>} order The composed property order.
 * @param {Object<string, {type: string, expression: *}>} calculations Declarations by name.
 * @return {{properties: object, order: Array<string>}} The new map and order.
 */
export function applyCalculations(properties, order, calculations) {
	const out = {}
	for (const [name, prop] of Object.entries(properties || {})) {
		const copy = { ...prop }
		delete copy[CALCULATION_KEY]
		out[name] = copy
	}
	const nextOrder = [...(order || [])]
	for (const [name, calc] of Object.entries(calculations || {})) {
		if (!name) {
			continue
		}
		if (!out[name]) {
			out[name] = propertyForType(calc.type)
			if (!nextOrder.includes(name)) {
				nextOrder.push(name)
			}
		}
		out[name][CALCULATION_KEY] = { type: calc.type, expression: calc.expression }
	}
	return { properties: out, order: nextOrder }
}

/**
 * The field names an expression reads, in first-read order.
 *
 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
 * @param {*} expression The expression AST.
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
		const args = node[op]
		if (op === 'prop') {
			const name = Array.isArray(args) ? args[0] : args
			if (typeof name === 'string' && name !== '' && !seen.includes(name)) {
				seen.push(name)
			}
			return
		}
		walk(args)
	}
	walk(expression)
	return seen
}
