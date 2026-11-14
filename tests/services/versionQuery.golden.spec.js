/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `withVersion` over every location and version slug in two
 * pools. The returned location of every pair, and whether it is the same
 * object, is pinned.
 *
 * @spec openspec/specs/version-routing/spec.md#requirement-browser-reload-preserves-_version-bookmarkability
 */
import { describe, expect, it } from 'vitest'
import { withVersion } from '../../src/services/versionQuery.js'

const LOCATIONS = [
	undefined,
	null,
	'',
	'/list',
	'/list?page=2',
	'/list#top',
	'/list?page=2#top',
	'/list?_version=old',
	'/list?a=1&_version=old',
	'/list?my_version=1',
	'#only',
	7,
	true,
	{ name: 'list' },
	{ name: 'list', query: { page: '2' } },
	{ name: 'list', query: { _version: 'old' } },
	{ name: 'list', query: { _version: '' } },
	{ path: '/x', query: null },
	[],
]

const VERSIONS = [undefined, '', 'development', 'a b&c', 0]

describe('withVersion golden', () => {
	it('pins the location of every pair', () => {
		const lines = []
		for (const versionSlug of VERSIONS) {
			for (const to of LOCATIONS) {
				const result = withVersion(to, versionSlug)
				lines.push(
					`${JSON.stringify(to)} ${JSON.stringify(versionSlug)} => ${result === to} ${JSON.stringify(result)}`,
				)
			}
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
