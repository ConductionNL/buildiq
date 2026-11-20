/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: how version and application records are identified and picked —
 * `versionUuidOf`, the built-app host's `applicationUuid`, the fallbacks of
 * `defaultEditableVersion`, and the inputs `withVersion` leaves alone.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { versionUuidOf } = await import('../../src/composables/usePromoteDialog.js')
const builtAppHost = (await import('../../src/mixins/builtAppHost.js')).default
const { defaultEditableVersion } = await import(
	'../../src/composables/useApplicationVersion.js'
)
const { withVersion } = await import('../../src/services/versionQuery.js')

describe('version identity', () => {
	it('reads a version uuid from the top level first, then @self', () => {
		expect(versionUuidOf(null)).toBe('')
		expect(versionUuidOf({})).toBe('')
		expect(versionUuidOf({ uuid: 'u', id: 'i', '@self': { id: 's' } })).toBe('u')
		expect(versionUuidOf({ id: 'i', '@self': { id: 's' } })).toBe('i')
		expect(versionUuidOf({ '@self': { id: 's', uuid: 'su' } })).toBe('s')
		expect(versionUuidOf({ '@self': { uuid: 'su' } })).toBe('su')
		expect(versionUuidOf({ '@self': null, uuid: '' })).toBe('')
	})

	it('reads the application uuid from @self first, then the top level', () => {
		const read = (application) =>
			builtAppHost.computed.applicationUuid.call({ application })
		expect(read(null)).toBe('')
		expect(read({})).toBe('')
		expect(read({ '@self': { id: 's' }, uuid: 'u' })).toBe('s')
		expect(read({ '@self': {}, uuid: 'u' })).toBe('u')
		expect(read({ '@self': null, uuid: 'u' })).toBe('u')
	})

	it('falls back to production, then the first version, in a cycle', () => {
		const a = { uuid: 'a', promotesTo: 'b' }
		const b = { uuid: 'b', promotesTo: 'a' }
		expect(defaultEditableVersion([a, b], 'b')).toBe(b)
		expect(defaultEditableVersion([a, b], 'gone')).toBe(a)
		expect(defaultEditableVersion([a, b], null)).toBe(a)
		expect(defaultEditableVersion([a, b], '')).toBe(a)
	})

	it('leaves a location without a version, or an unusable one, alone', () => {
		expect(withVersion('/x', '')).toBe('/x')
		expect(withVersion(null, 'v')).toBeNull()
		expect(withVersion(undefined, 'v')).toBeUndefined()
		expect(withVersion(5, 'v')).toBe(5)
		expect(withVersion({ path: '/x' }, 'v')).toEqual({ path: '/x', query: { _version: 'v' } })
	})
})
