// SPDX-License-Identifier: EUPL-1.2
/**
 * slotGeometry — every geometric rule a v2 `widgetEntry` obeys, in one pure
 * module (v2-widget-placement-editor, design.md D4).
 *
 * Both authoring paths in `WidgetPlacementPanel.vue` run every value through
 * here: the drag canvas (`CnWidgetGrid :editable`) and the numeric field rows.
 * Two ways to set the same four numbers is two chances to clamp differently,
 * and the disagreement would surface as a save the validator rejects over a
 * rule the author never saw.
 *
 * 🔴 THE COLUMN COUNT COMES FROM THE LIBRARY, NEVER FROM A LOCAL 12.
 * `resolveSlotColumns` is the exact function `validateManifest.js` calls for
 * its `gridX + gridWidth` post-schema check, with the exact same arguments.
 * A local constant would disagree the moment a page declares
 * `config.slotColumns`, and `sidebar` already resolves to 1, not 12.
 *
 * No Vue import, following `blockInsert.js` and `templateCapture.js`, so the
 * clamp is testable across the default counts and a widened `slotColumns`
 * without a browser.
 *
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */

import { resolveSlotColumns } from '@conduction/nextcloud-vue'

/** The literal slots the v2 schema's `slotValue` enumerates. */
export const LITERAL_SLOTS = Object.freeze([
	'body',
	'sidebar',
	'header-actions',
	'footer',
	'modal',
])

/** Order the placement panel lists slots in: body first, then the rest. */
export const SLOT_DISPLAY_ORDER = Object.freeze([
	'body',
	'sidebar',
	'header-actions',
	'footer',
	'modal',
])

/**
 * Whether a slot name is one the v2 `slotValue` def accepts: a literal, or a
 * `tab:<id>` / `section:<id>` pattern with a non-empty id.
 *
 * @param {string} slot - the slot name to test.
 * @return {boolean} true when the schema would accept it.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function isValidSlot(slot) {
	if (typeof slot !== 'string' || slot === '') {
		return false
	}
	return LITERAL_SLOTS.includes(slot) || /^(tab|section):.+/.test(slot)
}

/**
 * The resolved column count for a slot on a page, through the library's own
 * resolver so this editor and the validator can never disagree.
 *
 * @param {string} slot - the placement's slot.
 * @param {object|null} page - the page the placement lives on; only
 *   `config.slotColumns` is read.
 * @return {number} the effective column count (always a positive integer).
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function columnsForSlot(slot, page) {
	const slotColumns =
		page && page.config && typeof page.config === 'object'
			? page.config.slotColumns
			: null
	return resolveSlotColumns(slot, slotColumns || null)
}

/**
 * Whether a slot offers a row control at all. `header-actions` pins `gridY`
 * to 0 (every header action lives in one row), so showing a row field there
 * would offer a degree of freedom the schema does not have.
 *
 * @param {string} slot - the placement's slot.
 * @return {boolean} true when `gridY` is the author's to set.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function slotOffersRow(slot) {
	return slot !== 'header-actions'
}

/**
 * Whether a slot offers a span control. `sidebar` pins `gridWidth` to 1 (the
 * sidebar is a one-column panel), and a one-column slot has no column to
 * start in either.
 *
 * @param {string} slot - the placement's slot.
 * @param {object|null} page - the page, read for `config.slotColumns`.
 * @return {boolean} true when `gridX` / `gridWidth` are the author's to set.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function slotOffersSpan(slot, page) {
	return columnsForSlot(slot, page) > 1
}

/**
 * Coerce a value to an integer at or above `min`, falling back to `min` when
 * the input is a cleared field, a blank string or anything non-numeric.
 *
 * @param {number|string|null|undefined} value - the raw value, typically a
 *   number input's string.
 * @param {number} min - the floor.
 * @return {number} an integer at or above `min`.
 */
function toInt(value, min) {
	const parsed =
		typeof value === 'number' ? Math.trunc(value) : parseInt(value, 10)
	if (!Number.isFinite(parsed) || parsed < min) {
		return min
	}
	return parsed
}

/**
 * Apply every rule the manifest schema cannot state as schema to one
 * placement, and return a NEW entry. Every key the caller passed in survives,
 * because the entry is spread rather than rebuilt from a known-key list
 * (design.md D6): a whitelist silently drops `tabGroup`, `roles`,
 * `visibleWhen`, `requiredApp`, `dateChip`, `dataSource`, and whatever the
 * next library version adds.
 *
 * The rules, in the order the validator checks them:
 *  - `gridWidth` is pinned to 1 in `sidebar` and otherwise clamped into the
 *    slot's resolved column count;
 *  - `gridX` is clamped so `gridX + gridWidth` never exceeds that count, which
 *    is the post-schema check at `validateManifest.js` line 199;
 *  - `gridY` is pinned to 0 in `header-actions`;
 *  - `gridHeight` is at least 1.
 *
 * @param {object} entry - the placement to constrain.
 * @param {object|null} page - the page it lives on, read for
 *   `config.slotColumns`.
 * @return {object} a new placement with the rules applied.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function applySlotRules(entry, page) {
	const source = entry && typeof entry === 'object' ? entry : {}
	const slot = isValidSlot(source.slot) ? source.slot : 'body'
	const columns = columnsForSlot(slot, page)

	const requestedWidth = toInt(source.gridWidth, 1)
	const gridWidth = Math.min(requestedWidth, columns)

	const requestedX = toInt(source.gridX, 0)
	const gridX = Math.min(requestedX, Math.max(0, columns - gridWidth))

	const gridY = slotOffersRow(slot) ? toInt(source.gridY, 0) : 0
	const gridHeight = toInt(source.gridHeight, 1)

	return { ...source, slot, gridX, gridY, gridWidth, gridHeight }
}

/**
 * The geometry a placement is born with in a given slot: full-height stacking
 * below whatever the slot already holds, at a span the slot can carry.
 *
 * The default span is deliberately half the slot rather than all of it. A
 * single 12×12 custom widget on a `dashboard` page is rejected by the
 * validator as a custom page in disguise (ADR-036 decision 1), and adding one
 * full-width widget to an empty dashboard is the most natural first action in
 * this editor. Half width keeps the default action valid; an author who wants
 * the whole row can still widen it, and `pagePlacementChecks.js` tells them
 * what that costs.
 *
 * @param {string} slot - the slot the placement is being added to.
 * @param {object|null} page - the page, read for `config.slotColumns`.
 * @param {Array<object>} existingWidgets - the page's current `widgets[]`,
 *   read to stack the new placement below the slot's occupied rows.
 * @return {{slot: string, gridX: number, gridY: number, gridWidth: number, gridHeight: number}}
 *   the starting geometry, already through {@link applySlotRules}.
 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
 */
export function defaultGeometryFor(slot, page, existingWidgets) {
	const columns = columnsForSlot(slot, page)
	const inSlot = (Array.isArray(existingWidgets) ? existingWidgets : []).filter(
		(widget) => widget && widget.slot === slot,
	)
	const nextRow = inSlot.reduce(
		(max, widget) =>
			Math.max(max, toInt(widget.gridY, 0) + toInt(widget.gridHeight, 1)),
		0,
	)

	const preferredWidth = slot === 'header-actions' ? 2 : 6
	const preferredHeight = slot === 'header-actions' ? 1 : 3

	return applySlotRules(
		{
			slot,
			gridX: 0,
			gridY: nextRow,
			gridWidth: Math.min(preferredWidth, columns),
			gridHeight: preferredHeight,
		},
		page,
	)
}
