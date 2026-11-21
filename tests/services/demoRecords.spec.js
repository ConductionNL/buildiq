/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for demoRecords: the requests and their outcome messages.
 *
 * Spec: capability-requirements-backfill (REQ-BQCV-030).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), delete: vi.fn() } }))

import axios from '@nextcloud/axios'
import {
	demoRecordCount,
	fillDemoRecords,
	fillMessage,
	removeDemoRecords,
	removeMessage,
} from '../../src/services/demoRecords.js'

describe('demoRecords', () => {
	beforeEach(() => {
		axios.post.mockReset()
		axios.delete.mockReset()
	})

	it('fills and removes through the app\'s demo records endpoint', async () => {
		axios.post.mockResolvedValueOnce({ data: { created: 3 } })
		axios.delete.mockResolvedValueOnce({ data: { removed: 3 } })

		expect(await fillDemoRecords('app-1')).toEqual({ created: 3 })
		expect(await removeDemoRecords('app-1')).toEqual({ removed: 3 })
		expect(axios.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/app-1/demo-records')
		expect(axios.delete).toHaveBeenCalledWith('/apps/buildiq/api/applications/app-1/demo-records')
	})

	it('answers an empty object for an empty response', async () => {
		axios.post.mockResolvedValueOnce({})
		expect(await fillDemoRecords('app-1')).toEqual({})
	})

	it('counts the demo records an app lists', () => {
		expect(demoRecordCount({ demoRecords: [{ uuid: 'a' }, { uuid: 'b' }] })).toBe(2)
		expect(demoRecordCount({ demoRecords: 'x' })).toBe(0)
		expect(demoRecordCount(null)).toBe(0)
	})

	// The test harness's t() and n() do not interpolate, so the messages show their keys.
	it('says how many were added and which schemas got none', () => {
		expect(fillMessage({ created: 3, skipped: [] })).toBe('Added %n demo records.')
		expect(fillMessage({ created: 1, skipped: ['case', 'task'] })).toBe('Added %n demo record. No demo records could be made for: {schemas}.')
		expect(fillMessage(null)).toBe('Added %n demo records.')
	})

	it('says how many were removed and how many stayed', () => {
		expect(removeMessage({ removed: 3, failed: 0 })).toBe('Removed %n demo records.')
		expect(removeMessage({ removed: 1, failed: 2 })).toBe('Removed %n demo record. %n could not be removed.')
	})
})
