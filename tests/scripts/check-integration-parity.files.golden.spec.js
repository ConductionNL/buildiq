/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of check-integration-parity over a repository whose files sit
 * where the walk must not read them (vendor and node_modules folders, below
 * the depth bound, under a name that is not PHP) and whose schema declaration
 * files hold blocks that are not objects.
 */
import { spawnSync } from 'child_process'
import * as fs from 'fs'
import * as os from 'os'
import * as path from 'path'
import { afterAll, describe, expect, it } from 'vitest'

const SCRIPT = path.resolve(__dirname, '../../scripts/check-integration-parity.js')
const roots = []

/**
 * Write a fixture repository and return its root.
 *
 * @param {Object<string, string>} files Relative path to file contents.
 *
 * @return {string} The fixture root.
 */
function fixture(files) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'buildiq-parity-files-'))
	roots.push(root)
	for (const [rel, text] of Object.entries(files)) {
		fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
		fs.writeFileSync(path.join(root, rel), text)
	}
	return root
}

/**
 * Run the parity script over a fixture, with the fixture root normalised.
 *
 * @param {string} root The fixture root.
 *
 * @return {{status: number, stdout: string, stderr: string}} The run.
 */
function run(root) {
	const p = spawnSync(process.execPath, [SCRIPT, root], { encoding: 'utf8' })
	const clean = (text) => text.split(root).join('<root>')
	return { status: p.status, stdout: clean(p.stdout), stderr: clean(p.stderr) }
}

/**
 * A provider whose getId() returns the id.
 *
 * @param {string} id The provider id.
 *
 * @return {string} The PHP source.
 */
function provider(id) {
	return `<?php
class P extends AbstractIntegrationProvider {
	public function getId(): string { return '${id}'; }
}
`
}

const DEEP = 'lib/' + 'd/'.repeat(12) + 'Deep.php'
const NEAR = 'lib/' + 'd/'.repeat(9) + 'Near.php'

describe('check-integration-parity file walk golden output', () => {
	afterAll(() => {
		for (const root of roots) {
			fs.rmSync(root, { recursive: true, force: true })
		}
	})

	it('reads only the PHP, JS and declaration files the walk may see', () => {
		expect(
			run(
				fixture({
					'lib/Ok/OkProvider.php': provider('ok'),
					'lib/vendor/Hidden.php': provider('hidden'),
					'lib/node_modules/x/Mod.php': provider('mod'),
					'lib/coverage/Cov.php': provider('cov'),
					'lib/Notes.php.txt': provider('notes'),
					[DEEP]: provider('deep'),
					[NEAR]: provider('near'),
					'src/ok.js': `registerIntegration({ id: 'ok', tab: T, widget: W, offlineConfig: { plannedSchema: '0', titleField: 'x' } })
`,
					'src/vendor/v.js': `registerIntegration({ id: 'vendored', tab: T, widget: W })
`,
					'src/notes.txt': `registerIntegration({ id: 'texted', tab: T, widget: W })
`,
					'lib/Settings/text.json': '{"components":{"schemas":"x"}}',
					'lib/Settings/number.json': '{"components":{"schemas":5}}',
					'lib/Settings/broken.json': '{"components":',
					'lib/Settings/null.json': 'null',
					'lib/Settings/list.json':
						'{"components":{"schemas":[{"slug":"listed","properties":{"x":{}}}]}}',
				}),
			),
		).toMatchSnapshot()
	})
})
