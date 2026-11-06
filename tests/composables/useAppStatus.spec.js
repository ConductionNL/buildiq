/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useAppStatus`.
 *
 * The page designer soft-checks the theme app on every load. With the theme
 * app disabled, the webroots map lists neither `thematiq` nor `nldesign`, and
 * the composable used to probe `/apps/thematiq/api` anyway: a request to a
 * route that cannot exist, answered with a 404 on every load.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import {
	clearAppStatusCache,
	useAppStatus,
} from '../../src/composables/useAppStatus.js'

describe('useAppStatus', () => {
	let client

	beforeEach(() => {
		clearAppStatusCache()
		client = { get: vi.fn() }
	})

	afterEach(() => {
		delete globalThis.OC
	})

	it('reports an app absent from a populated webroots map without probing', async () => {
		globalThis.OC = {
			appswebroots: {
				buildiq: '/custom_apps/buildiq',
				dossiq: '/custom_apps/dossiq',
			},
		}
		const status = useAppStatus('thematiq', { client })

		const result = await status.check()

		expect(result).toBe(false)
		expect(status.available.value).toBe(false)
		expect(status.checked.value).toBe(true)
		expect(client.get).not.toHaveBeenCalled()
	})

	it('reports an app listed under its old id as available without probing', async () => {
		globalThis.OC = { appswebroots: { nldesign: '/custom_apps/nldesign' } }
		const status = useAppStatus('thematiq', { client })

		expect(await status.check()).toBe(true)
		expect(client.get).not.toHaveBeenCalled()
	})

	it('still probes when there is no webroots map to read', async () => {
		client.get.mockResolvedValue({ data: {} })
		const status = useAppStatus('thematiq', { client })

		expect(await status.check()).toBe(true)
		expect(client.get).toHaveBeenCalledWith('/index.php/apps/thematiq/api')
	})

	it('reads a 404 from the probe as absent', async () => {
		client.get.mockRejectedValue({ response: { status: 404 } })
		const status = useAppStatus('thematiq', { client })

		expect(await status.check()).toBe(false)
	})
})
