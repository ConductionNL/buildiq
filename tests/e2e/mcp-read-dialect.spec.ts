/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The read-only MCP dialect survives the register import (openbuild-mcp-adoption
 * task 4).
 *
 * `tests/Unit/Settings/McpReadDialectTest.php` pins the dialect on the merged
 * register in memory. This spec asserts it on the schemas OpenRegister actually
 * imported: OpenRegister folds a top-level `x-openregister-mcp` block into the
 * schema's `configuration`, so each of the five schemas must answer it there with
 * `search` and `get` only, read scope, and the declared filters, including the
 * two (`Application`, `exportJob`) that register.d overlays re-declare.
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-curated-read-only-dialect-on-five-schemas
 * @e2e openbuild-mcp-surface/requirement-a-curated-read-only-dialect-on-five-schemas/declared-filters-survive-the-register-d-union-merge
 */

import { expect, test } from '@playwright/test'

const SCHEMAS = '/index.php/apps/openregister/api/schemas'
const HEADERS = { 'OCS-APIRequest': 'true' }

// Schema slug → the filters its `search` verb declares.
const DIALECT: Record<string, string[]> = {
	'built-app': ['status', 'appType', 'slug'],
	applicationVersion: ['application', 'status', 'semver'],
	'application-template': ['category', 'useCase', 'slug'],
	'export-job': ['status', 'target', 'applicationUuid'],
	automation: ['applicationSlug', 'enabled', 'trigger'],
}

test.describe('the read-only MCP dialect on the imported register', () => {
	for (const [slug, filters] of Object.entries(DIALECT)) {
		// @e2e openbuild-mcp-surface/requirement-a-curated-read-only-dialect-on-five-schemas/declared-filters-survive-the-register-d-union-merge
		test(`${slug} keeps a search and get dialect with its filters`, async ({
			request,
		}) => {
			const response = await request.get(`${SCHEMAS}/${slug}`, {
				headers: HEADERS,
			})
			test.skip(
				response.status() === 404,
				'OpenRegister is not installed here, or the buildiq register is not imported',
			)
			expect(response.ok(), `GET ${SCHEMAS}/${slug}`).toBeTruthy()

			const schema = await response.json()
			const dialect =
				schema?.configuration?.['x-openregister-mcp'] ??
				schema?.['x-openregister-mcp']
			expect(
				dialect,
				`${slug} lost its x-openregister-mcp block on import`,
			).toBeTruthy()
			expect(Object.keys(dialect.tools).sort()).toEqual(['get', 'search'])
			for (const verb of ['search', 'get']) {
				expect(dialect.tools[verb].scope).toBe('read')
				expect(dialect.tools[verb].readOnlyHint).toBe(true)
			}
			expect(dialect.tools.search.filters).toEqual(filters)
		})
	}
})
