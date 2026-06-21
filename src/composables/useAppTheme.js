// SPDX-License-Identifier: EUPL-1.2
/**
 * useAppTheme — runtime applier for the NL Design per-app theme
 * (nldesign-theme-selection, REQ-NTS-003). When a resolved virtual-app
 * manifest declares `runtime.theme`, this composable:
 *
 *   1. fetches the static token asset `css/tokens/<tokenSet>.css` (the very
 *      file nldesign injects globally — a plain web-served asset, no controller,
 *      so a scoped consumer reads it with the NC session);
 *   2. rewrites every `:root` selector to
 *      `[data-openbuild-theme-scope="<appSlug>"]` (a mechanical selector-prefix
 *      transform — no style values are altered or user-authored);
 *   3. injects the result as exactly one managed
 *      `<style data-openbuild-theme="<appSlug>">` element; and
 *   4. removes that element on teardown.
 *
 * Defensive: the rewriter only positively recognises flat `:root { decl; }`
 * blocks. If the fetched text carries anything else the rewriter cannot safely
 * scope (nested at-rules, `@media`, `@font-face`, etc.), it injects NOTHING and
 * degrades to default styling with one console warning — never partially
 * rewritten CSS. A 404/network failure does the same. It NEVER writes any
 * nldesign endpoint/appconfig and NEVER injects an unscoped `:root` rule, so
 * nldesign's instance-global theming and the NC chrome are untouched.
 *
 * The theme is a progressive enhancement, never a hard dependency (design.md
 * Decision 4) — nldesign absent simply yields default styling.
 *
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
 */
import axios from '@nextcloud/axios'
import { generateFilePath } from '@nextcloud/router'

const STYLE_ATTR = 'data-openbuild-theme'
export const SCOPE_ATTR = 'data-openbuild-theme-scope'

/** Session cache of fetched (raw) token CSS, keyed by token-set id. */
const cssCache = new Map()

/**
 * Rewrite every `:root` selector in `css` to the scoped attribute selector.
 * Returns null when the CSS contains a construct the rewriter does not
 * positively recognise (so the caller bails out rather than inject partially
 * scoped CSS that could leak unscoped rules).
 *
 * Recognised input: a sequence of `:root { ...declarations... }` blocks plus
 * CSS comments and whitespace. Anything else (at-rules, non-:root selectors,
 * nesting) ⇒ null.
 *
 * @param {string} css - the raw token CSS.
 * @param {string} scopeSelector - e.g. `[data-openbuild-theme-scope="kap"]`.
 * @return {?string} - the rewritten CSS, or null to bail out.
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
 */
export function rewriteRootScope(css, scopeSelector) {
	if (typeof css !== 'string' || css.trim() === '') {
		return null
	}
	// Strip CSS comments first (they may contain braces / @ that confuse the scan).
	const withoutComments = css.replace(/\/\*[\s\S]*?\*\//g, '')
	// Bail on any at-rule — these cannot be flat-scoped by a prefix transform.
	if (/@[a-zA-Z-]+/.test(withoutComments)) {
		return null
	}
	const out = []
	let rest = withoutComments
	const blockRe = /^\s*:root\s*\{([^{}]*)\}\s*/
	while (rest.trim() !== '') {
		const match = blockRe.exec(rest)
		if (!match) {
			// A non-:root selector, nested block, or stray token — not safe.
			return null
		}
		out.push(`${scopeSelector} {${match[1]}}`)
		rest = rest.slice(match[0].length)
	}
	if (out.length === 0) {
		return null
	}
	return out.join('\n')
}

/**
 * Theme applier bound to one virtual-app slug + render root.
 *
 * @param {object} [opts] - options.
 * @param {Function} [opts.client] - axios-like client injection for tests.
 * @param {Document|object} [opts.doc] - document injection for tests.
 * @param {Function} [opts.warn] - console.warn injection for tests.
 * @return {{ apply: Function, teardown: Function }}
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
 */
export function useAppTheme(opts = {}) {
	const client = opts.client || axios
	const doc = opts.doc || (typeof document !== 'undefined' ? document : null)
	const warn = opts.warn || ((m) => { try { console.warn(m) } catch { /* noop */ } })

	/**
	 * Fetch the raw token CSS for a set (session-cached).
	 *
	 * @param {string} tokenSet - the token-set id.
	 * @return {Promise<?string>} - the raw CSS, or null on failure.
	 */
	async function fetchTokenCss(tokenSet) {
		if (cssCache.has(tokenSet)) {
			return cssCache.get(tokenSet)
		}
		try {
			const url = generateFilePath('nldesign', 'css', `tokens/${tokenSet}.css`)
			const { data } = await client.get(url, { responseType: 'text' })
			const css = typeof data === 'string' ? data : String(data || '')
			cssCache.set(tokenSet, css)
			return css
		} catch {
			cssCache.set(tokenSet, null)
			return null
		}
	}

	/**
	 * Apply the manifest's `runtime.theme` to the virtual-app render root.
	 * No-op (and removes any prior managed style) when the manifest declares no
	 * theme. Degrades to default styling on any failure (REQ-NTS-003).
	 *
	 * @param {object} manifest - the resolved (version-routed) manifest.
	 * @param {string} appSlug - the virtual-app slug used as the scope value.
	 * @return {Promise<boolean>} - true when a scoped style was injected.
	 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
	 */
	async function apply(manifest, appSlug) {
		teardown(appSlug)
		const theme = manifest && manifest.runtime && manifest.runtime.theme
		if (!theme || theme.source !== 'nldesign' || !theme.tokenSet || !appSlug || !doc) {
			return false
		}
		const css = await fetchTokenCss(theme.tokenSet)
		if (css === null) {
			warn(`[openbuild] NL Design token set "${theme.tokenSet}" could not be loaded; rendering in default styling.`)
			return false
		}
		const scopeSelector = `[${SCOPE_ATTR}="${cssAttrEscape(appSlug)}"]`
		const rewritten = rewriteRootScope(css, scopeSelector)
		if (rewritten === null) {
			warn(`[openbuild] NL Design token set "${theme.tokenSet}" is not a flat :root stylesheet; skipping scoped theme.`)
			return false
		}
		const style = doc.createElement('style')
		style.setAttribute(STYLE_ATTR, appSlug)
		style.textContent = rewritten
		;(doc.head || doc.body || doc.documentElement).appendChild(style)
		return true
	}

	/**
	 * Remove the managed style element for this app slug, if present.
	 *
	 * @param {string} appSlug - the virtual-app slug.
	 * @return {void}
	 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
	 */
	function teardown(appSlug) {
		if (!doc || !appSlug || !doc.querySelectorAll) {
			return
		}
		const existing = doc.querySelectorAll(`style[${STYLE_ATTR}="${cssAttrEscape(appSlug)}"]`)
		existing.forEach((el) => {
			if (el.parentNode) {
				el.parentNode.removeChild(el)
			}
		})
	}

	return { apply, teardown, fetchTokenCss }
}

/**
 * Escape a slug for safe use inside an attribute-selector string literal.
 * Slugs are kebab-case in practice; this guards the injection boundary anyway.
 *
 * @param {string} value - the slug.
 * @return {string}
 */
function cssAttrEscape(value) {
	return String(value).replace(/["\\]/g, '\\$&')
}

/**
 * Test helper — clear the session token-CSS cache.
 *
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
 */
export function clearThemeCache() {
	cssCache.clear()
}
