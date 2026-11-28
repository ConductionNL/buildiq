/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-CWR-002 "Both bindings on one widget are rejected": a widget that
 * declares an OpenRegister source and an `endpointSource` at once is refused
 * by the canonical manifest validator the designer runs before a save, with a
 * message naming the widget and saying that exactly one data binding is
 * allowed. The rule lives in `@conduction/nextcloud-vue`'s validator; this
 * spec pins that buildiq's validator entry point still enforces it.
 *
 * @spec openspec/changes/archive/2026-11-25-openbuild-connector-widget-runtime/specs/connector-widget-runtime/spec.md#requirement-req-cwr-002-normative-endpoint-binding-matrix-for-the-published-path
 */
import { validateManifest } from '@conduction/nextcloud-vue'
import { describe, expect, it } from 'vitest'

const V2 = 'https://conduction.nl/schemas/app-manifest-v2.schema.json'
const SOURCE = { register: 'permits', schema: 'permit' }
const ENDPOINT = { url: '/apps/openconnector/api/endpoint/permits', responsePath: 'results' }

/**
 * A v2 manifest with one dashboard page holding the given grid widget.
 *
 * @param {object} widget - the widget entry.
 * @return {object} the manifest.
 */
function v2With(widget) {
	return {
		$schema: V2,
		version: '1.0.0',
		menu: [],
		pages: [{ id: 'kpis', route: '/kpis', type: 'dashboard', title: 'KPIs', widgets: [widget] }],
	}
}

/**
 * A stat widget whose content carries the given bindings.
 *
 * @param {object} content - the widget content.
 * @return {object} the widget entry.
 */
function stat(content) {
	return { id: 'open-permits', widgetKey: 'stat', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2, props: { title: 'Open permits', content } }
}

/**
 * The validator's messages about the one-binding rule.
 *
 * @param {object} manifest - the manifest to validate.
 * @return {string[]} the matching errors.
 */
function bindingErrors(manifest) {
	return validateManifest(manifest).errors.filter((e) => /exactly one of the two data bindings/.test(String(e)))
}

describe('one data binding per widget (REQ-CWR-002)', () => {
	it('rejects a widget that declares a source and an endpointSource, naming the widget', () => {
		const errors = bindingErrors(v2With(stat({ source: SOURCE, endpointSource: ENDPOINT })))

		expect(errors).toHaveLength(1)
		expect(errors[0]).toContain('pages[0]/widgets[0]')
		expect(errors[0]).toMatch(/exactly one of the two data bindings is allowed/)
	})

	it('accepts the same widget with only the endpointSource', () => {
		expect(bindingErrors(v2With(stat({ endpointSource: ENDPOINT })))).toEqual([])
	})

	it('accepts the same widget with only the OpenRegister source', () => {
		expect(bindingErrors(v2With(stat({ source: SOURCE })))).toEqual([])
	})

	it('does not count the empty source the widget editor seeds as a binding', () => {
		const seeded = { register: '', schema: '' }

		expect(bindingErrors(v2With(stat({ source: seeded, endpointSource: ENDPOINT })))).toEqual([])
	})
})
