/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `schemas.js` store helpers.
 *
 * Covers spec `openbuilt-version-routing` REQ-OBVR-007 and tasks.md §6.5:
 *  - `registerSlugForApp(appSlug)` → `openbuilt-{appSlug}` (legacy, no versionSlug)
 *  - `registerSlugForApp(appSlug, versionSlug)` → `openbuilt-{appSlug}-{versionSlug}` (per-version register)
 *  - `useSchemasStore` registers to the correct per-version register namespace
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'

describe('registerSlugForApp — register slug construction (REQ-OBVR-007)', () => {
	let registerSlugForApp

	beforeEach(async () => {
		vi.resetModules()

		// Mock createObjectStore so we can import the module without Pinia setup
		vi.doMock('@conduction/nextcloud-vue', () => ({
			createObjectStore: vi.fn(() => vi.fn(() => ({
				objectTypeRegistry: {},
				registerObjectType: vi.fn(),
			}))),
		}))

		const mod = await import('../../src/store/schemas.js')
		registerSlugForApp = mod.registerSlugForApp
	})

	it('returns openbuilt-{appSlug} when no versionSlug is provided (legacy)', () => {
		expect(registerSlugForApp('hello-world')).toBe('openbuilt-hello-world')
	})

	it('returns openbuilt-{appSlug} when versionSlug is undefined', () => {
		expect(registerSlugForApp('hello-world', undefined)).toBe('openbuilt-hello-world')
	})

	it('returns openbuilt-{appSlug} when versionSlug is empty string', () => {
		expect(registerSlugForApp('hello-world', '')).toBe('openbuilt-hello-world')
	})

	it('returns openbuilt-{appSlug}-{versionSlug} when versionSlug is provided (spec C)', () => {
		expect(registerSlugForApp('hello-world', 'staging')).toBe('openbuilt-hello-world-staging')
	})

	it('handles multi-segment slugs correctly', () => {
		expect(registerSlugForApp('my-complex-app', 'v2-beta')).toBe('openbuilt-my-complex-app-v2-beta')
	})

	it('handles production version slug', () => {
		expect(registerSlugForApp('my-app', 'production')).toBe('openbuilt-my-app-production')
	})
})

describe('useSchemasStore — re-registers when register changes', () => {
	it('registers to the versioned register when versionSlug is provided', async () => {
		vi.resetModules()

		const registerObjectType = vi.fn()
		const fakeStore = {
			objectTypeRegistry: {},
			registerObjectType,
		}

		vi.doMock('@conduction/nextcloud-vue', () => ({
			createObjectStore: vi.fn(() => vi.fn(() => fakeStore)),
		}))

		const { useSchemasStore } = await import('../../src/store/schemas.js')

		useSchemasStore('hello-world', 'staging')

		expect(registerObjectType).toHaveBeenCalledWith(
			'schema',
			'schemas',
			'openbuilt-hello-world-staging',
		)
	})

	it('registers to the legacy register when versionSlug is absent', async () => {
		vi.resetModules()

		const registerObjectType = vi.fn()
		const fakeStore = {
			objectTypeRegistry: {},
			registerObjectType,
		}

		vi.doMock('@conduction/nextcloud-vue', () => ({
			createObjectStore: vi.fn(() => vi.fn(() => fakeStore)),
		}))

		const { useSchemasStore } = await import('../../src/store/schemas.js')

		useSchemasStore('hello-world')

		expect(registerObjectType).toHaveBeenCalledWith(
			'schema',
			'schemas',
			'openbuilt-hello-world',
		)
	})

	it('re-registers when called again with a different register', async () => {
		vi.resetModules()

		const registerObjectType = vi.fn()
		const fakeStore = {
			objectTypeRegistry: {},
			registerObjectType,
		}

		vi.doMock('@conduction/nextcloud-vue', () => ({
			createObjectStore: vi.fn(() => vi.fn(() => fakeStore)),
		}))

		const { useSchemasStore } = await import('../../src/store/schemas.js')

		// First call — registers to staging
		useSchemasStore('hello-world', 'staging')
		expect(registerObjectType).toHaveBeenCalledTimes(1)
		expect(registerObjectType).toHaveBeenLastCalledWith('schema', 'schemas', 'openbuilt-hello-world-staging')

		// Simulate version change — objectTypeRegistry now has the old register recorded
		fakeStore.objectTypeRegistry.schema = { register: 'openbuilt-hello-world-staging' }

		// Second call with different version — should re-register
		useSchemasStore('hello-world', 'production')
		expect(registerObjectType).toHaveBeenCalledTimes(2)
		expect(registerObjectType).toHaveBeenLastCalledWith('schema', 'schemas', 'openbuilt-hello-world-production')
	})
})
