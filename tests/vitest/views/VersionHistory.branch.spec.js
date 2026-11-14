/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * VersionHistory's branch and merge actions (lifecycle-git-branches T06):
 * "Start a branch" posts the entered name and reloads; "Merge into" offers
 * only the other drafts that are not production; each row shows its Git branch.
 */

import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, showErrorMock, showSuccessMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
	showErrorMock: vi.fn(),
	showSuccessMock: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: showErrorMock, showSuccess: showSuccessMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))

import VersionHistory from '../../../src/views/VersionHistory.vue'

const ROWS = [
	{ id: 'prod', slug: 'production', status: 'published' },
	{ id: 'dev', slug: 'development', status: 'draft' },
	{ id: 'fees', slug: 'permit-fees', status: 'draft', githubBranch: 'permit-fees' },
	{ id: 'old', slug: 'staging', status: 'published' },
]

/**
 * Mount the panel with the rows loaded.
 *
 * @param {boolean} canEdit Whether the caller may edit.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(canEdit = true) {
	axiosMock.get.mockResolvedValue({ data: ROWS })
	const wrapper = shallowMount(VersionHistory, {
		props: { appSlug: 'vergunningen', currentVersionUuid: 'prod', canEdit },
		global: { stubs: { 'router-link': true } },
	})
	await wrapper.vm.refresh()
	return wrapper
}

describe('VersionHistory branches', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		showErrorMock.mockReset()
		showSuccessMock.mockReset()
	})

	it('offers the other non-production drafts as merge targets', async () => {
		const wrapper = await mountPanel()
		const fees = ROWS[2]

		expect(wrapper.vm.mergeTargets(fees).map((r) => r.slug)).toEqual(['development'])
		expect(wrapper.vm.mergeTargets(ROWS[1]).map((r) => r.slug)).toEqual(['permit-fees'])
	})

	it('shows the actions only to an editor, and each row its Git branch', async () => {
		const editor = await mountPanel(true)
		expect(editor.findAll('[data-test="version-branch"]')).toHaveLength(4)
		expect(editor.findAll('[data-test="version-merge"]').length).toBeGreaterThan(0)
		expect(editor.text()).toContain('Git branch')

		const viewer = await mountPanel(false)
		expect(viewer.find('[data-test="version-branch"]').exists()).toBe(false)
		expect(viewer.find('[data-test="version-merge"]').exists()).toBe(false)
	})

	it('starts a branch from the chosen version and reloads the list', async () => {
		const wrapper = await mountPanel()
		axiosMock.post.mockResolvedValue({ data: { slug: 'more-fees' } })

		const readsBefore = axiosMock.get.mock.calls.length
		wrapper.vm.askBranch(ROWS[1])
		await wrapper.vm.startBranch('  more-fees ')

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/vergunningen/versions/development/branch', { name: 'more-fees' })
		expect(showSuccessMock).toHaveBeenCalledTimes(1)
		expect(axiosMock.get.mock.calls.length).toBe(readsBefore + 1)
		expect(wrapper.vm.branchSource).toBe(null)
	})

	it('shows the server reason when a branch is refused', async () => {
		const wrapper = await mountPanel()
		axiosMock.post.mockRejectedValue({ response: { data: { detail: 'A version named "permit-fees" already exists.' } } })

		wrapper.vm.askBranch(ROWS[1])
		await wrapper.vm.startBranch('permit-fees')

		expect(showErrorMock).toHaveBeenCalledWith('A version named "permit-fees" already exists.')
	})
})
