/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The endpoint-binding matrix (connector-widget-runtime REQ-CWR-002 /
 * REQ-CWR-003), checked against the vendored canonical manifest schema
 * `@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json` — read
 * from disk, never restated here.
 *
 *   | widget key                      | binding location                         |
 *   | object-table, workspace-filter* | props.endpointSource                     |
 *   | stat, delta                     | props.content.endpointSource             |
 *   | chart                           | props.endpointSource ($defs/chartEndpointSource) |
 *   | stats-block                     | none — entries[] are register-bound      |
 *
 *   * workspace-filter nests it at props.content.endpointSource.
 *
 * `connector-data` binds through the widget-level `dataSource.connector`
 * block (REQ-OCAS-001) and is resolved from the runtime registry, so it is not
 * a schema-declared `endpointSource` key and is covered by
 * runtimeRegistryParity.spec.js instead.
 *
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-002-normative-endpoint-binding-matrix-for-the-published-path
 * @spec openspec/changes/openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-003-stats-block-remains-register-bound-with-a-stated-rationale
 */
import Ajv from 'ajv/dist/2020.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const SCHEMA_PATH = resolve(
	__dirname,
	'../../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest-v2.schema.json',
)
const schema = JSON.parse(readFileSync(SCHEMA_PATH, 'utf-8'))

const ajv = new Ajv.default({ allErrors: true, strict: false })
ajv.addSchema(schema)
const validateWidget = ajv.compile({ $ref: `${schema.$id}#/$defs/widgetEntry` })

// The normative matrix: widget key → [path under the widget to the binding,
// the $defs the binding must reference].
const MATRIX = {
	'object-table': [['props', 'endpointSource'], '#/$defs/endpointSource'],
	chart: [['props', 'endpointSource'], '#/$defs/chartEndpointSource'],
	stat: [['props', 'content', 'endpointSource'], '#/$defs/endpointSource'],
	delta: [['props', 'content', 'endpointSource'], '#/$defs/endpointSource'],
	'workspace-filter': [
		['props', 'content', 'endpointSource'],
		'#/$defs/endpointSource',
	],
}

/**
 * The widget keys a conditional `widgetEntry.allOf[]` branch applies to.
 *
 * @param {object} branch One `allOf` entry.
 * @return {string[]} the keys named by its `if.properties.widgetKey`.
 */
function branchKeys(branch) {
	const key = branch?.if?.properties?.widgetKey
	if (!key) {
		return []
	}
	if (typeof key.const === 'string') {
		return [key.const]
	}
	return Array.isArray(key.enum) ? key.enum : []
}

/**
 * Every place under a schema node that declares an `endpointSource` property,
 * as the widget-relative path to it.
 *
 * @param {object} node A schema node (a branch's `then`).
 * @param {string[]} [path] Widget-relative path so far.
 * @return {Array<{path: string[], ref: string}>} the declarations found.
 */
function endpointDeclarations(node, path = []) {
	const found = []
	const props = node && typeof node === 'object' ? node.properties : null
	if (!props) {
		return found
	}
	for (const [name, child] of Object.entries(props)) {
		if (name === 'endpointSource') {
			found.push({ path: [...path, name], ref: child.$ref })
		} else {
			found.push(...endpointDeclarations(child, [...path, name]))
		}
	}
	return found
}

/**
 * The schema's endpoint-binding declarations, keyed by widget key.
 *
 * @return {Map<string, Array<{path: string[], ref: string}>>} key → declarations.
 */
function schemaMatrix() {
	const matrix = new Map()
	for (const branch of schema.$defs.widgetEntry.allOf) {
		const declarations = endpointDeclarations(branch.then)
		if (declarations.length === 0) {
			continue
		}
		for (const key of branchKeys(branch)) {
			matrix.set(key, [...(matrix.get(key) || []), ...declarations])
		}
	}
	return matrix
}

/**
 * A minimal widget entry carrying `value` at `path`.
 *
 * @param {string} widgetKey The widget key.
 * @param {string[]} path Widget-relative path to set.
 * @param {*} value The value to place there.
 * @return {object} the widget entry.
 */
function widgetWith(widgetKey, path, value) {
	const widget = {
		widgetKey,
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 6,
		gridHeight: 4,
	}
	let cursor = widget
	for (const segment of path.slice(0, -1)) {
		cursor[segment] = cursor[segment] || {}
		cursor = cursor[segment]
	}
	cursor[path[path.length - 1]] = value
	return widget
}

/**
 * Validation errors located at or below a widget-relative path.
 *
 * @param {object} widget The widget entry to validate.
 * @param {string[]} path Widget-relative path.
 * @return {object[]} the Ajv errors under that path.
 */
function errorsAt(widget, path) {
	validateWidget(widget)
	const pointer = '/' + path.join('/')
	return (validateWidget.errors || []).filter(
		(e) => e.instancePath === pointer || e.instancePath.startsWith(pointer + '/'),
	)
}

describe('endpoint-binding matrix (REQ-CWR-002)', () => {
	it('the schema declares an endpointSource for exactly the normative widget keys', () => {
		expect([...schemaMatrix().keys()].sort()).toEqual(
			Object.keys(MATRIX).sort(),
		)
	})

	it.each(Object.entries(MATRIX))(
		'%s declares its endpointSource at the documented location',
		(key, [path, ref]) => {
			const declared = schemaMatrix().get(key)
			expect(declared).toEqual([{ path, ref }])
		},
	)

	it.each(Object.entries(MATRIX))(
		'%s accepts a well-formed endpointSource at that location',
		(key, [path]) => {
			const widget = widgetWith(key, path, {
				url: '/apps/example/api/items',
				responsePath: 'results',
			})
			expect(errorsAt(widget, path)).toEqual([])
		},
	)

	it.each(Object.entries(MATRIX))(
		'%s validates the endpointSource it accepts (a url-less binding is refused)',
		(key, [path]) => {
			const widget = widgetWith(key, path, { responsePath: 'results' })
			const errors = errorsAt(widget, path)
			expect(errors.map((e) => e.params.missingProperty)).toContain('url')
		},
	)
})

describe('stats-block stays register-bound (REQ-CWR-003)', () => {
	it('declares no endpoint binding anywhere', () => {
		expect(schemaMatrix().has('stats-block')).toBe(false)
	})

	it('requires register + schema and forbids extra keys on each entry', () => {
		const entry = schema.$defs.statsBlockEntry
		expect(entry.additionalProperties).toBe(false)
		expect(entry.required).toEqual(
			expect.arrayContaining(['register', 'schema']),
		)
		expect(entry.properties).not.toHaveProperty('endpointSource')
	})

	it('rejects an endpointSource inside props.entries[], naming the offending entry', () => {
		const widget = widgetWith('stats-block', ['props', 'entries'], [
			{ register: 'r', schema: 's', title: 'Fine' },
			{
				register: 'r',
				schema: 's',
				endpointSource: { url: '/apps/example/api/kpi' },
			},
		])
		expect(validateWidget(widget)).toBe(false)
		const offending = validateWidget.errors.filter(
			(e) => e.keyword === 'additionalProperties',
		)
		expect(offending).toHaveLength(1)
		expect(offending[0].instancePath).toBe('/props/entries/1')
		expect(offending[0].params.additionalProperty).toBe('endpointSource')
	})

	it('accepts the same entries without the endpoint binding', () => {
		const widget = widgetWith('stats-block', ['props', 'entries'], [
			{ register: 'r', schema: 's', title: 'Fine' },
		])
		expect(errorsAt(widget, ['props', 'entries'])).toEqual([])
	})
})
