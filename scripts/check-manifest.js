#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * check-manifest — validate openbuild manifests against the canonical
 * @conduction/nextcloud-vue ADR-024 schema.
 *
 * Implements openbuild#10 task 4.3 — "Run npm run check:manifest on the
 * seeded hello-world manifest blob in tests; passes against the canonical
 * schema pinned in package.json."
 *
 * Defaults to validating:
 *   - src/manifest.json (the OpenBuild shell manifest)
 *   - lib/Resources/wizard/default-manifest.json (the wizard seed)
 *
 * Pass alternate paths as CLI args. The wizard seed carries the literal
 * `{registerSlug}` placeholder string in `pages[].config.register`, so
 * it's validated through a wrapper that swaps the token to a syntactically
 * valid slug before validation runs. We're checking the schema-shape, not
 * the placeholder substitution itself.
 *
 * Exits 0 when every input passes; 1 otherwise.
 */

const fs = require('node:fs')
const path = require('node:path')
const Ajv = require('ajv/dist/2020').default

const SCHEMA_DIR = path.resolve(
	__dirname,
	'../node_modules/@conduction/nextcloud-vue/src/schemas',
)
const SCHEMA_V1_PATH = path.join(SCHEMA_DIR, 'app-manifest.schema.json')
const SCHEMA_V2_PATH = path.join(SCHEMA_DIR, 'app-manifest-v2.schema.json')

/**
 * Pick the schema version a manifest declares via its `$schema` URL. A manifest
 * whose `$schema` references `app-manifest-v2` is validated against the v2
 * schema; everything else (including manifests with no `$schema`) defaults to
 * v1. This keeps the v2 shell manifest and the v1 wizard seed each validated
 * against the contract they actually target.
 *
 * @param {object} manifest The parsed manifest payload.
 * @returns {'v1'|'v2'} The schema version to validate against.
 */
function schemaVersionFor(manifest) {
	const ref = manifest && typeof manifest.$schema === 'string' ? manifest.$schema : ''
	return ref.includes('app-manifest-v2') ? 'v2' : 'v1'
}

const DEFAULT_TARGETS = [
	'src/manifest.json',
	'lib/Resources/wizard/default-manifest.json',
]

function loadJson(filePath) {
	const raw = fs.readFileSync(filePath, 'utf-8')
	return JSON.parse(raw)
}

/**
 * Replace token placeholders in a manifest with syntactically valid values
 * so the schema validator can run against the structural shape.
 *
 * @param {object} manifest The manifest payload.
 * @returns {object} The manifest with tokens substituted.
 */
function substituteTokens(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) return manifest
	return {
		...manifest,
		pages: manifest.pages.map((page) => {
			if (!page || typeof page !== 'object' || !page.config) return page
			const config = { ...page.config }
			if (config.register === '{registerSlug}') {
				config.register = 'openbuild-validator-placeholder'
			}
			return { ...page, config }
		}),
	}
}

function main() {
	const args = process.argv.slice(2)
	const targets = args.length > 0 ? args : DEFAULT_TARGETS
	const repoRoot = path.resolve(__dirname, '..')

	const ajv = new Ajv({ allErrors: true, strict: false })
	const validators = {
		v1: ajv.compile(loadJson(SCHEMA_V1_PATH)),
		v2: ajv.compile(loadJson(SCHEMA_V2_PATH)),
	}

	let allPassed = true
	for (const target of targets) {
		const abs = path.isAbsolute(target) ? target : path.join(repoRoot, target)
		if (!fs.existsSync(abs)) {
			console.error(`SKIP ${target} (not found)`)
			continue
		}

		let manifest
		try {
			manifest = loadJson(abs)
		} catch (err) {
			console.error(`FAIL ${target} — JSON parse error: ${err.message}`)
			allPassed = false
			continue
		}

		const candidate = substituteTokens(manifest)
		const version = schemaVersionFor(manifest)
		const validate = validators[version]
		const valid = validate(candidate)
		if (valid) {
			console.log(`PASS ${target} (${version})`)
			continue
		}

		allPassed = false
		console.error(`FAIL ${target} (${version})`)
		for (const err of validate.errors || []) {
			console.error(`  ${err.instancePath || '(root)'} ${err.message}`)
		}
	}

	process.exit(allPassed ? 0 : 1)
}

main()
