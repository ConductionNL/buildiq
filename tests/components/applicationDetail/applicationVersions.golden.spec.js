/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the application detail header and dashboard load, order and pick an
 * application's versions: list shapes, uuid fallbacks, route selection, failure,
 * record refresh and the watchers.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'
import axios from '@nextcloud/axios'
import ApplicationDetailDashboard from '../../../src/components/applicationDetail/ApplicationDetailDashboard.vue'
import ApplicationDetailHeader from '../../../src/components/applicationDetail/ApplicationDetailHeader.vue'
import { fetchApplicationRecord } from '../../../src/composables/useApplicationRecord.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/nc' + path, generateOcsUrl: (path) => '/ocs' + path }))
vi.mock('../../../src/composables/useApplicationRecord.js', () => ({ fetchApplicationRecord: vi.fn() }))

const FIXTURE = resolve(__dirname, '__golden__/applicationVersions.golden.json')
const dev = { uuid: 'dev', slug: 'development', promotesTo: 'stage' }
const stage = { uuid: 'stage', slug: 'staging', promotesTo: 'prod' }
const prod = { id: 'prod', slug: 'production' }
const selfId = { '@self': { id: 'self-id' }, slug: 'by-self' }
const application = { uuid: 'app-uuid', slug: 'hello world', productionVersion: 'prod', name: 'Hello' }

const lists = {
	array: [dev, stage, prod],
	results: { results: [prod, dev, selfId] },
	'not a list': { nope: true },
	'null body': null,
	'no uuid at all': [{ slug: 'bare' }],
}
const routes = {
	'no route': undefined,
	'empty query': { query: {}, params: {} },
	'known version': { query: { _version: 'staging' }, params: {} },
	'unknown version': { query: { _version: 'gone' }, params: {} },
}

/**
 * What a component holds.
 *
 * @param {object} vm The component.
 * @return {object} The state.
 */
function state(vm) {
	return JSON.parse(JSON.stringify({
		versions: vm.versions.map((v) => v.uuid),
		selected: vm.selectedVersionUuid,
		active: vm.activeVersionUuid,
		activeVersion: vm.activeVersion?.slug,
		production: vm.productionVersionUuid,
		productionVersion: vm.productionVersion?.slug,
		ordered: vm.orderedVersions.map((v) => v.slug),
		slug: vm.appSlug,
		application: vm.application?.uuid,
		error: vm.error ? String(vm.error) : null,
	}))
}

/**
 * Mount one component against an answer.
 *
 * @param {object} Component The component.
 * @param {object} options The answer, route and props.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(Component, { answer, route, props = { object: application, objectId: 'app-uuid' } }) {
	axios.get.mockImplementation(async (url) => {
		if (url.includes('/versions')) {
			if (answer instanceof Error) {
				throw answer
			}
			return { data: answer }
		}
		return { data: {} }
	})
	const wrapper = shallowMount(Component, {
		props,
		global: { mocks: { t: (_a, text) => text, $router: { push: vi.fn(), replace: vi.fn() }, $route: route } },
	})
	await flushPromises()
	return wrapper
}

describe('application versions', () => {
	beforeEach(() => vi.clearAllMocks())

	it('matches what was recorded', async () => {
		const actual = {}
		for (const [name, Component] of [['header', ApplicationDetailHeader], ['dashboard', ApplicationDetailDashboard]]) {
			for (const [ln, answer] of Object.entries(lists)) {
				for (const [rn, route] of Object.entries(routes)) {
					vi.clearAllMocks()
					const wrapper = await mounted(Component, { answer, route })
					actual[`${name} / ${ln} / ${rn}`] = {
						...state(wrapper.vm),
						urls: axios.get.mock.calls.map(([url]) => url).filter((url) => url.includes('/versions')),
					}
					wrapper.unmount()
				}
			}

			vi.clearAllMocks()
			const failed = await mounted(Component, { answer: new Error('boom'), route: routes['empty query'] })
			actual[`${name} / request fails`] = state(failed.vm)
			failed.unmount()

			const noSlug = await mounted(Component, { answer: lists.array, route: routes['empty query'], props: { object: { uuid: 'x' }, objectId: 'x' } })
			actual[`${name} / no slug`] = { ...state(noSlug.vm), asked: axios.get.mock.calls.filter(([url]) => url.includes('/versions')).length }
			noSlug.unmount()

			fetchApplicationRecord.mockResolvedValueOnce(application)
			const noRecord = await mounted(Component, { answer: lists.array, route: { query: {}, params: { objectId: 'from-route' } }, props: {} })
			actual[`${name} / record from the route`] = { ...state(noRecord.vm), fetched: fetchApplicationRecord.mock.calls.map(([id]) => id) }
			noRecord.unmount()

			vi.clearAllMocks()
			fetchApplicationRecord.mockRejectedValueOnce(new Error('no record'))
			const badRecord = await mounted(Component, { answer: lists.array, route: { query: {}, params: {} }, props: { objectId: 'by-prop' } })
			actual[`${name} / record fails`] = { ...state(badRecord.vm), fetched: fetchApplicationRecord.mock.calls.map(([id]) => id) }
			badRecord.unmount()

			const noId = await mounted(Component, { answer: lists.array, route: { query: {}, params: {} }, props: {} })
			actual[`${name} / no record id`] = state(noId.vm)
			noId.unmount()

			const rebound = await mounted(Component, { answer: lists.array, route: routes['empty query'] })
			vi.clearAllMocks()
			await rebound.setProps({ object: { ...application, uuid: 'next', slug: 'next-app', productionVersion: 'dev' } })
			await flushPromises()
			actual[`${name} / object changes`] = { ...state(rebound.vm), urls: axios.get.mock.calls.map(([url]) => url).filter((url) => url.includes('/versions')) }
			rebound.unmount()
		}

		const insights = vi.spyOn(ApplicationDetailDashboard.methods, 'scheduleInsightsFetch').mockImplementation(() => {})
		const delta = vi.spyOn(ApplicationDetailDashboard.methods, 'loadUserDeltaContent').mockImplementation(() => {})
		const followUps = {}
		for (const [name, answer] of [['loaded', lists.array], ['failed', new Error('boom')]]) {
			insights.mockClear()
			delta.mockClear()
			const dash = await mounted(ApplicationDetailDashboard, { answer, route: routes['empty query'] })
			followUps[name] = { insights: insights.mock.calls.length, delta: delta.mock.calls.length }
			dash.unmount()
		}
		insights.mockRestore()
		delta.mockRestore()
		actual['dashboard / follow-ups after the versions load'] = followUps

		if (!existsSync(FIXTURE) && process.env.GOLDEN_RECORD === '1') {
			writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		const expected = JSON.parse(readFileSync(FIXTURE, 'utf8'))
		expect(Object.keys(actual)).toEqual(Object.keys(expected))
		for (const name of Object.keys(expected)) {
			expect(actual[name], name).toEqual(expected[name])
		}
	}, 120000)
})
