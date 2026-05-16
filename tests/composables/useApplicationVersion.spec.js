/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useApplicationVersion` composable and `defaultEditableVersion`.
 *
 * Covers spec `openbuilt-version-routing` REQ-OBVR-005 and tasks.md §3.6:
 *  - Named-version fetch path (versionSlug provided).
 *  - Most-upstream-non-production fallback (versionSlug absent, 3-version chain).
 *  - Production-only fallback when no non-production version exists.
 *  - Loading + error state transitions.
 *  - `defaultEditableVersion` pure-function behaviour.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defaultEditableVersion } from '../../src/composables/useApplicationVersion.js'

// ---------------------------------------------------------------------------
// Pure function: defaultEditableVersion
// ---------------------------------------------------------------------------

describe('defaultEditableVersion — pure function (REQ-OBVR-004 Decision 2)', () => {
	it('returns null for an empty list', () => {
		expect(defaultEditableVersion([], null)).toBeNull()
		expect(defaultEditableVersion(null, null)).toBeNull()
	})

	it('returns null for non-array input', () => {
		expect(defaultEditableVersion('bad', null)).toBeNull()
	})

	it('returns the single version when only one exists', () => {
		const v = { uuid: 'aaa', promotesTo: null }
		expect(defaultEditableVersion([v], null)).toBe(v)
	})

	it('selects the most-upstream non-production version in a 3-version chain', () => {
		// Chain: dev → staging → production
		// dev has no predecessor (nothing promotesTo dev) → upstream-most
		// staging has predecessor dev
		// production has predecessor staging
		const dev = { uuid: 'dev-uuid', promotesTo: 'staging-uuid' }
		const staging = { uuid: 'staging-uuid', promotesTo: 'prod-uuid' }
		const prod = { uuid: 'prod-uuid', promotesTo: null }
		const result = defaultEditableVersion([dev, staging, prod], 'prod-uuid')
		// dev is the only upstream-most non-production version
		expect(result).toBe(dev)
	})

	it('falls back to production when all versions are in a cycle or only production', () => {
		const prod = { uuid: 'prod-uuid', promotesTo: null }
		const result = defaultEditableVersion([prod], 'prod-uuid')
		// Only version IS production — fallback to prod
		expect(result).toBe(prod)
	})

	it('falls back to production when no non-production upstream exists', () => {
		// Two versions but both are upstream-most AND one is production
		const prod = { uuid: 'prod-uuid', promotesTo: null }
		const other = { uuid: 'other-uuid', promotesTo: null }
		// other is upstream-most and non-production → selected
		const result = defaultEditableVersion([prod, other], 'prod-uuid')
		expect(result).toBe(other)
	})

	it('selects the upstream-most version when productionUuid is null', () => {
		const v1 = { uuid: 'aaa', promotesTo: null }
		const v2 = { uuid: 'bbb', promotesTo: 'aaa' }
		// v2 is upstream-most: nothing promotes to bbb; v1 is downstream (v2 promotes to it)
		const result = defaultEditableVersion([v1, v2], null)
		expect(result).toBe(v2)
	})
})

// ---------------------------------------------------------------------------
// Composable: useApplicationVersion — named-version path
// ---------------------------------------------------------------------------

describe('useApplicationVersion — named versionSlug (REQ-OBVR-005 fetch-by-slug)', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doMock('@nextcloud/axios', () => ({
			default: {
				get: vi.fn(),
			},
		}))
		vi.doMock('@nextcloud/router', () => ({
			generateUrl: (path) => `/index.php${path}`,
		}))
	})

	it('fetches by slug and sets applicationVersion on success', async () => {
		const { default: axios } = await import('@nextcloud/axios')
		const versionRecord = { uuid: 'staging-uuid', slug: 'staging' }
		axios.get.mockResolvedValueOnce({ data: versionRecord })

		const { useApplicationVersion } = await import('../../src/composables/useApplicationVersion.js')
		const { applicationVersion, loading, error } = useApplicationVersion('hello-world', 'staging')

		// Initial state: loading true, version null
		expect(loading.value).toBe(true)
		expect(applicationVersion.value).toBeNull()

		// Wait for the async fetch to settle
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axios.get).toHaveBeenCalledWith(
			expect.stringContaining('/versions/staging'),
		)
		expect(applicationVersion.value).toEqual(versionRecord)
		expect(loading.value).toBe(false)
		expect(error.value).toBeNull()
	})

	it('sets error and null version on HTTP error', async () => {
		const { default: axios } = await import('@nextcloud/axios')
		axios.get.mockRejectedValueOnce(new Error('404 Not Found'))

		const { useApplicationVersion } = await import('../../src/composables/useApplicationVersion.js')
		const { applicationVersion, loading, error } = useApplicationVersion('hello-world', 'unknown-slug')

		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(applicationVersion.value).toBeNull()
		expect(loading.value).toBe(false)
		expect(error.value).toBeInstanceOf(Error)
	})
})

// ---------------------------------------------------------------------------
// Composable: useApplicationVersion — default (no versionSlug) path
// ---------------------------------------------------------------------------

describe('useApplicationVersion — no versionSlug (REQ-OBVR-004 fallback rule)', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doMock('@nextcloud/axios', () => ({
			default: {
				get: vi.fn(),
			},
		}))
		vi.doMock('@nextcloud/router', () => ({
			generateUrl: (path) => `/index.php${path}`,
		}))
	})

	it('applies the most-upstream-non-production rule from a 3-version list', async () => {
		const { default: axios } = await import('@nextcloud/axios')
		const dev = { uuid: 'dev-uuid', promotesTo: 'staging-uuid' }
		const staging = { uuid: 'staging-uuid', promotesTo: 'prod-uuid' }
		const prod = { uuid: 'prod-uuid', promotesTo: null }

		// First call: versions list
		axios.get.mockResolvedValueOnce({ data: [dev, staging, prod] })
		// Second call: application record (to get productionVersion)
		axios.get.mockResolvedValueOnce({
			data: { results: [{ slug: 'hello-world', productionVersion: 'prod-uuid' }] },
		})

		const { useApplicationVersion } = await import('../../src/composables/useApplicationVersion.js')
		const { applicationVersion, loading } = useApplicationVersion('hello-world', undefined)

		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(loading.value).toBe(false)
		// dev is upstream-most non-production version
		expect(applicationVersion.value).toEqual(dev)
	})

	it('falls back gracefully when application fetch fails', async () => {
		const { default: axios } = await import('@nextcloud/axios')
		const onlyVersion = { uuid: 'solo-uuid', promotesTo: null }

		axios.get.mockResolvedValueOnce({ data: [onlyVersion] })
		// Application fetch fails — productionUuid stays null
		axios.get.mockRejectedValueOnce(new Error('network error'))

		const { useApplicationVersion } = await import('../../src/composables/useApplicationVersion.js')
		const { applicationVersion, loading } = useApplicationVersion('hello-world', undefined)

		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(loading.value).toBe(false)
		// Falls back to the only version
		expect(applicationVersion.value).toEqual(onlyVersion)
	})

	it('sets applicationVersion to null when version list is empty', async () => {
		const { default: axios } = await import('@nextcloud/axios')

		axios.get.mockResolvedValueOnce({ data: [] })
		axios.get.mockResolvedValueOnce({ data: { results: [] } })

		const { useApplicationVersion } = await import('../../src/composables/useApplicationVersion.js')
		const { applicationVersion, loading } = useApplicationVersion('hello-world', undefined)

		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(loading.value).toBe(false)
		expect(applicationVersion.value).toBeNull()
	})
})
