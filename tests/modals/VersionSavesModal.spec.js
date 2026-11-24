/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for VersionSavesModal (capability-requirements-backfill T15,
 * REQ-BQCV-015): the saves of one version, newest first; rolling back to the
 * save before a broken change writes that save's manifest through the versions
 * API and lists the rollback as the newest save; cancelling writes nothing.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import VersionSavesModal, { savesUrl } from '../../src/modals/VersionSavesModal.vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) => {
		let out = key
		Object.entries(vars || {}).forEach(([k, v]) => {
			out = out.replace(`{${k}}`, v)
		})
		return out
	}
})
afterAll(() => {
	globalThis.t = realT
})

const STUBS = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="nc-dialog-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		template: '<div class="nc-empty-stub"><span class="nc-empty-name">{{ name }}</span><slot name="icon" /></div>',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="nc-note-stub"><slot /></div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	HistoryIcon: { name: 'HistoryIcon', template: '<span />' },
}

const BASE = '/apps/buildiq/api/applications/permits/versions/development'
const WITH_PERMITS = { pages: [{ id: 'permits' }, { id: 'dashboard' }] }

/**
 * The saves the server holds: the broken one (permits removed) is newest.
 */
let saves

/**
 * Mount the dialog open on the development version.
 *
 * @param {boolean} canEdit Whether the caller may write the version.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountOpen(canEdit = true) {
	const wrapper = mount(VersionSavesModal, {
		props: { open: true, appSlug: 'permits', version: { slug: 'development', name: 'Development' }, canEdit },
		global: { stubs: STUBS },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	saves = [
		{ id: 'save-2', savedAt: '2026-11-24T10:00:02+00:00', user: 'sanne', pages: ['dashboard'] },
		{ id: 'save-1', savedAt: '2026-11-24T10:00:01+00:00', user: 'sanne', pages: ['permits', 'dashboard'] },
	]
	axiosMock.get.mockReset()
	axiosMock.put.mockReset()
	axiosMock.get.mockImplementation((url) => {
		if (url === `${BASE}/saves`) {
			return Promise.resolve({ data: { saves } })
		}
		if (url === `${BASE}/saves/save-1`) {
			return Promise.resolve({ data: { manifest: WITH_PERMITS } })
		}
		return Promise.reject(Object.assign(new Error('not found'), { response: { status: 404 } }))
	})
	axiosMock.put.mockImplementation((url, body) => {
		saves = [{ id: 'save-3', savedAt: '2026-11-24T10:00:03+00:00', user: 'sanne', pages: body.manifest.pages.map((p) => p.id) }, ...saves]
		return Promise.resolve({ data: {} })
	})
})

describe('VersionSavesModal', () => {
	it('builds the saves address of a version and of one save', () => {
		expect(savesUrl('permits', 'development')).toBe(`${BASE}/saves`)
		expect(savesUrl('permits', 'development', 'save-1')).toBe(`${BASE}/saves/save-1`)
	})

	it('lists the saves newest first, with a rollback on every earlier save only', async () => {
		const wrapper = await mountOpen()

		const rows = wrapper.findAll('.buildiq-version-saves__row')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('current')
		expect(rows[0].text()).toContain('Pages: dashboard')
		expect(rows[0].find('[data-testid="version-save-rollback"]').exists()).toBe(false)
		expect(rows[1].find('[data-testid="version-save-rollback"]').exists()).toBe(true)
	})

	it('rolls back to the save before a broken change and lists the rollback as the newest save', async () => {
		const wrapper = await mountOpen()

		await wrapper.find('[data-testid="version-save-save-1"] [data-testid="version-save-rollback"]').trigger('click')
		await wrapper.find('[data-testid="version-save-confirm"]').trigger('click')
		await flushPromises()

		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		expect(axiosMock.put).toHaveBeenCalledWith(BASE, { manifest: WITH_PERMITS })
		expect(wrapper.emitted('restored')).toEqual([[{ version: 'development', save: 'save-1' }]])
		const rows = wrapper.findAll('.buildiq-version-saves__row')
		expect(rows).toHaveLength(3)
		expect(rows[0].text()).toContain('Pages: permits, dashboard')
	})

	it('cancelling the confirmation writes nothing', async () => {
		const wrapper = await mountOpen()

		await wrapper.find('[data-testid="version-save-save-1"] [data-testid="version-save-rollback"]').trigger('click')
		await wrapper.find('[data-testid="version-save-cancel"]').trigger('click')
		await flushPromises()

		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="version-save-confirm"]').exists()).toBe(false)
		expect(wrapper.emitted('restored')).toBeUndefined()
	})

	it('offers no rollback to a viewer', async () => {
		const wrapper = await mountOpen(false)

		expect(wrapper.findAll('.buildiq-version-saves__row')).toHaveLength(2)
		expect(wrapper.find('[data-testid="version-save-rollback"]').exists()).toBe(false)
	})

	it('a refused rollback says the version is unchanged', async () => {
		axiosMock.put.mockRejectedValue(Object.assign(new Error('nope'), { response: { status: 403 } }))
		const wrapper = await mountOpen()

		await wrapper.find('[data-testid="version-save-save-1"] [data-testid="version-save-rollback"]').trigger('click')
		await wrapper.find('[data-testid="version-save-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.nc-note-stub').text()).toBe('You need to be an owner or editor of this app to roll it back.')
		expect(wrapper.emitted('restored')).toBeUndefined()
	})

	it('a refused list shows its message', async () => {
		axiosMock.get.mockRejectedValue(Object.assign(new Error('nope'), { response: { status: 403 } }))
		const wrapper = await mountOpen()

		expect(wrapper.find('.nc-note-stub').text()).toBe('You need a role on this app to see its saves.')
		expect(wrapper.findAll('.buildiq-version-saves__row')).toHaveLength(0)
	})
})
