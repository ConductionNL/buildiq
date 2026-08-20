/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Regression spec for adopt-shared-menu-pipeline (ADR-044 §1).
 *
 * Proves the no-functionality-loss invariant: with an all-empty
 * `menu-layout.json`, the shared `buildManifest(base, fragments, {})`
 * pipeline produces `pages`/`menu` arrays equal to the OLD bespoke
 * `mergeManifestFragments()` concatenation that used to live in
 * `src/main.js`. The base manifest + real `src/manifest.d/*.json`
 * fragments are fed to both paths and the outputs compared.
 *
 * The real shared util is imported via its deep path so the vitest
 * `@conduction/nextcloud-vue` stub alias (which only anchors the bare
 * specifier) does NOT shadow it.
 */

import { describe, it, expect } from 'vitest'
// eslint-disable-next-line n/no-extraneous-import
import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import baseManifest from '../../src/manifest.json'
import businessRulesFragment from '../../src/manifest.d/20-business-rules.json'
import placeholderFragment from '../../src/manifest.d/_placeholder.json'
import menuLayout from '../../src/menu-layout.json'

// The exact fragment set webpack's require.context('./manifest.d/', ...)
// resolves at build time (sorted by filename, README.md excluded).
const fragments = [placeholderFragment, businessRulesFragment]

/**
 * The old bespoke merge that lived in src/main.js before ADR-044 adoption —
 * plain concatenation of each fragment's pages/menu onto the base.
 *
 * @param {object} base The bundled base manifest.
 * @param {Array<object>} frags Fragment objects.
 * @return {object} merged manifest.
 */
function oldMergeManifestFragments(base, frags) {
	const merged = {
		...base,
		pages: [...(base.pages || [])],
		menu: [...(base.menu || [])],
	}
	frags.forEach((frag) => {
		if (Array.isArray(frag.pages)) {
			merged.pages.push(...frag.pages)
		}
		if (Array.isArray(frag.menu)) {
			merged.menu.push(...frag.menu)
		}
	})
	return merged
}

describe('adopt-shared-menu-pipeline regression (ADR-044 §1)', () => {
	it('menu-layout.json ships all keys empty/no-op', () => {
		expect(menuLayout.relocations).toEqual({})
		expect(menuLayout.removals).toEqual([])
		expect(menuLayout.settingsSection).toEqual([])
	})

	it('buildManifest with empty layout equals the old concat for pages', () => {
		const shared = buildManifest(baseManifest, fragments, menuLayout)
		const old = oldMergeManifestFragments(baseManifest, fragments)
		expect(shared.pages).toEqual(old.pages)
	})

	it('buildManifest with empty layout equals the old concat for menu', () => {
		const shared = buildManifest(baseManifest, fragments, menuLayout)
		const old = oldMergeManifestFragments(baseManifest, fragments)
		expect(shared.menu).toEqual(old.menu)
	})

	it('every base + fragment page id survives the shared pipeline', () => {
		const shared = buildManifest(baseManifest, fragments, menuLayout)
		const ids = shared.pages.map((p) => p.id)
		expect(ids).toContain('Dashboard')
		expect(ids).toContain('BusinessRules')
		// One route per id — no duplicates introduced by the merge.
		expect(new Set(ids).size).toBe(ids.length)
	})
})
