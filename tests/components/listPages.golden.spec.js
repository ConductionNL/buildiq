/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how the Agents, Automations and Rule sets pages read their lists:
 * a bare array, a `{ results }` envelope and any other body (an empty list),
 * the loading flag cleared afterwards, and the error message of a failed read.
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import AgentsPage from '../../src/views/AgentsPage.vue'
import AutomationsPage from '../../src/views/AutomationsPage.vue'
import RuleSetsPage from '../../src/views/RuleSetsPage.vue'

const APP = { slug: 'tool-library', name: 'Tool Library' }

/**
 * @param {object} Page the page component.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountPage(Page) {
	return shallowMount(Page, {
		global: {
			mocks: {
				$route: { query: {}, params: {} },
				$router: { replace: vi.fn().mockResolvedValue(undefined), push: vi.fn() },
			},
		},
	})
}

const SHAPES = [
	['a bare array', [APP], [APP]],
	['an envelope', { results: [APP] }, [APP]],
	['an envelope without a list', { results: 'x' }, []],
	['no body', null, []],
]

const LOADERS = [
	{ Page: AgentsPage, method: 'fetchApplications', list: 'applications', flag: 'loadingApplications', error: 'Could not load applications.' },
	{ Page: AutomationsPage, method: 'fetchApplications', list: 'applications', flag: 'loadingApplications', error: 'Could not load applications.' },
	{ Page: RuleSetsPage, method: 'fetchRuleSets', list: 'ruleSets', flag: 'loading', error: 'Could not load rule sets.' },
]

describe.each(LOADERS)('$Page.name $method', ({ Page, method, list, flag, error }) => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: [] })
	})

	it.each(SHAPES)('reads %s', async (_name, body, expected) => {
		const wrapper = mountPage(Page)
		axios.get.mockResolvedValueOnce({ data: body })

		await wrapper.vm[method]()

		expect(wrapper.vm[list]).toEqual(expected)
		expect(wrapper.vm[flag]).toBe(false)
		expect(wrapper.vm.errorMessage).toBe('')
	})

	it('reports a failed read', async () => {
		const wrapper = mountPage(Page)
		axios.get.mockRejectedValueOnce(new Error('boom'))

		await wrapper.vm[method]()

		expect(wrapper.vm.errorMessage).toBe(error)
		expect(wrapper.vm[flag]).toBe(false)
	})
})

describe('list filters', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.get.mockResolvedValue({ data: [] })
	})

	it('keeps the agents of the selected application from an envelope', async () => {
		const wrapper = mountPage(AgentsPage)
		wrapper.vm.selectedApp = APP
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1, applicationSlug: 'tool-library' }, { id: 2, applicationSlug: 'x' }] } })

		await wrapper.vm.fetchAgents()

		expect(wrapper.vm.agents).toEqual([{ id: 1, applicationSlug: 'tool-library' }])
	})

	it('reads the versions of the selected application from a bare array', async () => {
		const wrapper = mountPage(AutomationsPage)
		wrapper.vm.selectedApp = APP
		axios.get.mockResolvedValueOnce({ data: [{ uuid: 'v1' }] })

		await wrapper.vm.fetchVersions()

		expect(axios.get).toHaveBeenLastCalledWith('/apps/buildiq/api/applications/tool-library/versions')
		expect(wrapper.vm.versions).toEqual([{ uuid: 'v1' }])
	})
})
