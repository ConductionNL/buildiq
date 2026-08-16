/**
 * iconCatalogues.js
 *
 * Builds the icon catalogues passed to the shared CnIconPicker (enriched
 * multi-source mode) in the app-creation wizard, and resolves a picked icon
 * into an app-icon SVG the wizard can attach to the created Application.
 *
 * The @conduction/nextcloud-vue library bundles no icon pack — the consumer
 * (OpenBuild) owns and licenses the data and passes it in through the adapters:
 *   - MDI is built from the optional @mdi/js dependency (Apache-2.0 / MIT).
 *   - OpenGemeenten is the full CC0 set from github.com/OpenGemeenten/Iconenset
 *     (Regular style, 250 glyphs), extracted into ./openGemeentenIcons.json.
 *
 * The picker emits the icon *name* for a catalogue pick (an @mdi/js export name
 * such as `mdiAccount`, or an OpenGemeenten key such as `paspoort`), or raw
 * `<svg>` markup for the custom-SVG editor / an uploaded file. `resolveAppIcon`
 * turns any of those into a standalone monochrome SVG string, applying the
 * Nextcloud app-icon fill convention (white light glyph for the dark app
 * header; no-fill dark glyph for light backgrounds) so a single pick yields a
 * correct light/dark pair.
 *
 * @category Utils
 * @license  EUPL-1.2
 */

import { fromMdiJs, fromOpenGemeenten } from '@conduction/nextcloud-vue'
import * as mdiJs from '@mdi/js'
import DOMPurify from 'dompurify'
import openGemeentenIcons from './openGemeentenIcons.json'

/**
 * The icon sources offered by the wizard's picker, in tab order.
 *
 * @type {string[]}
 */
export const ICON_SOURCES = ['mdi', 'opengemeenten']

/**
 * The OpenGemeenten viewBox (all Regular-style glyphs are 48×48); MDI is 24×24.
 */
const OG_VIEWBOX = '0 0 48 48'
const MDI_VIEWBOX = '0 0 24 24'

/**
 * Build the `catalogues` map for CnIconPicker's enriched multi-source mode.
 * Supplying the MDI catalogue explicitly (rather than relying on the picker's
 * lazy @mdi/js load) keeps the source deterministic across builds.
 *
 * @return {{mdi: Array<object>, opengemeenten: Array<object>}} the catalogues.
 */
export function buildIconCatalogues() {
	return {
		mdi: fromMdiJs(mdiJs),
		opengemeenten: fromOpenGemeenten(openGemeentenIcons),
	}
}

/**
 * Look up the SVG `path` + `viewBox` for a picked catalogue value.
 *
 * @param {string} value the emitted picker value (mdi export name or OG key).
 * @return {{path: string, viewBox: string}|null} the glyph, or null if unknown.
 */
function lookupGlyph(value) {
	if (typeof mdiJs[value] === 'string') {
		return { path: mdiJs[value], viewBox: MDI_VIEWBOX }
	}
	const og = openGemeentenIcons.find((e) => e.key === value)
	if (og) {
		return { path: og.path, viewBox: og.viewBox || OG_VIEWBOX }
	}
	return null
}

/**
 * Escape the double quotes that could appear in an SVG path `d` string so it is
 * safe to interpolate into a `d="…"` attribute.
 *
 * @param {string} d the path data.
 * @return {string} the attribute-safe path data.
 */
function escapePath(d) {
	return String(d).replace(/"/g, '&quot;')
}

/**
 * Resolve a picked icon value into a standalone app-icon SVG string.
 *
 * Accepts a catalogue value (mdi export name / OG key) or raw `<svg>` markup
 * (from the custom-SVG editor or an uploaded file). Catalogue picks are
 * synthesized into a single-path monochrome glyph following the Nextcloud
 * app-icon fill convention; raw SVG is returned unchanged (the author owns its
 * styling). Returns null for an empty or unresolvable value.
 *
 * @param {string|null} value  the picker value.
 * @param {object}      [opts] options.
 * @param {boolean}     [opts.dark] when true, omit the fill so the glyph renders
 *                                  dark on a light background (app-dark.svg
 *                                  convention); otherwise fill white for the
 *                                  dark app header.
 * @return {string|null} the SVG markup, or null.
 */
export function resolveAppIcon(value, { dark = false } = {}) {
	if (typeof value !== 'string' || value.trim() === '') {
		return null
	}
	const trimmed = value.trim()
	if (trimmed.startsWith('<svg')) {
		// Raw SVG (custom editor / uploaded file) — sanitize before use so an
		// author-supplied <script>/event-handler cannot execute in a preview or
		// once persisted and served (harden-xss-dos-csrf).
		return DOMPurify.sanitize(trimmed, {
			USE_PROFILES: { svg: true, svgFilters: true },
		})
	}
	const glyph = lookupGlyph(trimmed)
	if (!glyph) {
		return null
	}
	const fill = dark ? '' : ' fill="#ffffff"'
	return (
		`<svg xmlns="http://www.w3.org/2000/svg" viewBox="${glyph.viewBox}">`
		+ `<path d="${escapePath(glyph.path)}"${fill}/></svg>`
	)
}
