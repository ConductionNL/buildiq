/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the demo record actions of ApplicationDetailActions.
 *
 * Spec: capability-requirements-backfill (REQ-BQCV-030).
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, roleMock, recordMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
	roleMock: vi.fn(() => 'editor'),
	recordMock: vi.fn(async () => null),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('../../src/composables/useRole.js', () => ({
	useRole: roleMock,
	getCurrentUserGroups: () => ['group1'],
}))
vi.mock('../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: recordMock,
}))

import ApplicationDetailActions from '../../src/components/ApplicationDetailActions.vue'

const application = {
	uuid: 'app-uuid',
	slug: 'meldingen',
	name: 'Meldingen',
	productionVersion: 'ver-prod',
	manifest: { pages: [] },
	permissions: { owners: [], editors: ['group1'], viewers: [] },
}

/**
 * Mount the actions bar for an app, with a mocked caller role.
 *
 * @param {string} role The caller's role.
 * @param {object} app The Application record.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountActions(role, app = application) {
	roleMock.mockReturnValue(role)
	return shallowMount(ApplicationDetailActions, {
		propsData: { object: { ...app }, objectId: 'app-uuid' },
		mocks: { $router: { push: vi.fn(() => Promise.resolve()) } },
		stubs: { NcButton: true, AppSettingsModal: true },
	})
}

/**
 * The ids of the demo record entries the actions bar shows.
 *
 * @param {import('@vue/test-utils').Wrapper} wrapper The mounted bar.
 * @return {string[]}
 */
function demoIds(wrapper) {
	return wrapper.vm.actionDescriptors.map((d) => d.id).filter((id) => id.startsWith('app-demo-records'))
}

describe('ApplicationDetailActions — demo records', () => {
	beforeEach(() => {
		for (const fn of Object.values(axiosMock)) {
			fn.mockReset()
		}
		axiosMock.get.mockResolvedValue({ data: [] })
		recordMock.mockReset()
	})

	it('offers an editor "Fill with demo records", and "Remove" only once the app has some', () => {
		expect(demoIds(mountActions('editor'))).toEqual(['app-demo-records-fill'])
		expect(demoIds(mountActions('editor', { ...application, demoRecords: [{ uuid: 'd-1' }] })))
			.toEqual(['app-demo-records-fill', 'app-demo-records-remove'])
	})

	it('offers a viewer neither', () => {
		expect(demoIds(mountActions('viewer', { ...application, demoRecords: [{ uuid: 'd-1' }] }))).toEqual([])
	})

	it('fills, shows how many were added and re-reads the app', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: { created: 3, skipped: [], failed: 0, total: 3 } })
		recordMock.mockResolvedValueOnce({ ...application, demoRecords: [{ uuid: 'd-1' }] })
		const wrapper = mountActions('editor')

		const fill = wrapper.vm.actionDescriptors.find((d) => d.id === 'app-demo-records-fill')
		await fill.onSelect()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/app-uuid/demo-records')
		expect(wrapper.vm.toast).toBe('Added %n demo records.')
		expect(recordMock).toHaveBeenCalledWith('app-uuid')
		expect(demoIds(wrapper)).toContain('app-demo-records-remove')
	})

	it('removes the demo records', async () => {
		axiosMock.delete.mockResolvedValueOnce({ data: { removed: 1, failed: 0 } })
		recordMock.mockResolvedValueOnce({ ...application, demoRecords: [] })
		const wrapper = mountActions('editor', { ...application, demoRecords: [{ uuid: 'd-1' }] })

		await wrapper.vm.onDemoRecords(
			(uuid) => axiosMock.delete(`/apps/buildiq/api/applications/${uuid}/demo-records`).then((r) => r.data),
			(result) => `removed ${result.removed}`,
		)

		expect(wrapper.vm.toast).toBe('removed 1')
	})

	it('reports a failed request and keeps the app as it was', async () => {
		axiosMock.post.mockRejectedValueOnce(new Error('forbidden'))
		const wrapper = mountActions('editor')

		await wrapper.vm.actionDescriptors.find((d) => d.id === 'app-demo-records-fill').onSelect()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.error).toBe('Failed to change the demo records: forbidden')
		expect(recordMock).not.toHaveBeenCalled()
	})
})
