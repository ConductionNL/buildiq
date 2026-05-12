// SPDX-License-Identifier: EUPL-1.2
//
// Structural checks for OpenBuilt's own app manifest (ADR-024 Tier-1+):
// every menu entry routes to a real page, every custom page resolves a
// real customComponents entry, and the registry carries no dead entries.
// Catches the easy ways a manifest edit silently blanks a nav item or a
// route.

import { describe, it, expect } from 'vitest'
import manifest from '../../src/manifest.json'
import customComponents from '../../src/customComponents.js'

describe('src/manifest.json', () => {
	it('declares a version and the OpenRegister dependency', () => {
		expect(typeof manifest.version).toBe('string')
		expect(Array.isArray(manifest.dependencies)).toBe(true)
		expect(manifest.dependencies).toContain('openregister')
	})

	it('has unique page ids', () => {
		const ids = manifest.pages.map((p) => p.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('every menu entry with a route points at an existing page id', () => {
		const pageIds = new Set(manifest.pages.map((p) => p.id))
		for (const entry of manifest.menu) {
			if (entry.route === undefined) {
				// href / action entries don't reference a page.
				continue
			}
			expect(pageIds, `menu entry "${entry.id}" → "${entry.route}"`).toContain(entry.route)
		}
	})

	it('every custom page resolves a registered component', () => {
		for (const page of manifest.pages) {
			if (page.type !== 'custom') {
				continue
			}
			expect(typeof page.component, `page "${page.id}"`).toBe('string')
			expect(customComponents, `page "${page.id}" → "${page.component}"`).toHaveProperty(page.component)
		}
	})

	it('has no unused customComponents entries', () => {
		const referenced = new Set(
			manifest.pages.filter((p) => p.type === 'custom').map((p) => p.component),
		)
		for (const name of Object.keys(customComponents)) {
			expect(referenced, `customComponents.${name} is unreferenced`).toContain(name)
		}
	})
})
