// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * checkThemeContrast — pure WCAG 2.x relative-luminance contrast-ratio
 * guardrail for the app-theming feature (openspec/changes/app-theming,
 * design.md Decision D2).
 *
 * Computes the WCAG contrast ratio (`(L1+0.05)/(L2+0.05)` on relative
 * luminance `L = 0.2126R + 0.7152G + 0.0722B` over linearised sRGB
 * channels) for every `appTheme` color against a fixed background
 * reference, and reports every pair that fails its required threshold:
 *   - `primaryColor` as TEXT on the background — WCAG 1.4.3, ≥4.5:1.
 *   - `primaryColor`, `secondaryColor`, `accentColor` each as a
 *     non-text UI element on the background — WCAG 1.4.11, ≥3:1.
 *
 * The background reference is pinned to `#FFFFFF` — Nextcloud's
 * `--color-main-background` default (light theme) value. This is a pure
 * function with no DOM access (design.md: "run client-side before every
 * save"), so it cannot read the instance's actual computed background;
 * v1 does not vary the check per theme (design.md Non-Goals: "Dark-mode-
 * specific theme tokens" deferred), matching the same light-theme-only
 * simplification the feature's colors themselves make.
 *
 * No override/bypass of a failing result exists anywhere in this module
 * or its caller (`AppCustomThemeSection.vue` / `PageDesignerHost.vue`
 * save()) — this is the hard compliance gate the feature exists for.
 *
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
 */

/** NC `--color-main-background` default (light theme) — see module docblock. */
export const BACKGROUND_HEX = '#FFFFFF'

/** WCAG 1.4.3 — normal text minimum contrast ratio. */
export const TEXT_MIN_RATIO = 4.5

/** WCAG 1.4.11 — non-text UI-element minimum contrast ratio. */
export const UI_MIN_RATIO = 3

/**
 * Parse a 3- or 6-digit hex color string into `{r, g, b}` (0-255 each).
 *
 * @param {string} hex - a hex color string, e.g. `#1D4ED8` or `#fff`.
 * @return {?{r: number, g: number, b: number}} - the parsed channels, or null when not a valid hex color.
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
 */
export function parseHexColor(hex) {
	if (typeof hex !== 'string') {
		return null
	}
	const match = /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.exec(hex.trim())
	if (!match) {
		return null
	}
	let h = match[1]
	if (h.length === 3) {
		h = h.split('').map((c) => c + c).join('')
	}
	const num = parseInt(h, 16)
	return { r: (num >> 16) & 255, g: (num >> 8) & 255, b: num & 255 }
}

/**
 * Convert one 0-255 sRGB channel to its linearised value.
 *
 * @param {number} channel - the 0-255 channel value.
 * @return {number} - the linearised 0-1 value.
 */
function srgbChannelToLinear(channel) {
	const s = channel / 255
	return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4)
}

/**
 * WCAG relative luminance of an `{r, g, b}` color.
 *
 * @param {{r: number, g: number, b: number}} rgb - the color channels.
 * @return {number} - the relative luminance (0-1).
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
 */
export function relativeLuminance({ r, g, b }) {
	const R = srgbChannelToLinear(r)
	const G = srgbChannelToLinear(g)
	const B = srgbChannelToLinear(b)
	return 0.2126 * R + 0.7152 * G + 0.0722 * B
}

/**
 * WCAG contrast ratio between two hex colors. Returns null when either
 * color is not a valid hex string.
 *
 * @param {string} hexA - the first hex color.
 * @param {string} hexB - the second hex color.
 * @return {?number} - the contrast ratio (≥1), or null when invalid input.
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
 */
export function contrastRatio(hexA, hexB) {
	const a = parseHexColor(hexA)
	const b = parseHexColor(hexB)
	if (!a || !b) {
		return null
	}
	const lA = relativeLuminance(a)
	const lB = relativeLuminance(b)
	const lighter = Math.max(lA, lB)
	const darker = Math.min(lA, lB)
	return (lighter + 0.05) / (darker + 0.05)
}

/**
 * A readable black/white text color that is accessible against `hex`
 * (used to derive `--color-primary-element-text` in the applier — a
 * primary-colored button needs its own label to stay legible regardless
 * of which primary color the developer picked).
 *
 * @param {string} hex - the background hex color the text sits on.
 * @return {string} - `#000000` or `#ffffff`, whichever contrasts more.
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
 */
export function accessibleTextColor(hex) {
	const black = contrastRatio(hex, '#000000') || 0
	const white = contrastRatio(hex, '#ffffff') || 0
	return white >= black ? '#ffffff' : '#000000'
}

/**
 * Run the full WCAG contrast guardrail against an `appTheme` object.
 *
 * @param {?{primaryColor?: string, secondaryColor?: string, accentColor?: string}} theme - the candidate appTheme.
 * @return {{passed: boolean, failures: Array<{pair: string, ratio: number, required: number, kind: string}>}}
 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
 */
export function checkThemeContrast(theme) {
	if (!theme || typeof theme !== 'object') {
		return { passed: true, failures: [] }
	}
	const checks = [
		{ key: 'primaryColor', kind: 'text', required: TEXT_MIN_RATIO },
		{ key: 'primaryColor', kind: 'ui', required: UI_MIN_RATIO },
		{ key: 'secondaryColor', kind: 'ui', required: UI_MIN_RATIO },
		{ key: 'accentColor', kind: 'ui', required: UI_MIN_RATIO },
	]
	const failures = []
	for (const check of checks) {
		const hex = theme[check.key]
		if (typeof hex !== 'string' || !parseHexColor(hex)) {
			// Shape validation (manifestValidation/appTheme.js) reports the
			// missing/invalid-hex error; the contrast check only evaluates
			// colors that are already valid hex strings.
			continue
		}
		const ratio = contrastRatio(hex, BACKGROUND_HEX)
		if (ratio === null) {
			continue
		}
		if (ratio < check.required) {
			failures.push({
				pair: `${check.key}-on-background`,
				ratio: Math.round(ratio * 100) / 100,
				required: check.required,
				kind: check.kind,
			})
		}
	}
	return { passed: failures.length === 0, failures }
}
