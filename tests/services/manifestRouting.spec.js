/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/manifestRouting.js: a manifest the runtime cannot
 * name is repaired before the router is built.
 *
 * Both cases were watched live on the demo instance before this fix:
 *  - an app installed from the GitHub store (pages with no `id`) rendered an
 *    empty shell: no menu, no page, no error;
 *  - an app made from a built-in template (menu entries carrying paths) showed
 *    no navigation at all and logged one router error per entry.
 */
import { describe, expect, it } from 'vitest'

const { normalizeManifestRouting } = await import(
	'../../src/services/manifestRouting.js'
)

describe('normalizeManifestRouting', () => {
	it('names a page that carries no id after its own route', () => {
		const manifest = {
			pages: [
				{ route: '/report', type: 'form' },
				{ route: '/incidents', type: 'index' },
				{ route: '/incidents/:id', type: 'detail' },
			],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.pages.map((p) => p.id)).toEqual([
			'/report',
			'/incidents',
			'/incidents/:id',
		])
	})

	it('leaves a page that already has an id alone', () => {
		const manifest = { pages: [{ id: 'Incidents', route: '/incidents' }] }

		normalizeManifestRouting(manifest)

		expect(manifest.pages[0].id).toBe('Incidents')
	})

	it('repoints a menu entry that carries a page path at that page', () => {
		const manifest = {
			menu: [
				{ label: 'Applications', route: '/applications' },
				{ label: 'Submit', route: '/submit' },
			],
			pages: [
				{ id: 'Applications', route: '/applications' },
				{ id: 'ApplicationForm', route: '/submit' },
			],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.menu.map((m) => m.route)).toEqual([
			'Applications',
			'ApplicationForm',
		])
	})

	it('repoints entries nested in a menu group', () => {
		const manifest = {
			menu: [
				{
					label: 'Work',
					children: [{ label: 'Incidents', route: '/incidents' }],
				},
			],
			pages: [{ id: 'Incidents', route: '/incidents' }],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.menu[0].children[0].route).toBe('Incidents')
	})

	it('leaves a menu entry that already names a page', () => {
		const manifest = {
			menu: [{ label: 'Messages', route: 'Messages' }],
			pages: [{ id: 'Messages', route: '/' }],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.menu[0].route).toBe('Messages')
	})

	it('leaves an entry no page answers to, rather than guessing', () => {
		const manifest = {
			menu: [{ label: 'Elsewhere', route: '/elsewhere' }],
			pages: [{ id: 'Incidents', route: '/incidents' }],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.menu[0].route).toBe('/elsewhere')
	})

	it('makes an id-less page reachable from its own menu entry', () => {
		const manifest = {
			menu: [{ label: 'Report', route: '/report' }],
			pages: [{ route: '/report', type: 'form' }],
		}

		normalizeManifestRouting(manifest)

		expect(manifest.pages[0].id).toBe('/report')
		expect(manifest.menu[0].route).toBe('/report')
	})

	it('survives a manifest with nothing in it', () => {
		expect(normalizeManifestRouting(null)).toBe(null)
		expect(normalizeManifestRouting({})).toEqual({})
		expect(normalizeManifestRouting({ pages: 'no', menu: 3 })).toEqual({
			pages: 'no',
			menu: 3,
		})
	})
})
