/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * This repo must own a tsconfig at its root.
 *
 * WHY THIS IS A TEST AND NOT A COMMENT
 * ------------------------------------
 * Vite's oxc transform resolves a tsconfig by walking UP from the file it is
 * compiling. Buildiq is developed as a checkout mounted inside the Nextcloud
 * server tree, and that tree has its own `tsconfig.json` which does
 * `"extends": "@vue/tsconfig/tsconfig.json"` — a package that is in neither
 * this app's `package.json` nor its lockfile, so it can never resolve here.
 *
 * With no tsconfig of our own the walk reached that one and every `.ts` spec
 * died before collection with
 * `[TSCONFIG_ERROR] Failed to load tsconfig '@vue/tsconfig/tsconfig.json'`.
 * Measured 2026-09-22: `tests/vitest/shared-instance.spec.ts` reported
 * "1 failed | 175 passed" with **0 tests** collected from it, while the run
 * still printed "1680 passed" — ten real assertions were dark, and the shape
 * of the failure looked nothing like a failing assertion.
 *
 * It passed in a plain clone and on CI, because neither has a parent tsconfig.
 * That is precisely what made it survive: the environment where people read
 * the result is not the environment where they run the tests.
 *
 * Written as `.js` on purpose. A `.ts` guard would be transformed by the very
 * pipeline it is guarding, so the guard would go dark with the thing it guards.
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

// Vitest runs from the directory holding vitest.config.js, which is the repo
// root. Asserted below rather than assumed: reading the wrong directory would
// otherwise let this guard pass while saying nothing.
const repoRoot = process.cwd()

describe('the repo owns its tsconfig', () => {
	it('CONTROL: is reading this app, not some other directory', () => {
		const pkg = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'),
		)
		expect(pkg.name).toBe('buildiq')
	})

	it('has a tsconfig.json at the repo root', () => {
		expect(fs.existsSync(path.join(repoRoot, 'tsconfig.json'))).toBe(true)
	})

	it('does not extend a package this app does not depend on', () => {
		const raw = fs.readFileSync(path.join(repoRoot, 'tsconfig.json'), 'utf8')
		const config = JSON.parse(raw)
		if (config.extends === undefined) {
			expect(config.extends).toBeUndefined()
			return
		}

		// If a base is ever introduced, it has to be installable from here.
		const pkg = JSON.parse(
			fs.readFileSync(path.join(repoRoot, 'package.json'), 'utf8'),
		)
		const declared = {
			...(pkg.dependencies || {}),
			...(pkg.devDependencies || {}),
		}
		const base = String(config.extends)
		const owner = base.startsWith('@')
			? base.split('/').slice(0, 2).join('/')
			: base.split('/')[0]
		expect(Object.keys(declared)).toContain(owner)
	})
})
