// SPDX-License-Identifier: EUPL-1.2
/**
 * appTheme — app-side validation of the manifest v2 `runtime.appTheme`
 * object (app-theming, REQ "appTheme manifest block declares logo, colors
 * and header style"). Mirrors `manifestValidation/theme.js`'s shape and
 * conventions exactly (same `<pointer>: <i18n-error-code>` string format,
 * same `useManifestValidator` wiring) since `runtime.appTheme` is a
 * sibling of `runtime.theme` under the same `additionalProperties: true`
 * `runtime` block in the canonical `app-manifest-v2.schema.json`.
 *
 * `runtime.appTheme` is a single OBJECT (not an array): at most one
 * appTheme per app, no per-page themes in v1 (design.md Non-Goals).
 *
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
 */

/** The only header-style values a manifest may declare. */
export const HEADER_STYLES = Object.freeze(['default', 'compact', 'branded'])

/** The only keys an `appTheme` object may carry. */
const ALLOWED_KEYS = Object.freeze(['logoRef', 'primaryColor', 'secondaryColor', 'accentColor', 'headerStyle'])

/** The only keys a `logoRef` object may carry. */
const ALLOWED_LOGO_REF_KEYS = Object.freeze(['ref'])

/** 3- or 6-digit hex colour. */
const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

/**
 * Validate the `runtime.appTheme` object of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
 */
export function validateAppTheme(manifest) {
	const errors = []
	const theme = manifest && manifest.runtime && manifest.runtime.appTheme
	if (theme === undefined) {
		// Additive, optional — a themeless app reports nothing (byte-identical
		// serialization scenario).
		return errors
	}
	const at = (code) => `/runtime/appTheme: ${code}`
	if (!theme || typeof theme !== 'object' || Array.isArray(theme)) {
		errors.push(at('openbuild.appTheme.error.invalid-shape'))
		return errors
	}
	for (const key of Object.keys(theme)) {
		if (!ALLOWED_KEYS.includes(key)) {
			errors.push(`/runtime/appTheme/${key}: openbuild.appTheme.error.unknown-key`)
		}
	}
	// logoRef (nullable; when present, an OR-attached-file ref object).
	if (theme.logoRef !== undefined && theme.logoRef !== null) {
		const logoRef = theme.logoRef
		if (!logoRef || typeof logoRef !== 'object' || Array.isArray(logoRef)) {
			errors.push(at('openbuild.appTheme.error.logo-ref-invalid-shape'))
		} else {
			for (const key of Object.keys(logoRef)) {
				if (!ALLOWED_LOGO_REF_KEYS.includes(key)) {
					errors.push(`/runtime/appTheme/logoRef/${key}: openbuild.appTheme.error.unknown-key`)
				}
			}
			if (typeof logoRef.ref !== 'string' || logoRef.ref.trim() === '') {
				errors.push(at('openbuild.appTheme.error.logo-ref-required'))
			}
		}
	}
	// Colors — required, hex only.
	for (const key of ['primaryColor', 'secondaryColor', 'accentColor']) {
		const value = theme[key]
		if (typeof value !== 'string' || value.trim() === '') {
			errors.push(`/runtime/appTheme/${key}: openbuild.appTheme.error.color-required`)
		} else if (!HEX_RE.test(value)) {
			errors.push(`/runtime/appTheme/${key}: openbuild.appTheme.error.color-not-hex`)
		}
	}
	// headerStyle — closed enum.
	if (!HEADER_STYLES.includes(theme.headerStyle)) {
		errors.push(at('openbuild.appTheme.error.unknown-header-style'))
	}
	return errors
}
