// SPDX-License-Identifier: EUPL-1.2
/**
 * blockInsert — pure deep-copy + id-mint + schema-remap logic for
 * inserting a `ComponentBlock` onto a target page (component-blocks,
 * design.md Decision D2).
 *
 * Mirrors `templateCapture.js`'s pure-function pattern: no I/O, no Vue.
 * The manifest write itself goes through `mergeManifestDelta` (the
 * repo's existing keyed structural-merge engine, `@conduction/nextcloud-vue`)
 * — this module only produces the widgetEntry objects to merge in, never
 * splices the manifest itself, so `PageDesigner.vue`'s own delta-merge path
 * stays the single place manifest structure is written.
 *
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */

import { deepClone, rewriteSchemaRefs } from './templateCapture.js'
import { isSectionFragment } from './blockCapture.js'

export { isSectionFragment }

/** Placeholder value written into an unresolved `schema`/`relatedSchema` ref. */
export const UNRESOLVED_SCHEMA_PLACEHOLDER = '__needs-remap__'

/**
 * Normalise a fragment into the list of widgetEntry objects it carries —
 * one for a single-widget capture, several (in their captured order) for a
 * section capture.
 *
 * @param {object} fragment - a captured `ComponentBlock.fragment`.
 * @return {Array<object>} the widgetEntry objects (not yet cloned).
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function fragmentWidgets(fragment) {
	if (isSectionFragment(fragment)) {
		return Array.isArray(fragment.widgets) ? fragment.widgets : []
	}
	return fragment ? [fragment] : []
}

/**
 * Diff a block's `schemaDependencies` against the target app's schema
 * slugs, returning the ones with no exact-slug match. An empty result
 * means insert needs no remap prompt (the common case — reusing a section
 * within its own source app).
 *
 * @param {string[]} schemaDependencies - the block's de-namespaced deps.
 * @param {string[]} targetSchemaSlugs - schema slugs present in the target app.
 * @return {string[]} the mismatched dependency slugs.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function computeSchemaMismatches(schemaDependencies, targetSchemaSlugs) {
	const targetSet = new Set(Array.isArray(targetSchemaSlugs) ? targetSchemaSlugs : [])
	return (Array.isArray(schemaDependencies) ? schemaDependencies : [])
		.filter((slug) => !targetSet.has(slug))
}

/**
 * Mint a fresh, kebab-case, collision-free widget id derived from a base
 * id (or a generic fallback). Mutates `existingIds` by adding the minted
 * id, so repeated calls against the same set never collide — this is what
 * guarantees two insertions of the same block on one page never collide
 * (REQ "Inserting the same block twice does not collide").
 *
 * @param {string} baseId - the fragment's original widget id (hint only).
 * @param {Set<string>} existingIds - ids already present on the target page;
 *   updated in place with every id this call mints.
 * @return {string} a new id matching the widgetEntry id pattern
 *   (`^[a-z0-9]+(-[a-z0-9]+)*$`).
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function mintWidgetId(baseId, existingIds) {
	const ids = existingIds instanceof Set ? existingIds : new Set()
	const base = String(baseId || 'widget')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '') || 'widget'
	let candidate = base
	do {
		const suffix = Math.random().toString(36).slice(2, 8)
		candidate = `${base}-${suffix}`
	} while (ids.has(candidate))
	ids.add(candidate)
	return candidate
}

/**
 * Recursively rewrite any `schema` / `relatedSchema` value that appears in
 * `unresolvedSlugs` to the `UNRESOLVED_SCHEMA_PLACEHOLDER` sentinel and tag
 * the owning object with `needsRemap: true`, so the inserted fragment
 * renders a visible "needs remap" mark instead of silently keeping (or
 * dropping) a reference to a schema that does not exist in the target app.
 *
 * @param {*} node - the node to mark (object / array / scalar).
 * @param {string[]} unresolvedSlugs - dependency slugs left unresolved.
 * @return {*} a NEW node — never mutates the input.
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function markUnresolvedRefs(node, unresolvedSlugs) {
	if (!Array.isArray(unresolvedSlugs) || unresolvedSlugs.length === 0) {
		return node
	}
	if (Array.isArray(node)) {
		return node.map((item) => markUnresolvedRefs(item, unresolvedSlugs))
	}
	if (node === null || typeof node !== 'object') {
		return node
	}
	const out = {}
	let hit = false
	for (const [key, value] of Object.entries(node)) {
		if ((key === 'schema' || key === 'relatedSchema')
			&& typeof value === 'string'
			&& unresolvedSlugs.includes(value)) {
			out[key] = UNRESOLVED_SCHEMA_PLACEHOLDER
			hit = true
			continue
		}
		out[key] = markUnresolvedRefs(value, unresolvedSlugs)
	}
	if (hit) {
		out.needsRemap = true
	}
	return out
}

/**
 * Deep-copy a block's fragment into fresh, insert-ready widgetEntry
 * objects: schema references remapped per `remapMap` (`sourceSlug ->
 * targetSlug`, only entries the developer explicitly resolved —
 * unresolved-but-matching slugs pass through untouched, satisfying the
 * "no remap prompt when slugs already match" scenario), unresolved
 * dependencies marked with a visible placeholder (never silently dropped),
 * and every widget minted a fresh id so two insertions never collide.
 *
 * Insert never creates a live reference back to the source block — the
 * fragment is read once here and never touched again (design.md D2 /
 * REQ "editing the source block does not affect an inserted copy").
 *
 * @param {object} block - `{ fragment, schemaDependencies }` (a ComponentBlock record).
 * @param {object} [options] - insert options.
 * @param {{[key: string]: string}} [options.remapMap] - resolved schema-slug
 *   remaps chosen in `BlockRemapDialog`.
 * @param {string[]} [options.unresolvedDependencies] - dependencies the
 *   developer explicitly left unresolved (dismissed the dialog without
 *   mapping them).
 * @param {Array<object>} [options.targetWidgets] - the target page's
 *   current `widgets[]`, used only to avoid id collisions.
 * @return {Array<object>} the widgetEntry objects ready to merge onto the
 *   target page (via `mergeManifestDelta`).
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function insertBlock(block, options = {}) {
	const fragment = (block && block.fragment) || {}
	const remapMap = options.remapMap || {}
	const unresolvedDependencies = Array.isArray(options.unresolvedDependencies)
		? options.unresolvedDependencies
		: []
	const targetWidgets = Array.isArray(options.targetWidgets) ? options.targetWidgets : []

	const existingIds = new Set(targetWidgets.map((w) => w && w.id).filter(Boolean))

	return fragmentWidgets(fragment).map((widget) => {
		const cloned = deepClone(widget)
		const remapped = rewriteSchemaRefs(cloned, remapMap)
		const marked = markUnresolvedRefs(remapped, unresolvedDependencies)
		return { ...marked, id: mintWidgetId(widget && widget.id, existingIds) }
	})
}

/**
 * Rewrite a whole `ComponentBlock` RECORD's `fragment`/`schemaDependencies`
 * in place (a new object — never mutates the input) to reflect a resolved
 * remap — used by the import flow (design.md D4 / REQ "Exported block
 * imports into a different organisation"), which resolves a block's schema
 * references once at import time rather than deferring to every future
 * insert. Unlike `insertBlock`, this does NOT mint fresh widget ids or
 * touch a target page — it only finalises the record about to be POSTed.
 *
 * @param {object} record - the parsed import record (`{ fragment, schemaDependencies, ... }`).
 * @param {{[key: string]: string}} remapMap - resolved `sourceSlug -> targetSlug` map.
 * @param {string[]} unresolvedDependencies - dependencies left unresolved.
 * @return {object} a new record with `fragment` rewritten and
 *   `schemaDependencies` updated to the resolved target slugs (unresolved
 *   entries are kept as-is — still recorded, still visibly flagged in the
 *   fragment).
 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
 */
export function remapBlockRecord(record, remapMap, unresolvedDependencies) {
	const source = record || {}
	const fragment = rewriteSchemaRefs(deepClone(source.fragment || {}), remapMap || {})
	const marked = markUnresolvedRefs(fragment, unresolvedDependencies || [])
	const schemaDependencies = (Array.isArray(source.schemaDependencies) ? source.schemaDependencies : [])
		.map((dep) => (remapMap && remapMap[dep]) || dep)
	return { ...source, fragment: marked, schemaDependencies }
}
