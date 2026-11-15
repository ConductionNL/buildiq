/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Built apps follow the user's dark mode (openbuild-nc-color-variables, row
 * `ux-dark-mode`). A published built app is rendered by CnAppRoot through
 * CnPageRenderer into CnIndexPage and CnDetailPage, plus buildiq's own runtime
 * components. Dark mode then follows from one rule: every colour those surfaces
 * declare goes through a Nextcloud CSS variable, so the theme the user chose in
 * Nextcloud decides it. This spec reads their styles and fails on any literal
 * colour outside a `var()` fallback, which is exactly what would keep a page area
 * light (or its text dark) under the dark theme.
 *
 * @spec openspec/changes/openbuild-nc-color-variables/specs/frontend-foundation/spec.md
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../..')
const LIBRARY = join(ROOT, 'node_modules/@conduction/nextcloud-vue/src/components')

/** The surfaces a built app's index and detail pages are drawn with. */
const BUILT_APP_SURFACES = [
	join(LIBRARY, 'CnAppRoot'),
	join(LIBRARY, 'CnPageRenderer'),
	join(LIBRARY, 'CnIndexPage'),
	join(LIBRARY, 'CnDetailPage'),
	join(ROOT, 'src/components/runtime'),
	join(ROOT, 'src/App.vue'),
]

const COLOUR_DECLARATION = /(?<![-\w])(color|background|background-color|border-color)\s*:\s*([^;{}]+);/g
const LITERAL_COLOUR = /#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(|\b(white|black)\b/

/**
 * Every style file under a path.
 *
 * @param {string} path A file or folder.
 * @return {string[]} The `.vue` and `.css` files.
 */
function styleFiles(path) {
	if (statSync(path).isFile()) {
		return [path]
	}
	return readdirSync(path).flatMap((entry) => {
		const full = join(path, entry)
		if (statSync(full).isDirectory()) {
			return styleFiles(full)
		}
		return /\.(vue|css|scss)$/.test(entry) ? [full] : []
	})
}

/**
 * The style text of a file: the `<style>` blocks of a component, or the whole stylesheet.
 *
 * @param {string} source The file's text.
 * @param {string} path Its path.
 * @return {string} The style text.
 */
function stylesOf(source, path) {
	if (!path.endsWith('.vue')) {
		return source
	}
	return [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n')
}

/**
 * The colour declarations in style text that name a literal colour outside a `var()`
 * fallback and are not marked as intentional.
 *
 * @param {string} styles Style text.
 * @return {string[]} The offending declarations.
 */
function literalColours(styles) {
	const found = []
	for (const line of styles.split('\n')) {
		for (const match of line.matchAll(COLOUR_DECLARATION)) {
			let value = match[2]
			let previous
			do {
				previous = value
				value = value.replace(/var\([^()]*\)/g, '')
			} while (value !== previous)
			if (LITERAL_COLOUR.test(value) && !line.includes('intentional')) {
				found.push(line.trim())
			}
		}
	}
	return found
}

describe('built apps follow the user\'s dark mode', () => {
	it('the scan flags a literal colour and accepts a variable with a fallback', () => {
		expect(literalColours('.a { background: #ffffff; }')).toEqual(['.a { background: #ffffff; }'])
		expect(literalColours('.a { color: rgba(0, 0, 0, 0.5); }')).toHaveLength(1)
		expect(literalColours('.a { background-color: white; }')).toHaveLength(1)
		expect(literalColours('.a { background: var(--color-main-background, #fff); color: var(--color-main-text); }')).toEqual([])
		expect(literalColours('.a { border-color: var(--color-border, var(--x, #ddd)); }')).toEqual([])
	})

	it('index and detail pages declare no colour that ignores the theme', () => {
		const files = BUILT_APP_SURFACES.flatMap(styleFiles)
		expect(files.length).toBeGreaterThan(4)

		const offenders = files.flatMap((path) => literalColours(stylesOf(readFileSync(path, 'utf8'), path))
			.map((line) => `${path.replace(ROOT + '/', '')}: ${line}`))
		expect(offenders).toEqual([])
	})

	it('the page surfaces draw their colours from Nextcloud variables', () => {
		const styles = BUILT_APP_SURFACES.flatMap(styleFiles)
			.map((path) => stylesOf(readFileSync(path, 'utf8'), path))
			.join('\n')

		expect(styles).toMatch(/var\(--color-main-background/)
		expect(styles).toMatch(/var\(--color-(main-text|text-maxcontrast)/)
	})
})
