// SPDX-License-Identifier: EUPL-1.2
/**
 * useAppCustomTheme — runtime applier for the app-theming feature's
 * `runtime.appTheme` block (logo + 3 colors + header style). Sibling of
 * `useAppTheme.js` (the nldesign-theme-selection applier), reusing its
 * exact scoping mechanism (design.md Decision D1/D3):
 *
 *   1. generates CSS custom-property declarations from the typed
 *      `appTheme` colors — no fetch, no rewriter (the input is already a
 *      handful of typed properties, not an externally-fetched stylesheet);
 *   2. scopes them to the SAME `[data-openbuild-theme-scope="<appSlug>"]`
 *      selector `nldesign-theme-selection`'s applier uses (`SCOPE_ATTR`,
 *      re-exported from `useAppTheme.js` — no second scoping mechanism);
 *   3. injects the result as exactly one managed
 *      `<style data-openbuild-app-theme="<appSlug>">` element; and
 *   4. removes that element on teardown.
 *
 * Variable-name pinning (design.md Open Question, resolved against the
 * ACTUAL fetched nldesign token CSS in this repo — see
 * `nldesign/css/tokens/*.css` and `nldesign/css/systems/nldesign/theme.css`,
 * not guessed):
 *
 *   - The real per-org token files (e.g. `tokens/amsterdam.css`, the exact
 *     asset `nldesign-theme-selection`'s scoped applier fetches and
 *     rewrites) declare ONLY `--nldesign-*`-prefixed custom properties —
 *     never `--color-*` directly. The `--color-primary: var(--nldesign-
 *     color-primary) !important` mapping only exists in nldesign's
 *     GLOBAL, unscoped `theme.css`/`overrides.css` (loaded instance-wide
 *     when nldesign is the active NC theme) — a separate mechanism this
 *     per-app scoped feature does not control or depend on.
 *   - So within THIS scope, nldesign's own scoped applier
 *     (`useAppTheme.js`) only ever injects `--nldesign-*` names — it
 *     never sets `--color-primary` in-scope. Pure DOM injection order
 *     therefore cannot make "nldesign wins for any shared variable name"
 *     true on its own: there is no shared name for the cascade to act on.
 *   - This applier resolves that by setting the NC-standard `--color-*`
 *     names (so existing NcButton/NcTextField/etc. inside the app pick up
 *     the custom theme with zero component changes — D1's whole point)
 *     using a `var(--nldesign-color-*, <appThemeColor>)` FALLBACK chain:
 *     when nldesign's scoped style (injected after this one, per D3) ALSO
 *     defines `--nldesign-color-primary` in this exact scope, the var()
 *     resolves to nldesign's value; otherwise it falls back to this
 *     applier's own literal color. This is the mechanism that actually
 *     implements "an active nldesign theme takes precedence" against the
 *     real fetched CSS, not injection-order-only.
 *   - `secondaryColor`/`accentColor` have no native NC-standard
 *     equivalent (NC's `--color-warning`/`--color-error`/etc. are
 *     semantically unrelated brand slots), so they are exposed as
 *     app-scoped `--ob-theme-secondary`/`--ob-theme-accent` custom
 *     properties, consumed only by this app's own branded-header
 *     rendering (`AppBrandedHeader.vue`) — never a competing global
 *     namespace for colors NC components do not read.
 *
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-an-active-nldesign-theme-takes-precedence-over-apptheme-colors
 */
import { SCOPE_ATTR } from './useAppTheme.js'
import { accessibleTextColor } from '../services/checkThemeContrast.js'

const STYLE_ATTR = 'data-openbuild-app-theme'

export { SCOPE_ATTR }

/**
 * Build the scoped CSS declarations for an `appTheme` object. Exported
 * standalone (pure, no DOM) so it is directly unit-testable.
 *
 * @param {object} theme - the `runtime.appTheme` object.
 * @param {string} scopeSelector - e.g. `[data-openbuild-theme-scope="kap"]`.
 * @return {string} - the scoped CSS text.
 */
export function buildAppThemeCss(theme, scopeSelector) {
	const primary = theme.primaryColor
	const secondary = theme.secondaryColor
	const accent = theme.accentColor
	const primaryText = accessibleTextColor(primary)
	const decls = [
		`--color-primary: var(--nldesign-color-primary, ${primary});`,
		`--color-primary-element: var(--nldesign-color-primary, ${primary});`,
		`--color-primary-element-hover: var(--nldesign-color-primary-hover, ${primary});`,
		`--color-primary-element-text: var(--nldesign-color-primary-text, ${primaryText});`,
		`--color-primary-text: var(--nldesign-color-primary-text, ${primaryText});`,
		`--ob-theme-secondary: ${secondary};`,
		`--ob-theme-accent: ${accent};`,
	]
	return `${scopeSelector} {\n\t${decls.join('\n\t')}\n}`
}

/**
 * appTheme applier bound to one virtual-app slug + render root.
 *
 * @param {object} [opts] - options.
 * @param {Document|object} [opts.doc] - document injection for tests.
 * @return {{ apply: Function, teardown: Function }}
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
 */
export function useAppCustomTheme(opts = {}) {
	const doc = opts.doc || (typeof document !== 'undefined' ? document : null)

	/**
	 * Apply the manifest's `runtime.appTheme` to the virtual-app render root.
	 * No-op (and removes any prior managed style) when the manifest declares
	 * no appTheme, or the theme is missing a required color.
	 *
	 * MUST be called (and complete) BEFORE the sibling `useAppTheme` (nldesign)
	 * applier for the same slug — design.md Decision D3 / REQ "An active
	 * nldesign theme takes precedence over appTheme colors".
	 *
	 * @param {object} manifest - the resolved (version-routed) manifest.
	 * @param {string} appSlug - the virtual-app slug used as the scope value.
	 * @return {boolean} - true when a scoped style was injected.
	 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
	 */
	function apply(manifest, appSlug) {
		teardown(appSlug)
		const theme = manifest && manifest.runtime && manifest.runtime.appTheme
		if (!theme || !appSlug || !doc) {
			return false
		}
		if (typeof theme.primaryColor !== 'string' || typeof theme.secondaryColor !== 'string' || typeof theme.accentColor !== 'string') {
			return false
		}
		const scopeSelector = `[${SCOPE_ATTR}="${cssAttrEscape(appSlug)}"]`
		const css = buildAppThemeCss(theme, scopeSelector)
		const style = doc.createElement('style')
		style.setAttribute(STYLE_ATTR, appSlug)
		style.textContent = css
		;(doc.head || doc.body || doc.documentElement).appendChild(style)
		return true
	}

	/**
	 * Remove the managed style element for this app slug, if present.
	 *
	 * @param {string} appSlug - the virtual-app slug.
	 * @return {void}
	 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
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

	return { apply, teardown }
}

/**
 * Escape a slug for safe use inside an attribute-selector string literal.
 *
 * @param {string} value - the slug.
 * @return {string}
 */
function cssAttrEscape(value) {
	return String(value).replace(/["\\]/g, '\\$&')
}
