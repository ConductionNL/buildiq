/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the `useApplicationInsights` composable.
 *
 * Spec: openbuilt-app-detail-overview / application-insights
 * (REQ-OBAI-001, REQ-OBAI-002 — surface behaviour from the frontend).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

import axios from '@nextcloud/axios'
import { useApplicationInsights } from '../../src/composables/useApplicationInsights.js'

const wait = (ms) => new Promise((r) => setTimeout(r, ms))

describe('useApplicationInsights', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('fetches on mount and exposes the KPI payload', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				kpis: { activeUsers: 5, objectCount: 10, filesCount: 3, auditEventCount: 50 },
				activity: [{ timestamp: '2026-05-08T00:00:00Z', eventCount: 12 }],
			},
		})
		const { kpis, activity, loading } = useApplicationInsights('app-uuid', 'version-uuid', '7d')
		await wait(0)
		await wait(0)
		expect(loading.value).toBe(false)
		expect(kpis.value.activeUsers).toBe(5)
		expect(activity.value.length).toBe(1)
	})

	it('does not fetch when appUuid or versionUuid is empty', async () => {
		useApplicationInsights('', '', '7d')
		await wait(0)
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('sets versionNoLongerAccessible on 404', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 404 } })
		const { versionNoLongerAccessible } = useApplicationInsights('app-uuid', 'version-uuid', '7d')
		await wait(0)
		await wait(0)
		expect(versionNoLongerAccessible.value).toBe(true)
	})

	it('debounces back-to-back changes to (versionUuid, window)', async () => {
		axios.get.mockResolvedValue({ data: { kpis: {}, activity: [] } })
		const appUuid = ref('app-uuid')
		const versionUuid = ref('v1')
		const win = ref('7d')

		useApplicationInsights(appUuid, versionUuid, win)
		await wait(0)
		const initialCalls = axios.get.mock.calls.length

		// Rapid back-to-back changes — debounce should collapse to one extra call.
		versionUuid.value = 'v2'
		await nextTick()
		win.value = '30d'
		await nextTick()
		win.value = '90d'
		await nextTick()

		// Before the debounce window expires, no new HTTP call should have fired.
		expect(axios.get.mock.calls.length).toBe(initialCalls)

		// After the debounce window expires, one consolidated call.
		await wait(250)
		expect(axios.get.mock.calls.length).toBeGreaterThan(initialCalls)
	})
})
