// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Read the Page Designer's LIVE (staged) manifest out of a running page.
 *
 * WHY THIS EXISTS — read before changing the traversal below.
 *
 * An insert, a step assignment or a condition edit is an in-editor change until
 * the page is saved, so the manifest that a scenario must assert on only exists
 * inside the mounted component. It is deliberately NOT read back through the
 * manifest API: `PageDesignerHost` treats a successful save as a session
 * boundary and bumps its session key, which resets the designer (and its page
 * selection) mid-scenario.
 *
 * Both call sites used to reach the component as:
 *
 *     document.querySelector('.page-designer').__vue__.manifest
 *
 * `__vue__` is a **Vue 2** back-reference: Vue 2 stamped the owning component
 * instance onto each element it created. Vue 3 never sets it, so after the Vue 3
 * migration every read threw "page designer not mounted — cannot read the staged
 * manifest" and seven scenarios failed on a designer that was in fact mounted,
 * on screen, and holding exactly the state under test. The message was
 * misleading: the probe was stale, the product was fine.
 *
 * The Vue 3 replacements are not drop-in:
 *
 *   - `el.__vueParentComponent` / `el.__vnode` are only stamped when
 *     `__DEV__ || __FEATURE_PROD_DEVTOOLS__`. This app bundles with
 *     `__VUE_PROD_DEVTOOLS__ = false`, so both are absent at runtime
 *     (measured — not assumed).
 *   - `container.__vue_app__` and `container._vnode`, by contrast, are assigned
 *     UNCONDITIONALLY by `createApp().mount()` and by the renderer's `render()`.
 *     They are therefore the only handles that survive a production build.
 *
 * So: find every element Vue 3 mounted an app into, then walk the component tree
 * from its root vnode until the named component is found, and read the prop off
 * that instance. `manifest` is a prop on `<PageDesigner>` fed by
 * `PageDesignerHost`, so this observes precisely what the old `__vue__.manifest`
 * read observed — the in-editor buffer before any save.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import type { Page } from '@playwright/test'

/**
 * Read a mounted component's `manifest` prop as plain JSON.
 *
 * @param page Playwright page, already showing the designer.
 * @param componentName Component to locate, by its `name` option.
 * @return {Promise<Record<string, any>>} A structured clone of the staged manifest.
 * @throws  When the component is not mounted — the error names every component
 *          that WAS found, so a rename shows up as a rename and not as a
 *          phantom "not mounted".
 */
export async function readStagedManifest(
	page: Page,
	componentName: string = 'PageDesigner',
): Promise<Record<string, any>> {
	const result = await page.evaluate((name: string) => {
		type AnyRec = Record<string, any>

		let found: AnyRec | null = null
		const seen: string[] = []

		/**
		 * Descend a component instance: match it, else walk its rendered tree.
		 *
		 * @param instance Vue 3 internal component instance.
		 * @return {boolean} True once the target has been found.
		 */
		function visitInstance(instance: AnyRec | null | undefined): boolean {
			if (!instance) {
				return false
			}
			// `type` is the component's options object; `__name` is the
			// filename-derived fallback vue-loader adds for `<script setup>`.
			const type: AnyRec = instance.type || {}
			const label = type.name || type.__name
			if (label) {
				seen.push(String(label))
			}
			if (label === name) {
				found = instance
				return true
			}
			return visitVNode(instance.subTree)
		}

		/**
		 * Descend a vnode: component vnodes hand off to their instance,
		 * fragment/element vnodes recurse through their children array.
		 *
		 * @param vnode Vue 3 internal vnode.
		 * @return {boolean} True once the target has been found.
		 */
		function visitVNode(vnode: AnyRec | null | undefined): boolean {
			if (!vnode || typeof vnode !== 'object') {
				return false
			}
			if (vnode.component) {
				return visitInstance(vnode.component)
			}
			// Element/fragment children are an array of vnodes; a text vnode's
			// children is a string, which `Array.isArray` correctly rejects.
			if (Array.isArray(vnode.children)) {
				for (const child of vnode.children) {
					if (visitVNode(child as AnyRec)) {
						return true
					}
				}
			}
			// Suspense keeps its real content off `children`.
			return visitVNode(vnode.ssContent)
		}

		// Every element `createApp().mount()` has ever targeted on this page —
		// Nextcloud mounts several (the notifications bell, the app root, …),
		// so all of them are searched rather than assuming a single root.
		const containers = Array.prototype.filter.call(
			document.querySelectorAll('*'),
			(el: AnyRec) => el.__vue_app__ !== undefined,
		) as AnyRec[]

		for (const container of containers) {
			if (visitVNode(container._vnode)) {
				break
			}
		}

		if (!found) {
			return {
				error: `no mounted <${name}> found`,
				containers: containers.length,
				components: Array.from(new Set(seen)).sort(),
			}
		}

		const instance = found as AnyRec
		const manifest =
			(instance.props && instance.props.manifest)
			|| (instance.proxy && instance.proxy.manifest)
		if (!manifest) {
			return { error: `<${name}> is mounted but exposes no \`manifest\`` }
		}

		return { manifest: JSON.parse(JSON.stringify(manifest)) }
	}, componentName)

	if (result.error) {
		const extra = result.components
			? ` (${result.containers} Vue app root(s); components seen: ${result.components.join(', ')})`
			: ''
		throw new Error(`cannot read the staged manifest — ${result.error}${extra}`)
	}

	return result.manifest as Record<string, any>
}
