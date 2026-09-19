// SPDX-License-Identifier: EUPL-1.2
/**
 * pagePlacementChecks — the two page-level facts the placement editor has to
 * know that are not properties of a single placement
 * (v2-widget-placement-editor task 1.2).
 *
 * Both are pure, both are read by `WidgetPlacementPanel.vue`, and neither can
 * be fixed by constraining a value the way `slotGeometry.js` fixes geometry:
 *
 *  1. The widget SURFACE a page represents. Each registry entry carries a
 *     `surfaces` array, so the library filters a detail-only type out of a
 *     dashboard page's picker itself, but only if it is handed the right
 *     surface. Hand it the wrong one and the picker offers a type that will
 *     not render on the page being edited, with no error anywhere.
 *  2. The single full-width custom widget on a `dashboard` page, which the
 *     validator rejects as a custom page in disguise (ADR-036 decision 1).
 *     It is a property of the page, not of the placement: the same placement
 *     is perfectly valid the moment a second one joins it.
 *
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */

// The exempt key list is NOT exported from the package barrel, so it is read
// from its own module by the `./dist/*` subpath the package's `exports` map
// publishes — the same real-leaf route `tests/vitest/stubs/` already uses for
// `mergeManifestDelta` and `validateManifest`. A second, local copy of the
// list is exactly how the validator's own hand-written array went stale at 11
// keys while the library rendered 45 (see the module's own docblock).
import { LIBRARY_WIDGET_KEYS } from '@conduction/nextcloud-vue/dist/esm/utils/libraryWidgetKeys.js'

/** Every widget key the library renders itself, as a set for lookup. */
const LIBRARY_KEYS = new Set(LIBRARY_WIDGET_KEYS)

/**
 * The widget surface a page of a given type represents, for
 * `CnAddWidgetModal`'s `surface` prop and `listWidgetTypes()`.
 *
 * @param {string} pageType - the page's `type`.
 * @return {string} `'detail-page'` for a detail page, `'app-dashboard'`
 *   otherwise.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function widgetSurfaceForPageType(pageType) {
	return pageType === 'detail' ? 'detail-page' : 'app-dashboard'
}

/**
 * Whether a widget key is one the library renders itself. A key outside this
 * set is a custom registry component, which is what the dashboard rule turns
 * on.
 *
 * @param {string} widgetKey - the placement's `widgetKey`.
 * @return {boolean} true when the library ships the component.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function isLibraryWidgetKey(widgetKey) {
	return typeof widgetKey === 'string' && LIBRARY_KEYS.has(widgetKey)
}

/**
 * Whether a page is the shape ADR-036 decision 1 forbids: `type: "dashboard"`
 * carrying exactly one widget, in the body slot, filling the 12x12 grid, whose
 * `widgetKey` is a custom registry component.
 *
 * Mirrors `validateManifest.js` check 3 including its normalisation of omitted
 * coordinates, so the editor reports the state while the author is in it
 * rather than letting them meet the rule as a rejected save. The count is
 * across ALL slots: one sidebar widget makes the page multi-widget and the
 * rule stops applying.
 *
 * @param {object|null} page - the page to check.
 * @return {boolean} true when the page is a custom page in disguise.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function isCustomPageInDisguise(page) {
	return customPageInDisguiseKey(page) !== null
}

/**
 * The `widgetKey` that makes a page a custom page in disguise, so the editor
 * can name the component in the alternative it offers, or `null` when the page
 * is fine.
 *
 * @param {object|null} page - the page to check.
 * @return {string|null} the offending widget key, or null.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function customPageInDisguiseKey(page) {
	if (!page || page.type !== 'dashboard') {
		return null
	}
	if (!Array.isArray(page.widgets) || page.widgets.length !== 1) {
		return null
	}
	const widget = page.widgets[0]
	if (!widget || typeof widget !== 'object') {
		return null
	}

	// Normalised the same way the validator normalises them, so an author
	// cannot slip past the rule here and be caught by the save.
	const gridX = typeof widget.gridX === 'number' ? widget.gridX : 0
	const gridY = typeof widget.gridY === 'number' ? widget.gridY : 0
	const gridWidth = typeof widget.gridWidth === 'number' ? widget.gridWidth : 12
	const gridHeight
		= typeof widget.gridHeight === 'number' ? widget.gridHeight : 12
	if (
		widget.slot !== 'body'
		|| gridX !== 0
		|| gridY !== 0
		|| gridWidth !== 12
		|| gridHeight !== 12
	) {
		return null
	}

	const widgetKey = typeof widget.widgetKey === 'string' ? widget.widgetKey : ''
	if (widgetKey === '' || isLibraryWidgetKey(widgetKey)) {
		return null
	}
	return widgetKey
}

/**
 * Whether a page must carry a note on every placement it holds. A `custom`
 * page does: the note documents why a standard page type was not feasible,
 * which is the ratchet ADR-036 decision 7 puts on the escape hatch.
 *
 * @param {object|null} page - the page being edited.
 * @return {boolean} true when `_note` is required on each placement.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function pageRequiresPlacementNote(page) {
	return Boolean(page) && page.type === 'custom'
}
