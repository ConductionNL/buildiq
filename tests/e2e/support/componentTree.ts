// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Ask a running page which Vue 3 components are mounted, and read their props.
 *
 * WHY THIS EXISTS
 * ---------------
 * Several `openbuild-runtime` requirements are about WHICH component the router
 * mounted, not about pixels:
 *
 *   - REQ-OBR-002 — `/builder/:slug` must mount a NESTED `CnAppRoot` whose
 *     `appId` is `openbuild-{slug}`.
 *   - REQ-OBR-006a — `/builder/:slug/schemas` must render the schema designer
 *     and must NOT mount that nested `CnAppRoot`.
 *
 * The negative half of REQ-OBR-006a is the load-bearing one and it is not
 * expressible in DOM selectors: "the virtual app is not mounted" looks exactly
 * like "the virtual app is mounted but still loading" from the outside. Only the
 * component tree distinguishes them.
 *
 * The traversal is the same one `stagedManifest.ts` documents at length: this
 * bundle ships `__VUE_PROD_DEVTOOLS__ = false`, so `__vueParentComponent` and
 * `__vnode` are never stamped on elements. `container.__vue_app__` and
 * `container._vnode` are assigned unconditionally by `createApp().mount()`, so
 * they are the only handles that survive a production build — walk from there.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import type { Page } from '@playwright/test'

/** One mounted component instance, reduced to a JSON-safe shape. */
export interface MountedComponent {
	/** The component's `name` option (or the `<script setup>` `__name` fallback). */
	name: string
	/** Its props, structurally cloned. Values that will not clone are dropped. */
	props: Record<string, unknown>
}

/**
 * Every component instance mounted on the page, in tree order.
 *
 * Props are cloned through a replacer that drops functions and cyclic values, so
 * a component holding a router instance or an event handler still reports the
 * scalar props a test wants to assert on.
 *
 * @param page Playwright page to inspect.
 * @return {Promise<Array<object>>} Flat list of mounted components.
 */
export async function mountedComponents(page: Page): Promise<MountedComponent[]> {
	return page.evaluate(() => {
		type AnyRec = Record<string, any>

		const out: Array<{ name: string; props: Record<string, unknown> }> = []

		/**
		 * Reduce a props bag to values that survive `structuredClone`-style
		 * serialisation, so one un-cloneable prop cannot blank the whole entry.
		 *
		 * @param props Raw Vue props object.
		 * @return {Record<string, unknown>} JSON-safe subset.
		 */
		function safeProps(
			props: AnyRec | null | undefined,
		): Record<string, unknown> {
			const safe: Record<string, unknown> = {}
			if (!props) {
				return safe
			}
			for (const key of Object.keys(props)) {
				const value = props[key]
				if (typeof value === 'function' || typeof value === 'symbol') {
					continue
				}
				try {
					safe[key] = JSON.parse(JSON.stringify(value))
				} catch {
					// Cyclic or otherwise un-serialisable — record its presence
					// and its type so a test can still assert "prop was passed".
					safe[key] = `[unserialisable ${typeof value}]`
				}
			}
			return safe
		}

		/**
		 * Record a component instance, then descend into its rendered tree.
		 *
		 * @param instance Vue 3 internal component instance.
		 * @return {void}
		 */
		function visitInstance(instance: AnyRec | null | undefined): void {
			if (!instance) {
				return
			}
			const type: AnyRec = instance.type || {}
			const label = type.name || type.__name
			if (label) {
				out.push({ name: String(label), props: safeProps(instance.props) })
			}
			visitVNode(instance.subTree)
		}

		/**
		 * Descend a vnode: component vnodes hand off to their instance,
		 * fragment/element vnodes recurse through their children array.
		 *
		 * @param vnode Vue 3 internal vnode.
		 * @return {void}
		 */
		function visitVNode(vnode: AnyRec | null | undefined): void {
			if (!vnode || typeof vnode !== 'object') {
				return
			}
			if (vnode.component) {
				visitInstance(vnode.component)
				return
			}
			if (Array.isArray(vnode.children)) {
				for (const child of vnode.children) {
					visitVNode(child as AnyRec)
				}
			}
			visitVNode(vnode.ssContent)
		}

		const containers = Array.prototype.filter.call(
			document.querySelectorAll('*'),
			(el: AnyRec) => el.__vue_app__ !== undefined,
		) as AnyRec[]

		for (const container of containers) {
			visitVNode(container._vnode)
		}

		return out
	})
}

/**
 * The names of every mounted component, de-duplicated and sorted.
 *
 * Handy in an assertion message: a rename then reads as a rename instead of as
 * a phantom "component missing".
 *
 * @param page Playwright page to inspect.
 * @return {Promise<string[]>} Sorted unique component names.
 */
export async function mountedComponentNames(page: Page): Promise<string[]> {
	const all = await mountedComponents(page)
	return Array.from(new Set(all.map((c) => c.name))).sort()
}

/**
 * All mounted instances of one named component.
 *
 * @param page Playwright page to inspect.
 * @param componentName Component `name` to match.
 * @return {Promise<Array<object>>} Matching instances (possibly empty).
 */
export async function findMounted(
	page: Page,
	componentName: string,
): Promise<MountedComponent[]> {
	const all = await mountedComponents(page)
	return all.filter((c) => c.name === componentName)
}
