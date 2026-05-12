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

	// Every name the manifest references against the customComponents registry:
	// `type: custom` pages' `component`, plus index pages' `config.cardComponent`,
	// detail pages' `config.sidebarTabs[].component` and `config.actionsComponent`.
	const referencedComponents = () => {
		const refs = new Set()
		for (const page of manifest.pages) {
			if (page.type === 'custom' && typeof page.component === 'string') {
				refs.add(page.component)
			}
			const cfg = page.config || {}
			if (typeof cfg.cardComponent === 'string') {
				refs.add(cfg.cardComponent)
			}
			if (typeof cfg.actionsComponent === 'string') {
				refs.add(cfg.actionsComponent)
			}
			if (typeof cfg.headerComponent === 'string') {
				refs.add(cfg.headerComponent)
			}
			for (const tab of cfg.sidebarTabs || []) {
				if (typeof tab.component === 'string') {
					refs.add(tab.component)
				}
			}
		}
		return refs
	}

	it('every component the manifest references resolves to a registered component', () => {
		for (const name of referencedComponents()) {
			expect(customComponents, `manifest references "${name}"`).toHaveProperty(name)
		}
	})

	it('has no unused customComponents entries', () => {
		const referenced = referencedComponents()
		for (const name of Object.keys(customComponents)) {
			expect(referenced, `customComponents.${name} is unreferenced`).toContain(name)
		}
	})
})
