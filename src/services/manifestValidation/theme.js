// SPDX-License-Identifier: EUPL-1.2
/**
 * theme — app-side validation of the manifest v2 `runtime.theme` object
 * (NL Design theme selection, REQ-NTS-001). The canonical
 * `app-manifest-v2.schema.json` carries `runtime` with
 * `additionalProperties: true`, so the library validator accepts the `theme`
 * branch; this module supplies the strict shape checks openbuild needs,
 * surfaced through the `useManifestValidator` pipeline (the same mechanism the
 * workflow/connector/document siblings use).
 *
 * `runtime.theme` is a single OBJECT (not an array): at most one theme per app,
 * no per-page themes in v1.
 *
 * Returned errors are `<pointer>: <i18n-error-code>` strings so the existing
 * path-prefix → inline-mark mechanism (REQ-OBPD-011) lights up the offending
 * editor entry.
 *
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-001
 */

/** The only theme provider supported in v1. */
export const THEME_SOURCES = Object.freeze(['nldesign'])

/** The only keys a theme object may carry. */
const ALLOWED_KEYS = Object.freeze(['source', 'tokenSet', 'tokenSetName', 'preview'])

/** The only keys a `preview` object may carry. */
const ALLOWED_PREVIEW_KEYS = Object.freeze(['primaryColor', 'backgroundColor'])

/** kebab-case token-set id (e.g. `rijkshuisstijl`, `bodegraven-reeuwijk`). */
const KEBAB_RE = /^[a-z0-9]+(-[a-z0-9]+)*$/

/** 3- or 6-digit hex colour. */
const HEX_RE = /^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/

/**
 * Validate the `runtime.theme` object of a manifest.
 *
 * @param {object} manifest - the in-flight manifest.
 * @return {string[]} - list of `<pointer>: <code>` error strings.
 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-001
 */
export function validateTheme(manifest) {
	const errors = []
	const theme = manifest && manifest.runtime && manifest.runtime.theme
	if (theme === undefined) {
		return errors
	}
	const at = (code) => `/runtime/theme: ${code}`
	if (!theme || typeof theme !== 'object' || Array.isArray(theme)) {
		errors.push(at('openbuild.theme.error.invalid-shape'))
		return errors
	}
	for (const key of Object.keys(theme)) {
		if (!ALLOWED_KEYS.includes(key)) {
			errors.push(`/runtime/theme/${key}: openbuild.theme.error.unknown-key`)
		}
	}
	// source
	if (!THEME_SOURCES.includes(theme.source)) {
		errors.push(at('openbuild.theme.error.unknown-source'))
	}
	// tokenSet (present + kebab-case)
	if (typeof theme.tokenSet !== 'string' || theme.tokenSet.trim() === '') {
		errors.push(at('openbuild.theme.error.token-set-required'))
	} else if (!KEBAB_RE.test(theme.tokenSet)) {
		errors.push(at('openbuild.theme.error.token-set-not-kebab'))
	}
	// tokenSetName (present)
	if (typeof theme.tokenSetName !== 'string' || theme.tokenSetName.trim() === '') {
		errors.push(at('openbuild.theme.error.token-set-name-required'))
	}
	// preview (optional, hex colours when present)
	if (theme.preview !== undefined) {
		const preview = theme.preview
		if (!preview || typeof preview !== 'object' || Array.isArray(preview)) {
			errors.push(at('openbuild.theme.error.preview-invalid'))
		} else {
			for (const key of Object.keys(preview)) {
				if (!ALLOWED_PREVIEW_KEYS.includes(key)) {
					errors.push(`/runtime/theme/preview/${key}: openbuild.theme.error.unknown-key`)
				}
			}
			for (const key of ALLOWED_PREVIEW_KEYS) {
				if (preview[key] !== undefined && !HEX_RE.test(String(preview[key]))) {
					errors.push(`/runtime/theme/preview/${key}: openbuild.theme.error.preview-color-not-hex`)
				}
			}
		}
	}
	return errors
}
