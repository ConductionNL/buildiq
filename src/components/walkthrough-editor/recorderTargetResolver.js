// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The stable identity attributes, in priority order, with the target kind each
 * resolves to.
 */
const STABLE_TARGETS = [
	['data-walkthrough-id', 'element'],
	['data-cn-route', 'nav-item'],
	['data-widget-key', 'widget'],
	['data-action-id', 'action'],
	['data-testid', 'element'],
]

/**
 * Resolve a clicked DOM element to the most stable walkthrough target descriptor
 * (ADR-043 recorder). Prefers explicit manifest identities over a brittle CSS
 * path, in priority order:
 *   1. `data-walkthrough-id`  → { kind: 'element', ref }
 *   2. `data-cn-route`        → { kind: 'nav-item', ref }   (CnAppNav menu items)
 *   3. `data-widget-key`      → { kind: 'widget', ref }
 *   4. `data-action-id`       → { kind: 'action', ref }
 *   5. `data-testid`          → { kind: 'element', ref }     (journeydoc instrumentation)
 *   6. fallback               → { kind: 'selector', selector } (short CSS path)
 *
 * Each lookup uses `closest()` so the NEAREST stable ancestor of the clicked node
 * wins (a clicked icon inside an instrumented button resolves to the button).
 *
 * @param {Element|null} el The clicked element.
 * @return {{ kind: string, ref?: string, selector?: string }|null} The target, or null.
 */
export function resolveTargetFromElement(el) {
	if (!el || typeof el.closest !== 'function') return null

	for (const [attr, kind] of STABLE_TARGETS) {
		const ref = nearestAttribute(el, attr)
		if (ref) return { kind, ref }
	}
	return { kind: 'selector', selector: cssPath(el) }
}

/**
 * The value of an attribute on the element or its nearest ancestor carrying it.
 *
 * @param {Element} el The clicked element.
 * @param {string} attr The attribute name.
 * @return {?string} The value, or null when no ancestor carries it.
 */
function nearestAttribute(el, attr) {
	const hit = el.closest(`[${attr}]`)
	return hit ? hit.getAttribute(attr) : null
}

/**
 * Build a short, reasonably-stable CSS selector path for an element with no
 * stable identity — an id when present, else a tag + first class + nth-of-type
 * chain up to 4 levels. Last-resort targeting; the recorder flags it as brittle.
 *
 * @param {Element} el The element.
 * @return {string} A CSS selector.
 */
export function cssPath(el) {
	return isElement(el) ? pathParts(el, 4).join(' > ') : ''
}

/**
 * The selector segments from the outermost ancestor down to `node`, at most
 * `levels` deep, starting at the nearest ancestor with an id.
 *
 * @param {?Node} node The element to end at.
 * @param {number} levels How many levels may still be added.
 * @return {Array<string>} The segments, outermost first.
 */
function pathParts(node, levels) {
	if (!isElement(node) || levels === 0) return []
	if (node.id) return ['#' + cssEscape(node.id)]
	return [...pathParts(node.parentElement, levels - 1), pathSegment(node)]
}

/**
 * Whether a node is an element node.
 *
 * @param {?Node} node Any node.
 * @return {boolean}
 */
function isElement(node) {
	return Boolean(node) && node.nodeType === 1
}

/**
 * One selector segment: the tag, its first class and, among same-tag
 * siblings, its position.
 *
 * @param {Element} node The element.
 * @return {string}
 */
function pathSegment(node) {
	return node.tagName.toLowerCase() + classSuffix(node) + nthOfTypeSuffix(node)
}

/**
 * The `.class` suffix for an element's first class, or ''.
 *
 * @param {Element} node The element.
 * @return {string}
 */
function classSuffix(node) {
	const cls = (node.getAttribute('class') || '')
		.trim()
		.split(/\s+/)
		.filter(Boolean)[0]
	return cls ? '.' + cssEscape(cls) : ''
}

/**
 * The `:nth-of-type()` suffix when the element has same-tag siblings, or ''.
 *
 * @param {Element} node The element.
 * @return {string}
 */
function nthOfTypeSuffix(node) {
	const parent = node.parentElement
	if (!parent) return ''
	const sameTag = Array.prototype.filter.call(
		parent.children,
		(c) => c.tagName === node.tagName,
	)
	return sameTag.length > 1 ? `:nth-of-type(${sameTag.indexOf(node) + 1})` : ''
}

/**
 * Escape a CSS identifier (CSS.escape when available).
 *
 * @param {string} v The value.
 * @return {string} Escaped.
 */
function cssEscape(v) {
	if (typeof window !== 'undefined' && window.CSS && window.CSS.escape)
		return window.CSS.escape(v)
	return String(v).replace(/[^a-zA-Z0-9_-]/g, '\\$&')
}
