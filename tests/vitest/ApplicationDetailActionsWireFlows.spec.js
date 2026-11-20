/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * After the flow bindings are saved the app detail actions ask the server to
 * wire their "Run on" entries, take the bindings it answers with, and say so
 * when an entry could not be wired (REQ-BQSF-002). The settings modal gets
 * the app version's schemas as "Run on" options (REQ-BQSF-001).
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: () => 'owner',
	getCurrentUserGroups: () => ['group1'],
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchSchemas: vi.fn(async () => [{ slug: 'vergunning', title: 'Vergunning' }, { slug: 'zaak' }, { title: 'no slug' }]),
	}),
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchProductionRegister: vi.fn(async () => 'reg-prod'),
}))
vi.mock('../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: vi.fn(async () => null),
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const application = {
	'@self': { id: 'app-uuid' },
	uuid: 'app-uuid',
	slug: 'my-permits',
	status: 'draft',
	productionVersion: 'ver-prod',
	permissions: { owners: ['group1'], editors: [], viewers: [] },
	flows: [],
}

const BOUND = { flow: 'f-1', label: 'Archiveer', runOn: [{ schema: 'vergunning', event: 'object.updated' }] }
const WRAPPER = { flow: 'w-1', label: 'aut-attach-my-permits-abc', sourceUuid: 'f-1' }

/**
 * Mount the actions bar as an owner.
 *
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountActions() {
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: { ...application }, objectId: 'app-uuid' },
		mocks: { t: (app, key) => key, $router: { push: vi.fn(() => Promise.resolve()) } },
		stubs: { AppSettingsModal: true },
	})
}

beforeEach(() => {
	for (const fn of Object.values(axiosMock)) {
		fn.mockReset()
	}
	axiosMock.get.mockResolvedValue({ data: [] })
	axiosMock.put.mockImplementation(async (url, body) => ({ data: { results: body } }))
})

describe('ApplicationDetailActions wires the "Run on" entries', () => {
	it('posts after the save and takes the bindings the server answers with', async () => {
		axiosMock.post.mockResolvedValue({ data: { flows: [BOUND, WRAPPER], created: 1, removed: 0, failed: [] } })
		const wrapper = mountActions()
		await flushPromises()

		await wrapper.vm.setFlows([BOUND])

		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/app-uuid/flow-wrappers')
		expect(axiosMock.put.mock.invocationCallOrder[0]).toBeLessThan(axiosMock.post.mock.invocationCallOrder[0])
		expect(wrapper.vm.obApp.flows).toEqual([BOUND, WRAPPER])
		expect(wrapper.vm.error).toBe('')
	})

	it('says when entries could not be wired', async () => {
		axiosMock.post.mockResolvedValue({ data: { flows: [BOUND], failed: [{ name: 'x', reason: 'no_register' }] } })
		const wrapper = mountActions()
		await flushPromises()

		await wrapper.vm.setFlows([BOUND])

		expect(wrapper.vm.error).toBe('Saved, but some "Run on" entries could not be wired. Save again to retry.')
	})

	it('reports a refused wiring call and keeps what was saved', async () => {
		axiosMock.post.mockRejectedValue(new Error('forbidden'))
		const wrapper = mountActions()
		await flushPromises()

		await wrapper.vm.setFlows([BOUND])

		expect(wrapper.vm.error).toBe('Saved, but the "Run on" entries could not be wired: forbidden')
		expect(wrapper.vm.obApp.flows).toEqual([BOUND])
	})

	it('does not wire anything when the save itself failed', async () => {
		axiosMock.put.mockRejectedValue(new Error('boom'))
		const wrapper = mountActions()
		await flushPromises()

		await wrapper.vm.setFlows([BOUND])

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Failed to save settings: boom')
	})

	it('gives the settings modal the version\'s schemas by slug when it opens', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountActions()
		await flushPromises()

		await wrapper.vm.onSettingsOpen(true)

		expect(wrapper.vm.flowSchemas).toEqual([
			{ label: 'Vergunning', value: 'vergunning' },
			{ label: 'zaak', value: 'zaak' },
		])
	})
})
