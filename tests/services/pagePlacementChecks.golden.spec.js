import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/dist/esm/utils/libraryWidgetKeys.js'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `customPageInDisguiseKey` over generated pages. Page type,
 * widget count and every widget key are drawn from pools, and the returned key
 * of every case is pinned.
 *
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
import { describe, expect, it } from 'vitest'
import { customPageInDisguiseKey } from '../../src/services/pagePlacementChecks.js'

const ABSENT = Symbol('absent')

/**
 * A small deterministic PRNG (mulberry32).
 *
 * @param {number} seed The seed.
 *
 * @return {function(): number} Returns floats in [0, 1).
 */
function mulberry32(seed) {
	let a = seed
	return () => {
		a = (a + 0x6d2b79f5) | 0
		let t = Math.imul(a ^ (a >>> 15), 1 | a)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

/**
 * Pick one element of a list.
 *
 * @param {function(): number} rand The PRNG.
 * @param {Array} list The list.
 *
 * @return {*} The element.
 */
function pick(rand, list) {
	return list[Math.floor(rand() * list.length)]
}

/**
 * Build one object from pools of values per key; ABSENT leaves a key out.
 *
 * @param {function(): number} rand The PRNG.
 * @param {object} pools The pool per key.
 *
 * @return {object} The object.
 */
function objectFrom(rand, pools) {
	const out = {}
	for (const [key, pool] of Object.entries(pools)) {
		const value = pick(rand, pool)
		if (value !== ABSENT) {
			out[key] = value
		}
	}
	return out
}

const WIDGET_POOLS = {
	slot: [ABSENT, 'body', 'body', 'sidebar'],
	gridX: [ABSENT, ABSENT, 0, 1, '0', Number.NaN],
	gridY: [ABSENT, ABSENT, 0, 2, null],
	gridWidth: [ABSENT, ABSENT, 12, 6, '12'],
	gridHeight: [ABSENT, ABSENT, 12, 4],
	widgetKey: [ABSENT, 'my-custom', 'my-custom', '', 7, LIBRARY_WIDGET_KEYS[0]],
}

/**
 * Build one page from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The page.
 */
function pageFrom(rand) {
	if (rand() < 0.05) {
		return pick(rand, [null, undefined, 0, 'page'])
	}
	const page = {}
	const type = pick(rand, [
		ABSENT,
		'dashboard',
		'dashboard',
		'dashboard',
		'detail',
		'custom',
	])
	if (type !== ABSENT) {
		page.type = type
	}
	const count = pick(rand, [ABSENT, 'list', 0, 1, 1, 1, 1, 2])
	if (count === 'list') {
		page.widgets = { 0: {} }
	} else if (count !== ABSENT) {
		page.widgets = Array.from({ length: count }, () =>
			rand() < 0.1
				? pick(rand, [null, 'widget', 0])
				: objectFrom(rand, WIDGET_POOLS),
		)
	}
	return page
}

describe('customPageInDisguiseKey golden', () => {
	it('pins the key of generated pages', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 800; i++) {
			const page = pageFrom(rand)
			lines.push(
				`${JSON.stringify(page)} => ${JSON.stringify(customPageInDisguiseKey(page))}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
