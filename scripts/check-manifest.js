#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * check-manifest — validate openbuilt manifests against the canonical
 * @conduction/nextcloud-vue ADR-024 schema.
 *
 * Implements openbuilt#10 task 4.3 — "Run npm run check:manifest on the
 * seeded hello-world manifest blob in tests; passes against the canonical
 * schema pinned in package.json."
 *
 * Defaults to validating:
 *   - src/manifest.json (the OpenBuilt shell manifest)
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

const SCHEMA_PATH = path.resolve(
	__dirname,
	'../node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json',
)

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
				config.register = 'openbuilt-validator-placeholder'
			}
			return { ...page, config }
		}),
	}
}

function main() {
	const args = process.argv.slice(2)
	const targets = args.length > 0 ? args : DEFAULT_TARGETS
	const repoRoot = path.resolve(__dirname, '..')

	const schema = loadJson(SCHEMA_PATH)
	const ajv = new Ajv({ allErrors: true, strict: false })
	const validate = ajv.compile(schema)

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
		const valid = validate(candidate)
		if (valid) {
			console.log(`PASS ${target}`)
			continue
		}

		allPassed = false
		console.error(`FAIL ${target}`)
		for (const err of validate.errors || []) {
			console.error(`  ${err.instancePath || '(root)'} ${err.message}`)
		}
	}

	process.exit(allPassed ? 0 : 1)
}

main()
