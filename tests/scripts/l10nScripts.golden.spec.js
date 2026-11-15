/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for scripts/check-schema-l10n.js and scripts/build-l10n-js.js.
 * Each case copies a script into a throwaway repository, runs it, and compares
 * its exit code, output and the files present afterwards with the snapshot.
 *
 * @spec exclude build tooling, not product behaviour
 */
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const REPO = path.resolve(__dirname, '..', '..')

const json = (value) => JSON.stringify(value, null, 2)

const SCHEMAS = {
	'lib/Settings/buildiq_register.json': json({
		components: {
			schemas: {
				Meeting: {
					title: 'Meeting',
					properties: {
						name: { title: 'Name', description: 'Full name' },
						status: {
							title: 'Status',
							description: 'Full name',
							'x-enum-labels': { open: 'Open', closed: 'Closed' },
						},
						tags: { items: { 'x-enum-labels': { a: 'Tag A', b: ' ' } } },
						broken: null,
						count: 3,
						blank: { title: '', description: 42 },
					},
				},
				List: [{ properties: { item: { title: 'Item title' } } }, 'text'],
			},
		},
	}),
	'lib/Settings/register.d/motion.json': json({
		title: 'Motion',
		properties: { days: { title: 'Days', description: 'Name' } },
		nested: {
			properties: 'not an object',
			other: { properties: [] },
			listed: { title: 'Array props', properties: [{ title: 'Array item' }] },
		},
	}),
	'lib/Settings/register.d/notes.txt': 'not json',
	'lib/Settings/register.d/bad.json': '{ broken',
}

const CATALOGUE = {
	'l10n/en.json': json({
		translations: { Meeting: 'Meeting', Name: 'Name', 'Tag A': 'Tag A' },
	}),
}

const L10N = {
	'appinfo/info.xml': '<info>\n\t<id> buildiq </id>\n</info>\n',
	'l10n/en.json': json({
		translations: { Hello: 'Hello', 'Say "hi"': 'Say "hi"' },
	}),
	'l10n/nl.json': json({
		translations: { Hello: 'Hallo' },
		pluralForm: 'nplurals=2; plural=(n > 1);',
	}),
	'l10n/.schema-l10n-baseline.json': json({ uncovered: 3 }),
	'l10n/README.md': 'not a catalogue',
}

const NL_JS =
	'OC.L10N.register(\n    "buildiq",\n    {\n        "Hello": "Hallo"\n    },\n    "nplurals=2; plural=(n > 1);"\n)\n'
const EN_JS =
	'OC.L10N.register(\n    "buildiq",\n    {\n        "Hello": "Hello",\n        "Say \\"hi\\"": "Say \\"hi\\""\n    },\n    "nplurals=2; plural=(n != 1);"\n)\n'

/**
 * Write every file of a fixture under a root directory.
 *
 * @param {string} root - the throwaway repository
 * @param {object} files - repo-relative path -> content
 */
function writeFiles(root, files) {
	for (const [rel, content] of Object.entries(files)) {
		fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
		fs.writeFileSync(path.join(root, rel), content)
	}
}

/**
 * Every file under a directory outside scripts/, as repo-relative path -> content.
 *
 * @param {string} root - the throwaway repository
 * @return {object} the tree
 */
function readTree(root) {
	const tree = {}
	const walk = (dir) => {
		for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
			const abs = path.join(dir, entry.name)
			if (entry.isDirectory()) {
				walk(abs)
			} else if (!abs.includes(`${path.sep}scripts${path.sep}`)) {
				tree[path.relative(root, abs).split(path.sep).join('/')] =
					fs.readFileSync(abs, 'utf8')
			}
		}
	}
	walk(root)
	return Object.fromEntries(
		Object.entries(tree).sort(([a], [b]) => a.localeCompare(b)),
	)
}

/**
 * Run one script in a fresh repository built from a case description.
 *
 * @param {object} spec - { script, files, args }
 * @return {object} exit code, output and the files present afterwards
 */
function runCase(spec) {
	const root = fs.mkdtempSync(path.join(os.tmpdir(), 'l10n-golden-'))
	try {
		writeFiles(root, {
			[`scripts/${spec.script}`]: fs.readFileSync(
				path.join(REPO, 'scripts', spec.script),
				'utf8',
			),
		})
		writeFiles(root, spec.files)
		const run = spawnSync(
			process.execPath,
			[path.join(root, 'scripts', spec.script), ...(spec.args || [])],
			{
				cwd: root,
				encoding: 'utf8',
			},
		)
		const normalise = (text) =>
			text.replace(/(does not parse|is unreadable): .*/g, '$1: <message>')
		return {
			status: run.status,
			stdout: normalise(run.stdout),
			stderr: normalise(run.stderr),
			files: readTree(root),
		}
	} finally {
		fs.rmSync(root, { recursive: true, force: true })
	}
}

