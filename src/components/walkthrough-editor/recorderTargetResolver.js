// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

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

	const byAttr = [
		['[data-walkthrough-id]', 'data-walkthrough-id', 'element'],
		['[data-cn-route]', 'data-cn-route', 'nav-item'],
		['[data-widget-key]', 'data-widget-key', 'widget'],
		['[data-action-id]', 'data-action-id', 'action'],
		['[data-testid]', 'data-testid', 'element'],
	]
	for (const [sel, attr, kind] of byAttr) {
		const hit = el.closest(sel)
		if (hit) {
			const ref = hit.getAttribute(attr)
			if (ref) return { kind, ref }
		}
	}
	return { kind: 'selector', selector: cssPath(el) }
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
	if (!el || el.nodeType !== 1) return ''
	const parts = []
	let node = el
	let depth = 0
	while (node && node.nodeType === 1 && depth < 4) {
		if (node.id) {
			parts.unshift('#' + cssEscape(node.id))
			break
		}
		let seg = node.tagName.toLowerCase()
		const cls = (node.getAttribute('class') || '')
			.trim()
			.split(/\s+/)
			.filter(Boolean)[0]
		if (cls) seg += '.' + cssEscape(cls)
		const parent = node.parentElement
		if (parent) {
			const sameTag = Array.prototype.filter.call(
				parent.children,
				(c) => c.tagName === node.tagName,
			)
			if (sameTag.length > 1)
				seg += `:nth-of-type(${sameTag.indexOf(node) + 1})`
		}
		parts.unshift(seg)
		node = node.parentElement
		depth++
	}
	return parts.join(' > ')
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
