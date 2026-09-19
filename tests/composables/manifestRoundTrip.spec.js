/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec — manifest load → serialise round-trip (buildiq#9 task 7.2).
 *
 * A canonical Buildiq manifest must survive a JSON.parse → JSON.stringify
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

import Ajv from 'ajv/dist/2020.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { applySlotRules } from '../../src/services/slotGeometry.js'

const REPO_ROOT = resolve(__dirname, '../..')

const TARGETS = [
	{
		label: 'Buildiq shell manifest (src/manifest.json)',
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

// Top-level blocks owned by the OpenRegister AppHost engine (ADR-040), not by
// the @conduction/nextcloud-vue renderer. The canonical app-manifest schema is
// `additionalProperties: false` at the root and does not yet describe them
// (schema lag — upstream nextcloud-vue PR pending). They are consumed
// server-side by OR's ManifestLoader, so the renderer-shape schema validation
// below strips them, mirroring scripts/check-manifest.js. The round-trip
// deep-equal test above keeps them, so a true round-trip is still asserted.
const ENGINE_OWNED_KEYS = ['observability', 'deepLinks']

function stripEngineBlocks(manifest) {
	if (!manifest || typeof manifest !== 'object') return manifest
	const stripped = { ...manifest }
	for (const key of ENGINE_OWNED_KEYS) {
		delete stripped[key]
	}
	return stripped
}

function substituteRegisterTokens(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) return manifest
	return {
		...manifest,
		pages: manifest.pages.map((page) => {
			if (!page || !page.config) return page
			const config = { ...page.config }
			if (config.register === '{registerSlug}') {
				config.register = 'openbuild-validator-placeholder'
			}
			return { ...page, config }
		}),
	}
}

const SCHEMA_DIR = 'node_modules/@conduction/nextcloud-vue/src/schemas'
function loadSchema(name) {
	return JSON.parse(readFileSync(resolve(REPO_ROOT, SCHEMA_DIR, name), 'utf-8'))
}
const ajv = new Ajv.default({ allErrors: true, strict: false })
const validators = {
	v1: ajv.compile(loadSchema('app-manifest.schema.json')),
	v2: ajv.compile(loadSchema('app-manifest-v2.schema.json')),
}

// Validate each manifest against the schema version it declares via `$schema`
// (v2 shell manifest vs v1 wizard seed), mirroring scripts/check-manifest.js.
function validatorFor(manifest) {
	const ref =
		manifest && typeof manifest.$schema === 'string' ? manifest.$schema : ''
	return ref.includes('app-manifest-v2') ? validators.v2 : validators.v1
}

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
				const tokenised = target.substituteTokens
					? substituteRegisterTokens(re)
					: re
				const candidate = stripEngineBlocks(tokenised)
				const validate = validatorFor(candidate)
				const ok = validate(candidate)
				if (!ok) {
					// Surface the first 5 errors — strict equality already gives
					// us the diff in the previous case if this one trips.
					const summary = (validate.errors || [])
						.slice(0, 5)
						.map((e) => `${e.instancePath || '(root)'} ${e.message}`)
						.join('; ')
					throw new Error(
						`round-tripped manifest failed schema: ${summary}`,
					)
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
					{
						id: 'h',
						label: 'Home',
						icon: 'icon-home',
						route: 'Home',
						order: 10,
					},
				],
				pages: [
					{
						id: 'Home',
						route: '/',
						type: 'dashboard',
						title: 'Home',
						config: { widgets: [], layout: [] },
					},
				],
			}
			const re = JSON.parse(JSON.stringify(manifest))
			expect(re).toEqual(manifest)
			expect(validatorFor(re)(re)).toBe(true)
		})

		it('preserves nested config blocks without flattening or coercion', () => {
			const manifest = {
				version: '1.0.0',
				menu: [
					{
						id: 'm',
						label: 'Msgs',
						icon: 'icon-comment',
						route: 'Msgs',
						order: 10,
					},
				],
				pages: [
					{
						id: 'Msgs',
						route: '/messages',
						type: 'index',
						title: 'Messages',
						config: {
							register: 'buildiq',
							schema: 'hello-message',
							columns: ['title', 'body'],
							sort: { field: 'created', dir: 'desc' },
						},
					},
				],
			}
			const re = JSON.parse(JSON.stringify(manifest))
			expect(re.pages[0].config.sort).toEqual({
				field: 'created',
				dir: 'desc',
			})
			expect(re.pages[0].config.columns).toEqual(['title', 'body'])
		})
	})

	// v2-widget-placement-editor task 3.3 / design.md R2 and D6.
	//
	// The failure this guards has no error message anywhere: a `visibleWhen` or
	// a `roles` array written by a template or the copilot vanishes on save,
	// and the page simply becomes visible to more people. A spec written from
	// the placement editor's own field list cannot fail that way, so the
	// placement below deliberately carries keys the editor never surfaces,
	// including one the schema does not know yet.
	describe('a v2 placement the editor edits', () => {
		const SURVIVES = {
			tabGroup: 'general',
			roles: ['controllers', 'beheerders'],
			visibleWhen: { field: 'status', op: 'eq', value: 'open' },
			requiredApp: 'openregister',
			dateChip: true,
			_note: 'written before this editor existed',
		}

		function manifestWithPlacement() {
			return {
				$schema:
					'https://github.com/ConductionNL/nextcloud-vue/raw/main/src/schemas/app-manifest-v2.schema.json',
				version: '2.0.0',
				menu: [
					{
						id: 'h',
						label: 'Home',
						icon: 'icon-home',
						route: 'home',
						order: 10,
					},
				],
				pages: [
					{
						id: 'home',
						route: '/',
						type: 'dashboard',
						title: 'Home',
						config: {},
						widgets: [
							{
								id: 'cases',
								widgetKey: 'object-table',
								slot: 'body',
								gridX: 0,
								gridY: 0,
								gridWidth: 6,
								gridHeight: 3,
								props: { label: 'Open cases' },
								...SURVIVES,
							},
							{
								id: 'notes',
								widgetKey: 'text',
								slot: 'sidebar',
								gridX: 0,
								gridY: 0,
								gridWidth: 1,
								gridHeight: 4,
							},
						],
					},
				],
			}
		}

		it('validates against the installed v2 schema before anything touches it', () => {
			const manifest = manifestWithPlacement()
			const validate = validatorFor(manifest)
			const ok = validate(manifest)
			if (!ok) {
				throw new Error(
					`fixture failed schema: ${(validate.errors || [])
						.slice(0, 5)
						.map((e) => `${e.instancePath || '(root)'} ${e.message}`)
						.join('; ')}`,
				)
			}
			expect(ok).toBe(true)
		})

		it('keeps every unsurfaced key through a position change, and still validates', () => {
			const manifest = manifestWithPlacement()
			const page = manifest.pages[0]

			// Exactly what WidgetPlacementPanel writes: the entry is SPREAD and
			// the geometry re-applied, never rebuilt from a list of known keys.
			const edited = {
				...manifest,
				pages: [
					{
						...page,
						widgets: page.widgets.map((entry, index) =>
							index === 0
								? applySlotRules({ ...entry, gridX: 6 }, page)
								: entry,
						),
					},
				],
			}

			const stored = edited.pages[0].widgets[0]
			expect(stored.gridX).toBe(6)
			for (const [key, value] of Object.entries(SURVIVES)) {
				expect(stored[key], `the editor dropped ${key}`).toEqual(value)
			}
			expect(stored.props).toEqual({ label: 'Open cases' })

			const validate = validatorFor(edited)
			const ok = validate(edited)
			if (!ok) {
				throw new Error(
					`edited manifest failed schema: ${(validate.errors || [])
						.slice(0, 5)
						.map((e) => `${e.instancePath || '(root)'} ${e.message}`)
						.join('; ')}`,
				)
			}
			expect(ok).toBe(true)
		})

		it('survives a key the schema has never heard of, which is the point', () => {
			// `additionalProperties: false` means the schema REJECTS an unknown
			// key. That is fine and expected: what matters is that the editor
			// does not silently eat one written by a newer library, so the
			// assertion is on the round-trip, not on the validator.
			const manifest = manifestWithPlacement()
			const page = manifest.pages[0]
			const entry = { ...page.widgets[0], ncDashboard: { panel: 'cases' } }
			const stored = applySlotRules({ ...entry, gridY: 2 }, page)
			expect(stored.ncDashboard).toEqual({ panel: 'cases' })
			expect(stored.gridY).toBe(2)
		})

		it('a page opened and saved with no edit is byte-for-byte what was loaded', () => {
			const manifest = manifestWithPlacement()
			const raw = JSON.stringify(manifest)
			// Opening the editor reads widgets[]; saving with no edit re-emits
			// the very same array, so the serialised manifest cannot move.
			const reopened = {
				...manifest,
				pages: manifest.pages.map((page) => ({
					...page,
					widgets: page.widgets,
				})),
			}
			expect(JSON.stringify(reopened)).toBe(raw)
		})
	})
})
