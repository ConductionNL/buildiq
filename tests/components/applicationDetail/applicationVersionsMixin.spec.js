/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the record refresh of the application versions mixin: which id it
 * loads, latest-request-wins on success and on failure, and the error shape;
 * plus the production version uuid read off the Application record.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fetchApplicationRecord } from '../../../src/composables/useApplicationRecord.js'
import { applicationVersionsMixin } from '../../../src/mixins/applicationVersions.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: vi.fn(),
}))

const { refreshApplication } = applicationVersionsMixin.methods
const { productionVersionUuid } = applicationVersionsMixin.computed

/**
 * A component stand-in.
 *
 * @param {object} fields Its data.
 * @return {object}
 */
function vm(fields) {
	return { application: null, error: null, loadVersions: vi.fn(), ...fields }
}

describe('applicationVersionsMixin.refreshApplication', () => {
	beforeEach(() => {
		fetchApplicationRecord.mockReset()
	})

	it('loads by objectId first, then the route param, and not at all without either', async () => {
		fetchApplicationRecord.mockResolvedValue({ uuid: 'r' })
		const byProp = vm({ objectId: 'p', $route: { params: { objectId: 'q' } } })
		await refreshApplication.call(byProp)
		const byRoute = vm({ objectId: '', $route: { params: { objectId: 'q' } } })
		await refreshApplication.call(byRoute)
		await refreshApplication.call(vm({ $route: { params: {} } }))
		await refreshApplication.call(vm({ $route: {} }))
		await refreshApplication.call(vm({}))
		expect(fetchApplicationRecord.mock.calls).toEqual([['p'], ['q']])
		expect(byProp.application).toEqual({ uuid: 'r' })
		expect(byProp.loadVersions).toHaveBeenCalledTimes(1)
		expect(byProp._appReqSeq).toBe(1)
	})

	it('drops a superseded answer, success or failure', async () => {
		let resolveFirst
		let rejectSecond
		fetchApplicationRecord
			.mockReturnValueOnce(new Promise((resolve) => { resolveFirst = resolve }))
			.mockReturnValueOnce(new Promise((resolve, reject) => { rejectSecond = reject }))
			.mockResolvedValueOnce({ uuid: 'latest' })
		const c = vm({ objectId: 'a' })
		const first = refreshApplication.call(c)
		const second = refreshApplication.call(c)
		const third = refreshApplication.call(c)
		await third
		resolveFirst({ uuid: 'stale' })
		rejectSecond(new Error('stale'))
		await first
		await second
		expect(c.application).toEqual({ uuid: 'latest' })
		expect(c.error).toBeNull()
		expect(c.loadVersions).toHaveBeenCalledTimes(1)
		expect(c._appReqSeq).toBe(3)
	})

	it('keeps an Error and wraps anything else', async () => {
		const boom = new Error('boom')
		fetchApplicationRecord.mockRejectedValueOnce(boom).mockRejectedValueOnce('plain')
		const a = vm({ objectId: 'a', _appReqSeq: 4 })
		await refreshApplication.call(a)
		expect(a.error).toBe(boom)
		expect(a._appReqSeq).toBe(5)
		const b = vm({ objectId: 'b' })
		await refreshApplication.call(b)
		expect(b.error).toBeInstanceOf(Error)
		expect(b.error.message).toBe('plain')
		expect(b.loadVersions).not.toHaveBeenCalled()
	})
})

describe('applicationVersionsMixin.productionVersionUuid', () => {
	it('reads a uuid, an embedded record or nothing', () => {
		const read = (application) => productionVersionUuid.call({ application })
		expect(read(null)).toBeNull()
		expect(read({})).toBeNull()
		expect(read({ productionVersion: '' })).toBeNull()
		expect(read({ productionVersion: 'p' })).toBe('p')
		expect(read({ productionVersion: { uuid: 'u', id: 'i' } })).toBe('u')
		expect(read({ productionVersion: { id: 'i' } })).toBe('i')
		expect(read({ productionVersion: { '@self': { id: 's' } } })).toBeNull()
	})
})
