/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `buildVersionedRoute` helper.
 *
 * Covers spec `openbuilt-version-routing` REQ-OBVR-006 and tasks.md §4.2:
 *  - version present → query contains _version
 *  - version absent (undefined, empty string) → query is empty
 *  - arbitrary params are forwarded verbatim
 *  - result shape always has name, params, query
 */

import { describe, it, expect } from 'vitest'
import { buildVersionedRoute } from '../../src/router/helpers.js'

describe('buildVersionedRoute (REQ-OBVR-006 — version forwarding)', () => {
	it('includes _version in query when currentVersion is provided', () => {
		const result = buildVersionedRoute('SchemaDesignerList', { slug: 'hello-world' }, 'staging')
		expect(result).toEqual({
			name: 'SchemaDesignerList',
			params: { slug: 'hello-world' },
			query: { _version: 'staging' },
		})
	})

	it('returns empty query when currentVersion is undefined', () => {
		const result = buildVersionedRoute('SchemaDesignerList', { slug: 'hello-world' }, undefined)
		expect(result.query).toEqual({})
	})

	it('returns empty query when currentVersion is an empty string', () => {
		const result = buildVersionedRoute('SchemaDesignerList', { slug: 'hello-world' }, '')
		expect(result.query).toEqual({})
	})

	it('returns empty query when currentVersion is not supplied', () => {
		const result = buildVersionedRoute('VirtualApps', {})
		expect(result.query).toEqual({})
	})

	it('forwards arbitrary route params verbatim', () => {
		const params = { slug: 'my-app', extra: 'value' }
		const result = buildVersionedRoute('PageDesigner', params, 'v2')
		expect(result.params).toEqual(params)
	})

	it('uses empty object for params when params not supplied', () => {
		const result = buildVersionedRoute('VirtualApps')
		expect(result.params).toEqual({})
	})

	it('always includes name, params, query properties', () => {
		const result = buildVersionedRoute('SomePage', { id: '1' }, 'prod')
		expect(result).toHaveProperty('name')
		expect(result).toHaveProperty('params')
		expect(result).toHaveProperty('query')
	})

	it('uses the exact underscore-prefix key _version (not version)', () => {
		const result = buildVersionedRoute('SomePage', {}, 'main')
		expect(result.query).toHaveProperty('_version')
		expect(result.query).not.toHaveProperty('version')
	})
})
