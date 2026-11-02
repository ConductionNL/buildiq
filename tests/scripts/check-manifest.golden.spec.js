// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Golden output of scripts/check-manifest.js run as a CLI over mixed target
 * lists: the default targets, relative and absolute paths, a missing file,
 * unparseable JSON, valid and invalid v1 and v2 manifests, token
 * substitution and engine-owned blocks. The exit code, stdout and stderr of
 * every run are pinned.
 */

import { spawnSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, relative, resolve } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'

const REPO_ROOT = resolve(__dirname, '../..')
const SCRIPT = resolve(REPO_ROOT, 'scripts/check-manifest.js')
const DIR = mkdtempSync(join(tmpdir(), 'check-manifest-golden-'))

const MENU = [
	{ id: 'home', label: 'Home', icon: 'icon-home', route: 'Home', order: 10 },
]
const PAGE = {
	id: 'Home',
	route: '/',
	type: 'dashboard',
	title: 'Home',
	config: { widgets: [], layout: [] },
}
const INDEX = {
	id: 'Msgs',
	route: '/messages',
	type: 'index',
	title: 'Messages',
	config: {
		register: '{registerSlug}',
		schema: 'hello-message',
		columns: ['body'],
	},
}

const FILES = {
	'valid.json': { version: '1.0.0', menu: MENU, pages: [PAGE] },
	'broken.json': { menu: [] },
	'token.json': {
		version: '1.0.0',
		menu: MENU,
		pages: [INDEX, null, 'x', { ...PAGE, config: undefined }],
	},
	'engine.json': {
		version: '1.0.0',
		menu: MENU,
		pages: [PAGE],
		observability: { a: 1 },
		deepLinks: [],
	},
	'extra.json': { version: '1.0.0', menu: MENU, pages: [PAGE], unknownKey: true },
	'v2.json': {
		$schema: 'https://x/app-manifest-v2.schema.json',
		version: '1.0.0',
		menu: MENU,
		pages: [PAGE],
	},
	'v2-broken.json': {
		$schema: 'https://x/app-manifest-v2.schema.json',
		menu: 'no',
	},
	'array.json': [],
	'string.json': 'text',
	'pages-not-array.json': { version: '1.0.0', menu: MENU, pages: {} },
	'bad-types.json': { version: 7, menu: [{ id: 1 }], pages: [{ id: 'x' }] },
}

for (const [name, body] of Object.entries(FILES)) {
	writeFileSync(join(DIR, name), JSON.stringify(body))
}
writeFileSync(join(DIR, 'unparseable.json'), '{ not json')

const abs = (name) => join(DIR, name)
const rel = (name) => relative(REPO_ROOT, join(DIR, name))

const RUNS = {
	defaults: [],
	'one valid': [abs('valid.json')],
	'one broken': [abs('broken.json')],
	'relative path': [rel('valid.json')],
	missing: [abs('missing.json')],
	'missing only, then valid': [abs('missing.json'), abs('valid.json')],
	unparseable: [abs('unparseable.json')],
	'unparseable then valid': [abs('unparseable.json'), abs('valid.json')],
	'broken then valid': [abs('broken.json'), abs('valid.json')],
	'valid then broken': [abs('valid.json'), abs('broken.json')],
	token: [abs('token.json')],
	engine: [abs('engine.json')],
	extra: [abs('extra.json')],
	v2: [abs('v2.json')],
	'v2 broken': [abs('v2-broken.json')],
	'non-objects': [abs('array.json'), abs('string.json')],
	'pages not an array': [abs('pages-not-array.json')],
	'bad types': [abs('bad-types.json')],
	everything: Object.keys(FILES)
		.map(abs)
		.concat([abs('unparseable.json'), abs('missing.json'), 'src/manifest.json']),
}

/**
 * Run the CLI and describe the outcome, with the temp dir masked.
 *
 * @param {Array<string>} args - CLI arguments.
 * @param {string} [cwd] - working directory of the run.
 * @return {string}
 */
function run(args, cwd = REPO_ROOT) {
	const result = spawnSync('node', [SCRIPT, ...args], {
		cwd,
		stdio: ['ignore', 'pipe', 'pipe'],
	})
	const code = result.status
	const stdout = result.stdout.toString()
	const stderr = result.stderr.toString()
	const mask = (s) =>
		s.split(DIR).join('<tmp>').split(relative(REPO_ROOT, DIR)).join('<rel>')
	return `code=${code}\n--stdout--\n${mask(stdout)}--stderr--\n${mask(stderr)}`
}

describe('check-manifest CLI golden', () => {
	afterAll(() => rmSync(DIR, { recursive: true, force: true }))

	for (const [name, args] of Object.entries(RUNS)) {
		it(`pins ${name}`, () => {
			expect(run(args)).toMatchSnapshot()
		}, 60000)
	}

	it('pins relative paths resolved against the repo, not the working directory', () => {
		expect(run(['src/manifest.json', 'valid.json'], DIR)).toMatchSnapshot()
	}, 60000)
})
