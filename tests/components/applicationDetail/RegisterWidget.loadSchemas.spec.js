// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosGetMock } = vi.hoisted(() => ({
	axiosGetMock: vi.fn(async () => ({ data: { results: [] } })),
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGetMock } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import RegisterWidget from '../../../src/components/applicationDetail/widgets/RegisterWidget.vue'

const t = (app, key) => key

/**
 * Pins RegisterWidget::loadSchemas — the register lookup, the schema id to
 * title mapping, the sort order and the bounded empty-result retry.
 *
 * @param {object} props Component props.
 * @return {object} The mounted wrapper.
 */
function mountWidget(props) {
	return shallowMount(RegisterWidget, {
		propsData: props,
		mocks: { t, n: (app, s, p, c) => (c === 1 ? s : p) },
	})
}

/**
 * Route the two reads (registers, schemas) to fixed payloads.
 *
 * @param {object} payloads The registers and schemas response bodies.
 */
function routeReads(payloads) {
	axiosGetMock.mockImplementation(async (url) => {
		if (url.endsWith('/registers')) return { data: payloads.registers }
		return { data: payloads.schemas }
	})
}

const props = { appSlug: 'hello', versionSlug: 'production' }

describe('RegisterWidget loadSchemas', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		axiosGetMock.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('maps register schema ids to titles, drops unknown ids and sorts by name', async () => {
		routeReads({
			registers: {
				results: [
					{ slug: 'other', schemas: [9] },
					{ slug: 'openbuild-hello-production', schemas: [1, '2', 3, 4, 5] },
				],
			},
			schemas: {
				results: [
					{ '@self': { id: 1 }, title: 'Zeta' },
					{ id: 2, slug: 'beta-slug' },
					{ id: 3 },
					{ title: 'No id' },
					{ '@self': {}, id: 5, title: 'Alpha' },
				],
			},
		})
		const wrapper = mountWidget(props)
		await vi.runAllTimersAsync()
		expect(wrapper.vm.schemas).toEqual([
			{ id: '3', name: '3' },
			{ id: '5', name: 'Alpha' },
			{ id: '2', name: 'beta-slug' },
			{ id: '1', name: 'Zeta' },
		])
		expect(wrapper.vm.loading).toBe(false)
		expect(axiosGetMock).toHaveBeenCalledTimes(2)
		expect(axiosGetMock.mock.calls[0]).toEqual([
			'/apps/openregister/api/registers',
			{ params: { _limit: 1000 }, headers: { 'OCS-APIREQUEST': 'true' } },
		])
		expect(axiosGetMock.mock.calls[1]).toEqual([
			'/apps/openregister/api/schemas',
			{ params: { _limit: 10000 }, headers: { 'OCS-APIREQUEST': 'true' } },
		])
	})

	it('skips the schemas read when the register has no schemas and retries three times', async () => {
		routeReads({
			registers: { results: [{ slug: 'openbuild-hello-production' }] },
			schemas: { results: [] },
		})
		const wrapper = mountWidget(props)
		await vi.advanceTimersByTimeAsync(0)
		expect(wrapper.vm.loading).toBe(true)
		expect(axiosGetMock).toHaveBeenCalledTimes(1)
		await vi.runAllTimersAsync()
		expect(axiosGetMock).toHaveBeenCalledTimes(4)
		expect(axiosGetMock.mock.calls.every((c) => c[0].endsWith('/registers'))).toBe(true)
		expect(wrapper.vm.schemas).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('treats a missing results array and a failed read as empty', async () => {
		axiosGetMock.mockImplementationOnce(async () => ({ data: null }))
		axiosGetMock.mockImplementationOnce(async () => {
			throw new Error('boom')
		})
		routeReads({
			registers: { results: [{ slug: 'openbuild-hello-production', schemas: [1] }] },
			schemas: { results: 'nope' },
		})
		axiosGetMock.mockImplementationOnce(async () => ({ data: null }))
		axiosGetMock.mockImplementationOnce(async () => {
			throw new Error('boom')
		})
		const wrapper = mountWidget(props)
		await vi.runAllTimersAsync()
		expect(wrapper.vm.schemas).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does nothing until both slugs are known', async () => {
		const wrapper = mountWidget({ appSlug: 'hello', versionSlug: '' })
		await vi.runAllTimersAsync()
		expect(axiosGetMock).not.toHaveBeenCalled()
		expect(wrapper.vm.loading).toBe(false)
	})
})
