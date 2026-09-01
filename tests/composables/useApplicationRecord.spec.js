/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/composables/useApplicationRecord.js` (#49).
 *
 * THE BUG. The app-detail page resolves its Application record in two places —
 * ApplicationDetailHeader and ApplicationDetailDashboard — because CnDetailPage's
 * slots forward only presentational props, not the resolved record. Each of those
 * components is driven by three independent triggers (mounted, the objectId
 * watcher, the object watcher) and none knew about the others, so a single load
 * of /applications/hydra-console issued TEN identical GETs for one record.
 *
 * The measurable contract this locks in: N concurrent callers for the same uuid
 * produce exactly ONE network request, and every caller still receives the
 * record.
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	__resetApplicationRecordCache,
	fetchApplicationRecord,
} from '../../src/composables/useApplicationRecord.js'

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

describe('useApplicationRecord — concurrent fetch coalescing (#49)', () => {
	beforeEach(() => {
		__resetApplicationRecordCache()
		axios.get.mockReset()
	})

	it('collapses ten concurrent callers into one request', async () => {
		let resolveRequest
		axios.get.mockReturnValue(
			new Promise((resolve) => {
				resolveRequest = resolve
			}),
		)

		// Ten callers, exactly as the page produced them before the fix.
		const calls = Array.from({ length: 10 }, () =>
			fetchApplicationRecord('hydra-console'),
		)
		resolveRequest({ data: { name: 'Hydra Console', slug: 'hydra-console' } })
		const results = await Promise.all(calls)

		expect(axios.get).toHaveBeenCalledTimes(1)
		// Every caller still gets the record — coalescing must not starve anyone.
		for (const r of results) expect(r.name).toBe('Hydra Console')
	})

	it('does not coalesce across different applications', async () => {
		axios.get.mockResolvedValue({ data: { name: 'x' } })
		await Promise.all([
			fetchApplicationRecord('app-one'),
			fetchApplicationRecord('app-two'),
		])
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('is not a cache — a later call re-fetches once the first settled', async () => {
		// Guards against "fixing" the stampede by serving a stale record forever.
		axios.get.mockResolvedValue({ data: { name: 'first' } })
		await fetchApplicationRecord('hydra-console')
		axios.get.mockResolvedValue({ data: { name: 'second' } })
		const again = await fetchApplicationRecord('hydra-console')

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(again.name).toBe('second')
	})

	it('does not pin a rejected promise for later callers', async () => {
		// A failed request must not poison the entry — the next caller has to be
		// able to try again rather than re-awaiting the same rejection.
		axios.get.mockRejectedValueOnce(new Error('boom'))
		await expect(fetchApplicationRecord('hydra-console')).rejects.toThrow('boom')

		axios.get.mockResolvedValue({ data: { name: 'recovered' } })
		const after = await fetchApplicationRecord('hydra-console')
		expect(after.name).toBe('recovered')
	})

	it('returns null for an empty payload and never calls out for an empty uuid', async () => {
		axios.get.mockResolvedValue({ data: null })
		expect(await fetchApplicationRecord('hydra-console')).toBeNull()

		axios.get.mockClear()
		expect(await fetchApplicationRecord('')).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()
	})
})
