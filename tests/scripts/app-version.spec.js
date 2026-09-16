// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vitest spec for scripts/app-version.js and its use in webpack.config.js.
 *
 * The user settings footer printed "buildiq 0.2.0" on a 0.7.10 install,
 * because the bundle's `appVersion` came from package.json.
 */

import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const REPO_ROOT = resolve(__dirname, '../..')
const { readAppVersion } = require(resolve(REPO_ROOT, 'scripts/app-version.js'))

describe('readAppVersion', () => {
	it('reads the version element from info.xml, not from its dependency attributes', () => {
		const dir = mkdtempSync(join(tmpdir(), 'app-version-'))
		try {
			const file = join(dir, 'info.xml')
			writeFileSync(
				file,
				'<?xml version="1.0"?>\n<info>\n  <id>buildiq</id>\n'
				+ '  <version>0.7.10-unstable.20260914204410</version>\n'
				+ '  <dependencies><nextcloud min-version="32" max-version="34"/></dependencies>\n</info>\n',
			)
			expect(readAppVersion(file)).toBe('0.7.10-unstable.20260914204410')
		} finally {
			rmSync(dir, { recursive: true, force: true })
		}
	})

	it('answers the version this repository ships in appinfo/info.xml', () => {
		const xml = readFileSync(resolve(REPO_ROOT, 'appinfo/info.xml'), 'utf8')
		const shipped = xml.match(/<version>([^<]+)<\/version>/)[1].trim()
		expect(readAppVersion()).toBe(shipped)
	})
})

describe('webpack.config.js', () => {
	it('defines appVersion from info.xml, even when package.json says otherwise', () => {
		// Load the real config the way `npm run build` does: npm exports the
		// package name and version into the environment. The stale version is
		// passed on purpose, it is exactly what the bundle used to print.
		const script = 'const c = require("./webpack.config.js");'
			+ 'const d = Object.assign({}, ...c.plugins.filter((p) => p.definitions).map((p) => p.definitions));'
			+ 'process.stdout.write("\\nDEFS=" + JSON.stringify(d))'
		const out = execFileSync('node', ['-e', script], {
			cwd: REPO_ROOT,
			encoding: 'utf8',
			env: { ...process.env, npm_package_name: 'buildiq', npm_package_version: '0.2.0' },
		})
		const defs = JSON.parse(out.slice(out.indexOf('DEFS=') + 5))
		expect(JSON.parse(defs.appVersion)).toBe(readAppVersion())
		expect(JSON.parse(defs.appVersion)).not.toBe('0.2.0')
	})
})
