/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Version history tab's snapshot panel (tutorial 07): take a labelled
 * snapshot, see who took it, roll back with a confirmation, and see the kept
 * "Previous draft" afterwards.
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) => p.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

import VersionSnapshotsPanel from '../../src/components/VersionSnapshotsPanel.vue'

const t = (app, key, vars) => Object.keys(vars || {}).reduce((out, k) => out.replace(`{${k}}`, vars[k]), key)
globalThis.t = t

const older = { id: 's-1', label: 'v1', takenBy: 'alice', takenAt: '2026-09-01T10:00:00+00:00', checksum: 'abcdef0123456789', kind: 'manual' }

function mountPanel({ canEdit = true, query = {} } = {}) {
	return shallowMount(VersionSnapshotsPanel, {
		props: { appSlug: 'shop', canEdit },
		global: {
			mocks: { t, $route: { query } },
			stubs: {
				NcButton: { template: '<button class="nc-button" @click="$emit(\'click\')"><slot /></button>' },
			},
		},
	})
}

describe('VersionSnapshotsPanel', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('lists the selected version\'s snapshots with who took them', async () => {
		axiosMock.get.mockResolvedValue({ data: [older] })
		const wrapper = mountPanel({ query: { _version: 'development' } })
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/shop/snapshots', { params: { version: 'development' } })
		expect(wrapper.text()).toContain('v1')
		expect(wrapper.text()).toContain('alice')
		expect(wrapper.text()).toContain('abcdef01')
	})

	it('takes a labelled snapshot and shows it first', async () => {
		axiosMock.get.mockResolvedValue({ data: [older] })
		axiosMock.post.mockResolvedValue({ data: { ...older, id: 's-2', label: 'before tasks page' } })
		const wrapper = mountPanel()
		await flushPromises()

		const take = wrapper.findAll('button').find((b) => b.text() === 'Take snapshot')
		await take.trigger('click')
		const prompt = wrapper.findComponent({ name: 'PromptTextDialog' })
		expect(prompt.props('open')).toBe(true)
		prompt.vm.$emit('submit', 'before tasks page')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/shop/snapshots', { label: 'before tasks page', version: '' })
		expect(wrapper.findAll('.ob-snapshots__label').map((l) => l.text())).toEqual(['before tasks page', 'v1'])
	})

	it('rolls back after confirmation and lists the kept Previous draft', async () => {
		axiosMock.get
			.mockResolvedValueOnce({ data: [older] })
			.mockResolvedValueOnce({ data: [{ id: 's-3', label: 'Previous draft', kind: 'previous-draft', takenBy: 'alice', takenAt: '2026-09-02T10:00:00+00:00' }, older] })
		axiosMock.post.mockResolvedValue({ data: {} })
		const wrapper = mountPanel()
		await flushPromises()

		const rollback = wrapper.findAll('button').find((b) => b.text() === 'Roll back to this version')
		await rollback.trigger('click')
		const confirm = wrapper.findComponent({ name: 'ConfirmActionDialog' })
		expect(confirm.props('open')).toBe(true)
		expect(axiosMock.post).not.toHaveBeenCalled()

		confirm.vm.$emit('confirm')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/shop/snapshots/s-1/restore', {})
		expect(wrapper.emitted('restored')).toHaveLength(1)
		expect(wrapper.findAll('.ob-snapshots__label').map((l) => l.text())).toEqual(['Previous draft', 'v1'])
	})

	it('offers no snapshot actions to a viewer', async () => {
		axiosMock.get.mockResolvedValue({ data: [older] })
		const wrapper = mountPanel({ canEdit: false })
		await flushPromises()

		expect(wrapper.findAll('button').map((b) => b.text())).toEqual([])
	})
})