const CASES = {
	'schema-l10n: at the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 10 }),
		},
	},
	'schema-l10n: --list': {
		script: 'check-schema-l10n.js',
		args: ['--list'],
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 10 }),
		},
	},
	'schema-l10n: --update': {
		script: 'check-schema-l10n.js',
		args: ['--update'],
		files: { ...SCHEMAS, ...CATALOGUE },
	},
	'schema-l10n: --update and --list': {
		script: 'check-schema-l10n.js',
		args: ['--list', '--update'],
		files: { ...SCHEMAS, ...CATALOGUE },
	},
	'schema-l10n: no baseline': {
		script: 'check-schema-l10n.js',
		files: { ...SCHEMAS, ...CATALOGUE },
	},
	'schema-l10n: grew past the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 5 }),
		},
	},
	'schema-l10n: exactly at the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 7 }),
		},
	},
	'schema-l10n: one past the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 6 }),
		},
	},
	'schema-l10n: one below the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 8 }),
		},
	},
	'schema-l10n: a schema-shaped file that is not .json': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'lib/Settings/register.d/draft.json.bak': json({
				title: 'Draft only',
				properties: { note: { title: 'Draft note' } },
			}),
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 7 }),
		},
	},
	'schema-l10n: fell below the baseline': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			...CATALOGUE,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 12 }),
		},
	},
	'schema-l10n: no catalogue': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 13 }),
		},
	},
	'schema-l10n: catalogue without translations': {
		script: 'check-schema-l10n.js',
		files: {
			...SCHEMAS,
			'l10n/en.json': json({}),
			'l10n/.schema-l10n-baseline.json': json({ uncovered: 13 }),
		},
	},
	'schema-l10n: no lib/Settings': {
		script: 'check-schema-l10n.js',
		files: { 'l10n/.schema-l10n-baseline.json': json({ uncovered: 0 }) },
	},

	'l10n-js: writes every catalogue': { script: 'build-l10n-js.js', files: L10N },
	'l10n-js: --check when current': {
		script: 'build-l10n-js.js',
		args: ['--check'],
		files: { ...L10N, 'l10n/en.js': EN_JS, 'l10n/nl.js': NL_JS },
	},
	'l10n-js: --check when stale': {
		script: 'build-l10n-js.js',
		args: ['--check'],
		files: { ...L10N, 'l10n/en.js': 'old\n' },
	},
	'l10n-js: rewrites only the stale one': {
		script: 'build-l10n-js.js',
		files: { ...L10N, 'l10n/nl.js': NL_JS, 'l10n/en.js': 'old\n' },
	},
	'l10n-js: --check with one stale': {
		script: 'build-l10n-js.js',
		args: ['--check'],
		files: { ...L10N, 'l10n/nl.js': NL_JS, 'l10n/en.js': 'old\n' },
	},
	'l10n-js: empty translations': {
		script: 'build-l10n-js.js',
		files: {
			'appinfo/info.xml': '<id>buildiq</id>',
			'l10n/de.json': json({ translations: {} }),
		},
	},
	'l10n-js: no catalogue': {
		script: 'build-l10n-js.js',
		files: {
			'appinfo/info.xml': '<id>buildiq</id>',
			'l10n/.schema-l10n-baseline.json': '{}',
		},
	},
	'l10n-js: catalogue does not parse': {
		script: 'build-l10n-js.js',
		files: { ...L10N, 'l10n/nl.json': '{ nope' },
	},
	'l10n-js: catalogue without translations': {
		script: 'build-l10n-js.js',
		files: { ...L10N, 'l10n/nl.json': json({ pluralForm: 'x' }) },
	},
	'l10n-js: info.xml without an id': {
		script: 'build-l10n-js.js',
		files: { ...L10N, 'appinfo/info.xml': '<info></info>' },
	},
}

describe('l10n build and check scripts', () => {
	for (const [name, spec] of Object.entries(CASES)) {
		it(name, () => {
			expect(runCase(spec)).toMatchSnapshot()
		})
	}
})
