/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of `normalizeManifestRouting` over generated manifests: page ids
 * and routes, and menus with nested children, drawn from pools. The repaired
 * manifest of every case is pinned.
 *
 * @spec openspec/specs/openbuild-runtime/spec.md
 */
import { describe, expect, it } from 'vitest'
import { normalizeManifestRouting } from '../../src/services/manifestRouting.js'

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

const PAGE_POOLS = {
	id: [ABSENT, 'home', 'list', '', 4, 'cases'],
	route: [ABSENT, '/', '/list', '/cases', '', 7, 'home'],
}

const MENU_POOLS = {
	route: [ABSENT, '/', '/list', '/cases', 'home', 'list', '/ghost', '', 3],
	label: [ABSENT, 'Item'],
}

/**
 * Build a menu level, with children now and then.
 *
 * @param {function(): number} rand The PRNG.
 * @param {number} depth How deep this level sits.
 *
 * @return {Array} The entries.
 */
function menuFrom(rand, depth) {
	const length = Math.floor(rand() * 4)
	return Array.from({ length }, () => {
		if (rand() < 0.1) {
			return pick(rand, [null, 'entry', 0])
		}
		const item = objectFrom(rand, MENU_POOLS)
		if (depth < 2 && rand() < 0.3) {
			item.children = rand() < 0.2 ? 'none' : menuFrom(rand, depth + 1)
		}
		return item
	})
}

/**
 * Build one manifest from the pools.
 *
 * @param {function(): number} rand The PRNG.
 *
 * @return {*} The manifest.
 */
function manifestFrom(rand) {
	const manifest = {}
	const pages = rand()
	if (pages < 0.1) {
		manifest.pages = pick(rand, [null, {}, 'pages'])
	} else if (pages < 0.95) {
		manifest.pages = Array.from({ length: Math.floor(rand() * 5) }, () =>
			rand() < 0.1
				? pick(rand, [null, 'page', 0])
				: objectFrom(rand, PAGE_POOLS),
		)
	}
	const menu = rand()
	if (menu < 0.1) {
		manifest.menu = pick(rand, [null, {}, 'menu'])
	} else if (menu < 0.95) {
		manifest.menu = menuFrom(rand, 0)
	}
	return manifest
}

describe('normalizeManifestRouting golden', () => {
	it('pins the repaired manifest of generated manifests', () => {
		const rand = mulberry32(20261102)
		const lines = []
		for (let i = 0; i < 600; i++) {
			const manifest = manifestFrom(rand)
			const before = JSON.stringify(manifest)
			const result = normalizeManifestRouting(manifest)
			lines.push(
				`${before} => ${result === manifest} ${JSON.stringify(manifest)}`,
			)
		}
		expect(lines.join('\n')).toMatchSnapshot()
	})

	it('pins the non-manifest inputs', () => {
		const lines = [undefined, null, 0, '', 'manifest', true].map(
			(m) => `${String(m)} => ${String(normalizeManifestRouting(m))}`,
		)
		expect(lines.join('\n')).toMatchSnapshot()
	})
})
