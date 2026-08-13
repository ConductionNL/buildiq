// SPDX-License-Identifier: EUPL-1.2
/**
 * blockCapture — pure capture + de-namespace logic for the "save as block"
 * flow (component-blocks, REQ under specs/component-blocks/spec.md).
 *
 * Reuses `save-as-template`'s already-unit-tested `deNamespaceSlug` /
 * `rewriteSchemaRefs` pair (`templateCapture.js`) rather than reinventing
 * de-namespace logic — design.md D1/D2 explicitly call this out as the
 * direct precedent. A block's fragment is a single captured `widgetEntry`
 * (any configured widget) or a section wrapper `{ id, widgets: [...] }`
 * (a selected contiguous page section) — the fragment/remap machinery is
 * identical for either shape (design.md Open Questions).
 *
 * Everything here is pure (no I/O): the OR write happens in
 * `SaveBlockDialog.vue` via axios, mirroring `SaveAsTemplateDialog.vue`.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */

import {
	deepClone,
	deNamespaceSlug,
	rewriteSchemaRefs,
	SlugCollisionError,
} from './templateCapture.js'

export { SlugCollisionError }

/**
 * Suggested (non-enforced) category values offered by the block-capture
 * picker. Unlike `TEMPLATE_CATEGORIES` this is not a schema enum — the
 * `category` property on `componentBlock` is free text (register.d
 * fragment) because a block's shape is far more varied than a full-app
 * template's.
 *
 * @type {string[]}
 */
export const BLOCK_CATEGORIES = ['display', 'layout', 'form', 'navigation', 'data']

/**
 * Recursively collect every `schema` / `relatedSchema` string value found
 * anywhere in a fragment — the same two keys `rewriteSchemaRefs` rewrites.
 * Used to derive `schemaDependencies` from an arbitrary widget/section
 * subtree without needing to know its widget-type-specific shape.
 *
 * @param {*} node - the fragment node (object / array / scalar).
 * @param {Set<string>} [acc] - accumulator (created fresh by default).
 * @return {Set<string>} the set of distinct schema slugs referenced.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function collectSchemaRefs(node, acc = new Set()) {
	if (Array.isArray(node)) {
		node.forEach((item) => collectSchemaRefs(item, acc))
		return acc
	}
	if (node === null || typeof node !== 'object') {
		return acc
	}
	for (const [key, value] of Object.entries(node)) {
		if (
			(key === 'schema' || key === 'relatedSchema')
			&& typeof value === 'string'
			&& value
		) {
			acc.add(value)
		} else {
			collectSchemaRefs(value, acc)
		}
	}
	return acc
}

/**
 * Build the section-fragment wrapper for a multi-widget capture, keyed by
 * a freshly derived section id and preserving the widgets' relative layout
 * (each widgetEntry already carries its own `slot`/`gridX`/`gridY`/
 * `gridWidth`/`gridHeight`, so the wrapper only needs to keep them in the
 * same array order — REQ "preserving their relative layout").
 *
 * @param {string} sectionId - a stable id for the section wrapper.
 * @param {Array<object>} widgets - the selected widgetEntry objects.
 * @return {{id: string, widgets: Array<object>}} the section fragment.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function buildSectionFragment(sectionId, widgets) {
	return { id: sectionId, widgets: Array.isArray(widgets) ? widgets : [] }
}

/**
 * Capture a widget or a section fragment into a `ComponentBlock` record
 * shape — de-namespaced so a cross-app insert can remap cleanly (mirrors
 * `captureTemplate`). Pure function; performs NO I/O and NEVER includes
 * object rows (a block is a definition, not a dataset).
 *
 * @param {object} fragment - the widgetEntry object (single-widget capture)
 *   or the `{ id, widgets: [...] }` section wrapper (multi-widget capture).
 * @param {string} appSlug - the source Application's slug (used to strip
 *   the `<appSlug>-` companion-schema prefix, exactly as save-as-template
 *   does on capture).
 * @param {object} metadata - `{ slug, name, description, category }`.
 * @return {{ record: object, summary: { schemaDependencies: Array<{sourceSlug: string, slug: string, shared: boolean}> } }}
 *   the `ComponentBlock` record plus a capture summary the dialog renders.
 * @throws {SlugCollisionError} when two referenced schemas de-namespace to
 *   the same canonical slug.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function captureBlock(fragment, appSlug, metadata) {
	const sourceSlugs = [...collectSchemaRefs(fragment)]

	const rewriteMap = {}
	const canonicalToSource = {}
	const summaryEntries = []
	for (const sourceSlug of sourceSlugs) {
		const { slug: canonical, shared } = deNamespaceSlug(sourceSlug, appSlug)
		if (
			Object.prototype.hasOwnProperty.call(canonicalToSource, canonical)
			&& canonicalToSource[canonical] !== sourceSlug
		) {
			throw new SlugCollisionError(canonical, [
				canonicalToSource[canonical],
				sourceSlug,
			])
		}
		canonicalToSource[canonical] = sourceSlug
		rewriteMap[sourceSlug] = canonical
		summaryEntries.push({ sourceSlug, slug: canonical, shared })
	}

	const capturedFragment = rewriteSchemaRefs(deepClone(fragment || {}), rewriteMap)

	const record = {
		slug: metadata.slug,
		name: metadata.name,
		description: metadata.description || '',
		category: metadata.category || '',
		schemaDependencies: Object.values(rewriteMap),
		sourceApplicationSlug: appSlug || '',
		fragment: capturedFragment,
	}
	if (metadata.createdBy) {
		record.createdBy = metadata.createdBy
	}

	return {
		record,
		summary: { schemaDependencies: summaryEntries },
	}
}

/**
 * Whether a fragment is a section wrapper (`{ widgets: [...] }`) rather
 * than a single widgetEntry. Shared by capture-summary rendering and
 * `blockInsert.js` so both sides agree on the discriminator.
 *
 * @param {*} fragment - the fragment value.
 * @return {boolean} true when the fragment is a section wrapper.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function isSectionFragment(fragment) {
	return !!(
		fragment
		&& typeof fragment === 'object'
		&& Array.isArray(fragment.widgets)
	)
}
