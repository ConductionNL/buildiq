/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sass refuses a stylesheet whose `@use` comes after a style rule ("@use rules
 * must be written before any other rules"), and that refusal fails the whole
 * webpack build. This spec reads every SCSS style block under `src/` and fails
 * on a rule written before the block's last `@use` or `@forward`. Variable
 * declarations and `@charset` may precede them, as Sass allows.
 *
 * @spec exclude build guard for the Sass module system, not a product requirement
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../..')
const SRC = join(ROOT, 'src')

/**
 * Every .vue and .scss file below a directory.
 *
 * @param {string} dir The directory.
 * @return {string[]} The file paths.
 */
function sources(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) {
			return sources(path)
		}
		return /\.(vue|scss)$/.test(name) ? [path] : []
	})
}

/**
 * The SCSS stylesheets in a file: the file itself for .scss, each
 * `<style lang="scss">` block for .vue.
 *
 * @param {string} path The file.
 * @return {string[]} The stylesheets.
 */
function stylesheets(path) {
	const text = readFileSync(path, 'utf8')
	if (path.endsWith('.scss')) {
		return [text]
	}
	return [...text.matchAll(/<style\b[^>]*lang=["']scss["'][^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1])
}

/**
 * Whether a stylesheet writes a rule before its last `@use` or `@forward`.
 *
 * @param {string} scss The stylesheet.
 * @return {boolean} True when the order is one Sass refuses.
 */
export function hasRuleBeforeUse(scss) {
	const code = scss.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '')
	const uses = [...code.matchAll(/^\s*@(use|forward)\b/gm)]
	if (uses.length === 0) {
		return false
	}
	const head = code.slice(0, uses[uses.length - 1].index)
	const statements = head.split(';').map((s) => s.trim()).filter((s) => s !== '')
	return statements.some((s) => !/^(@use|@forward|@charset|\$[\w-]+\s*:)/.test(s))
}

describe('SCSS @use order', () => {
	it('recognises a rule written before @use', () => {
		expect(hasRuleBeforeUse(".a {\n\tgap: 4px;\n}\n\n@use 'x' as c;\n")).toBe(true)
		expect(hasRuleBeforeUse("$w: 1px;\n@use 'x' as c;\n.a { gap: 4px; }\n")).toBe(false)
		expect(hasRuleBeforeUse("// .a { }\n@use 'x';\n")).toBe(false)
	})

	it('no stylesheet under src/ writes a rule before its @use', () => {
		const offenders = sources(SRC)
			.filter((path) => stylesheets(path).some(hasRuleBeforeUse))
			.map((path) => relative(ROOT, path))
		expect(offenders).toEqual([])
	})
})
