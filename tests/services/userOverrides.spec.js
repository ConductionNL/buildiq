/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the per-user app override endpoints and the delta state
 * read from their answer.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => `/index.php${path.replace(/\{(\w+)\}/g, (_, k) => params[k])}`,
}))

import { userDeltaState, userOverridesUrl, userOverrideUrl } from '../../src/services/userOverrides.js'

describe('userOverrides URLs', () => {
	it('address the app\'s own user override and its user deltas', () => {
		expect(userOverrideUrl('shop')).toBe('/index.php/apps/buildiq/api/app-overrides/shop/user')
		expect(userOverridesUrl('shop')).toBe('/index.php/apps/buildiq/api/app-overrides/shop/user-deltas')
	})
})

describe('userDeltaState', () => {
	it('reads allowed, exists and the version uuid', () => {
		expect(userDeltaState({ allowed: 1, exists: 'yes', versionUuid: 'v-1', extra: true })).toEqual({
			allowed: true,
			exists: true,
			versionUuid: 'v-1',
		})
	})

	it.each([[null], [undefined], [{}], [{ allowed: 0, exists: '', versionUuid: '' }]])('answers nothing allowed or stored for %j', (data) => {
		expect(userDeltaState(data)).toEqual({ allowed: false, exists: false, versionUuid: null })
	})
})
