/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/manifestPageDefaults.js: page config and title
 * defaults applied to a loaded manifest for the standalone runtime.
 */
import { describe, expect, it } from 'vitest'
import { normalizeManifestPages } from '../../src/services/manifestPageDefaults.js'

describe('normalizeManifestPages', () => {
	it('replaces a missing, array or scalar config with an object', () => {
		const manifest = {
			pages: [
				{ type: 'custom' },
				{ type: 'custom', config: [] },
				{ type: 'custom', config: 'x' },
				{ type: 'custom', config: null },
				{ type: 'custom', config: { a: 1 } },
			],
		}
		normalizeManifestPages(manifest)
		expect(manifest.pages.map((p) => p.config)).toEqual([{}, {}, {}, {}, { a: 1 }])
	})

	it('defaults data pages to a visible title and keeps an explicit one', () => {
		const manifest = {
			pages: [
				{ type: 'index', config: [] },
				{ type: 'detail' },
				{ type: 'index', config: { showTitle: false } },
				{ type: 'detail', config: { showTitle: null } },
				{ type: 'dashboard', config: {} },
			],
		}
		normalizeManifestPages(manifest)
		expect(manifest.pages.map((p) => p.config.showTitle)).toEqual([
			true,
			true,
			false,
			null,
			undefined,
		])
	})

	it('skips entries that are not objects and keeps their identity', () => {
		const shared = { type: 'index', config: {} }
		const manifest = { pages: [null, 'x', 3, shared] }
		normalizeManifestPages(manifest)
		expect(manifest.pages.slice(0, 3)).toEqual([null, 'x', 3])
		expect(manifest.pages[3]).toBe(shared)
		expect(shared.config).toEqual({ showTitle: true })
	})

	it('leaves a manifest without a page list alone', () => {
		const manifest = { pages: { a: { type: 'index' } } }
		normalizeManifestPages(manifest)
		expect(manifest).toEqual({ pages: { a: { type: 'index' } } })
		const empty = {}
		normalizeManifestPages(empty)
		expect(empty).toEqual({})
	})
})
