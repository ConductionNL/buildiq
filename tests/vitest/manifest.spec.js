// SPDX-License-Identifier: EUPL-1.2
//
// Structural checks for OpenBuild's own app manifest (ADR-024 Tier-1+):
// every menu entry routes to a real page, every referenced component resolves
// to a real registry entry, and the registry carries no dead entries.
// Catches the easy ways a manifest edit silently blanks a nav item or a
// route. (The component map moved from customComponents.js to the kind-tagged
// registry.js per ADR-036; the keys are the same component names.)

import { describe, it, expect } from 'vitest'
import baseManifest from '../../src/manifest.json'
import registry from '../../src/registry.js'

// ADR-037: the runtime manifest is the base monolith with every
// src/manifest.d/*.json fragment's `pages`/`menu` arrays concatenated on
// (mirrors src/main.js mergeManifestFragments()). The structural checks must
// run against the merged manifest so fragment-only pages/components are seen.
const fragmentModules = import.meta.glob('../../src/manifest.d/*.json', { eager: true })
const manifest = {
	...baseManifest,
	menu: [...(baseManifest.menu || [])],
	pages: [...(baseManifest.pages || [])],
}
for (const key of Object.keys(fragmentModules).sort()) {
	const fragment = fragmentModules[key].default || fragmentModules[key]
	if (Array.isArray(fragment.menu)) {
		manifest.menu.push(...fragment.menu)
	}
	if (Array.isArray(fragment.pages)) {
		manifest.pages.push(...fragment.pages)
	}
}

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
	// detail pages' `config.sidebarTabs[].component` and `config.actionsComponent`,
	// plus the page-level `headerComponent` (sugar field per nc-vue, also accepted
	// inside `config.headerComponent` for compat with older manifests).
	const referencedComponents = () => {
		const refs = new Set()
		for (const page of manifest.pages) {
			if (page.type === 'custom' && typeof page.component === 'string') {
				refs.add(page.component)
			}
			if (typeof page.headerComponent === 'string') {
				refs.add(page.headerComponent)
			}
			if (typeof page.actionsComponent === 'string') {
				refs.add(page.actionsComponent)
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
			// v2 pages may name slot-override components via `slots`
			// (e.g. `slots: { default: "SchemaDesignerView" }`).
			if (page.slots && typeof page.slots === 'object') {
				for (const slotComponent of Object.values(page.slots)) {
					if (typeof slotComponent === 'string') {
						refs.add(slotComponent)
					}
				}
			}
			// v2 manifest widgets reference registry components either directly
			// (`widget.component`), via `widget.props.component` (e.g. the
			// card-grid widget naming the per-row card), or — the canonical
			// CnWidgetGrid path — via `widget.widgetKey` resolved against the
			// kind-tagged registry (e.g. dashboard `stats-block` / `audit-trail`).
			// Scan both page-level `widgets[]` (v2) and `config.widgets[]`
			// (dashboard) arrays.
			const widgetArrays = [page.widgets, cfg.widgets]
			for (const widgets of widgetArrays) {
				for (const widget of widgets || []) {
					if (typeof widget.component === 'string') {
						refs.add(widget.component)
					}
					if (typeof widget.widgetKey === 'string') {
						refs.add(widget.widgetKey)
					}
					if (widget.props && typeof widget.props.component === 'string') {
						refs.add(widget.props.component)
					}
				}
			}
		}
		return refs
	}

	it('every component the manifest references resolves to a registered component', () => {
		for (const name of referencedComponents()) {
			expect(registry, `manifest references "${name}"`).toHaveProperty(name)
		}
	})

	it('has no unused registry entries', () => {
		const referenced = referencedComponents()
		for (const name of Object.keys(registry)) {
			expect(referenced, `registry.${name} is unreferenced`).toContain(name)
		}
	})
})
