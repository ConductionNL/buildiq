/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec — manifest load → serialise round-trip (openbuilt#9 task 7.2).
 *
 * A canonical OpenBuilt manifest must survive a JSON.parse → JSON.stringify
 * cycle without losing information. The page editor depends on this when it
 * round-trips manifest edits through its Raw-JSON tab; the wizard seed
 * depends on it because every new app is born from `default-manifest.json`.
 *
 * Strict bytewise equality is too brittle (key ordering varies between
 * authors), so the test asserts:
 *   1. parse(stringify(parse(raw))) deep-equals parse(raw)
 *   2. the round-tripped manifest also passes the ADR-024 schema validator
 *      (so we never silently introduce shape drift through editor edits)
 */

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import Ajv from 'ajv/dist/2020.js'

const REPO_ROOT = resolve(__dirname, '../..')

const TARGETS = [
	{
		label: 'OpenBuilt shell manifest (src/manifest.json)',
		path: 'src/manifest.json',
		substituteTokens: false,
	},
	{
		label: 'wizard seed (lib/Resources/wizard/default-manifest.json)',
		path: 'lib/Resources/wizard/default-manifest.json',
		substituteTokens: true,
	},
	// Note: lib/Resources/template/src/manifest.json is intentionally NOT
	// in this list. It's a Mustache scaffold (`{{appId}}` etc.) that gets
	// rendered at export time — it doesn't validate against ADR-024 until
	// the template engine substitutes its tokens.
]

function loadManifest(rel) {
	const raw = readFileSync(resolve(REPO_ROOT, rel), 'utf-8')
	return { raw, parsed: JSON.parse(raw) }
}

function substituteRegisterTokens(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) return manifest
	return {
		...manifest,
		pages: manifest.pages.map((page) => {
			if (!page || !page.config) return page
			const config = { ...page.config }
			if (config.register === '{registerSlug}') {
				config.register = 'openbuilt-validator-placeholder'
			}
			return { ...page, config }
		}),
	}
}

const SCHEMA_PATH = 'node_modules/@conduction/nextcloud-vue/src/schemas/app-manifest.schema.json'
const schema = JSON.parse(readFileSync(resolve(REPO_ROOT, SCHEMA_PATH), 'utf-8'))
const ajv = new Ajv.default({ allErrors: true, strict: false })
const validate = ajv.compile(schema)

describe('manifest round-trip', () => {
	for (const target of TARGETS) {
		describe(target.label, () => {
			it('parse → stringify → parse deep-equals the original parse', () => {
				const { parsed } = loadManifest(target.path)
				const re = JSON.parse(JSON.stringify(parsed))
				expect(re).toEqual(parsed)
			})

			it('round-tripped manifest still validates against the ADR-024 schema', () => {
				const { parsed } = loadManifest(target.path)
				const re = JSON.parse(JSON.stringify(parsed))
				const candidate = target.substituteTokens ? substituteRegisterTokens(re) : re
				const ok = validate(candidate)
				if (!ok) {
					// Surface the first 5 errors — strict equality already gives
					// us the diff in the previous case if this one trips.
					const summary = (validate.errors || [])
						.slice(0, 5)
						.map((e) => `${e.instancePath || '(root)'} ${e.message}`)
						.join('; ')
					throw new Error(`round-tripped manifest failed schema: ${summary}`)
				}
				expect(ok).toBe(true)
			})

			it('repeated round-trips converge (idempotent)', () => {
				const { parsed } = loadManifest(target.path)
				const once = JSON.parse(JSON.stringify(parsed))
				const twice = JSON.parse(JSON.stringify(once))
				expect(twice).toEqual(once)
			})
		})
	}

	describe('synthetic manifest', () => {
		it('survives a round-trip with `additionalProperties: false` shape preserved', () => {
			const manifest = {
				version: '1.0.0',
				menu: [
					{ id: 'h', label: 'Home', icon: 'icon-home', route: 'Home', order: 10 },
				],
				pages: [
					{ id: 'Home', route: '/', type: 'dashboard', title: 'Home', config: { widgets: [], layout: [] } },
				],
			}
			const re = JSON.parse(JSON.stringify(manifest))
			expect(re).toEqual(manifest)
			expect(validate(re)).toBe(true)
		})

		it('preserves nested config blocks without flattening or coercion', () => {
			const manifest = {
				version: '1.0.0',
				menu: [
					{ id: 'm', label: 'Msgs', icon: 'icon-comment', route: 'Msgs', order: 10 },
				],
				pages: [
					{
						id: 'Msgs',
						route: '/messages',
						type: 'index',
						title: 'Messages',
						config: {
							register: 'openbuilt',
							schema: 'hello-message',
							columns: ['title', 'body'],
							sort: { field: 'created', dir: 'desc' },
						},
					},
				],
			}
			const re = JSON.parse(JSON.stringify(manifest))
			expect(re.pages[0].config.sort).toEqual({ field: 'created', dir: 'desc' })
			expect(re.pages[0].config.columns).toEqual(['title', 'body'])
		})
	})
})
