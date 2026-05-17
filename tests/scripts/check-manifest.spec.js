// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vitest spec for scripts/check-manifest.js (openbuilt#10 task 4.3).
 *
 * The validator binds the canonical ADR-024 schema
 * (`@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json`) to
 * Ajv 2020 and asserts that the seed manifests we ship in the repo
 * (`src/manifest.json`, `lib/Resources/wizard/default-manifest.json`)
 * remain structurally valid.
 *
 * The integration tests in this spec invoke the script as a
 * subprocess so we cover the actual CLI behaviour, not a re-import
 * of its internals.
 */

import { describe, it, expect } from 'vitest'
import { execFileSync } from 'node:child_process'
import { writeFileSync, mkdtempSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'

const REPO_ROOT = resolve(__dirname, '../..')
const SCRIPT = resolve(REPO_ROOT, 'scripts/check-manifest.js')

function runValidator(args = []) {
	try {
		const out = execFileSync('node', [SCRIPT, ...args], {
			cwd: REPO_ROOT,
			stdio: ['ignore', 'pipe', 'pipe'],
		})
		return { code: 0, stdout: out.toString(), stderr: '' }
	} catch (err) {
		return {
			code: err.status ?? 1,
			stdout: (err.stdout || '').toString(),
			stderr: (err.stderr || '').toString(),
		}
	}
}

describe('check-manifest CLI', () => {
	it('passes for the OpenBuilt shell + wizard seed (default targets)', () => {
		const { code, stdout } = runValidator()
		expect(code).toBe(0)
		expect(stdout).toContain('PASS src/manifest.json')
		expect(stdout).toContain('PASS lib/Resources/wizard/default-manifest.json')
	})

	it('passes for a valid hand-rolled manifest', () => {
		const dir = mkdtempSync(join(tmpdir(), 'check-manifest-'))
		const file = join(dir, 'ok.json')
		try {
			writeFileSync(file, JSON.stringify({
				version: '1.0.0',
				menu: [
					{ id: 'home', label: 'Home', icon: 'icon-home', route: 'Home', order: 10 },
				],
				pages: [
					{ id: 'Home', route: '/', type: 'dashboard', title: 'Home', config: { widgets: [], layout: [] } },
				],
			}))
			const { code, stdout } = runValidator([file])
			expect(code).toBe(0)
			expect(stdout).toContain('PASS')
		} finally {
			rmSync(dir, { recursive: true, force: true })
		}
	})

	it('fails (exit 1) for a manifest missing required fields', () => {
		const dir = mkdtempSync(join(tmpdir(), 'check-manifest-'))
		const file = join(dir, 'broken.json')
		try {
			// Missing `version` AND `pages`.
			writeFileSync(file, JSON.stringify({ menu: [] }))
			const { code, stderr } = runValidator([file])
			expect(code).toBe(1)
			expect(stderr).toContain('FAIL')
			// Schema-validator surfaces the missing-required-property error.
			expect(stderr).toMatch(/required property|version|pages/i)
		} finally {
			rmSync(dir, { recursive: true, force: true })
		}
	})

	it('substitutes the {registerSlug} placeholder so wizard seeds validate', () => {
		const dir = mkdtempSync(join(tmpdir(), 'check-manifest-'))
		const file = join(dir, 'wizard-like.json')
		try {
			writeFileSync(file, JSON.stringify({
				version: '1.0.0',
				menu: [
					{ id: 'msgs', label: 'Messages', icon: 'icon-comment', route: 'Msgs', order: 10 },
				],
				pages: [
					{
						id: 'Msgs',
						route: '/messages',
						type: 'index',
						title: 'Messages',
						config: { register: '{registerSlug}', schema: 'hello-message', columns: ['body'] },
					},
				],
			}))
			const { code, stdout } = runValidator([file])
			expect(code).toBe(0)
			expect(stdout).toContain('PASS')
		} finally {
			rmSync(dir, { recursive: true, force: true })
		}
	})
})
