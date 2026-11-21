/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The version-history half of REQ-BQCV-027: an editor of an app whose draft
 * 1.3.0 waits on production 1.2.0 is offered no release action, an owner is,
 * and the owner's release posts to the owner-only release endpoint. The
 * server refusal is asserted in
 * tests/Unit/Controller/ApplicationVersionReleaseScenariosTest.php.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/version-lifecycle-ui/spec.md#requirement-a-change-goes-live-only-when-an-owner-releases-it-req-bqcv-027
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))

import VersionHistory from '../../../src/views/VersionHistory.vue'

const ROWS = [
	{ id: 'v120', slug: 'production', application: 'app-1', semver: '1.2.0', status: 'published' },
	{ id: 'v130', slug: 'draft-130', application: 'app-1', semver: '1.3.0', status: 'draft' },
]

/**
 * Mount the history of the app with production 1.2.0 and draft 1.3.0.
 *
 * @param {object} role `{ canEdit, canRelease }` as the versions tab passes them.
 * @return {Promise<object>} The wrapper, after the list is read.
 */
async function historyFor(role) {
	axiosMock.get.mockResolvedValue({ data: ROWS })
	const wrapper = mount(VersionHistory, {
		propsData: { appSlug: 'permits', applicationUuid: 'app-1', currentVersionUuid: 'v120', ...role },
		global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
	})
	await vi.waitFor(() => expect(wrapper.vm.versions).toHaveLength(2))
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The Release buttons on the page.
 *
 * @param {object} wrapper The mounted history.
 * @return {Array<object>} The button wrappers.
 */
function releaseButtons(wrapper) {
	return wrapper.findAll('button').filter((b) => b.text() === 'Release')
}

describe('REQ-BQCV-027: a change goes live only when an owner releases it', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('an editor sees no release action on the draft', async () => {
		const wrapper = await historyFor({ canEdit: true, canRelease: false })

		expect(releaseButtons(wrapper)).toHaveLength(0)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('an owner releases 1.3.0 through the release endpoint', async () => {
		axiosMock.post.mockResolvedValue({ data: { productionVersion: 'v130' } })
		const wrapper = await historyFor({ canEdit: true, canRelease: true })

		const buttons = releaseButtons(wrapper)
		expect(buttons).toHaveLength(1)
		await buttons[0].trigger('click')
		await vi.waitFor(() => expect(wrapper.emitted('released')).toHaveLength(1))

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/permits/versions/draft-130/release',
			{},
		)
	})
})
