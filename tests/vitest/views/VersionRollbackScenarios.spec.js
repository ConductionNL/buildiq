/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-BQCV-015: a maker rolls a version back to an earlier manifest, on the
 * Version history tab of the app detail page (ApplicationVersionsTab over the
 * real VersionHistory). Confirming writes the earlier manifest through the
 * manifest endpoint (`PUT /api/applications/{slug}/manifest`, wrapped in
 * `{ manifest }`), so the manifest comes back and the version link is kept;
 * cancelling writes nothing.
 *
 * The saves of one version ("the save before it", the rollback listed as the
 * newest save) are on each row's Saves button, which opens VersionSavesModal;
 * the modal itself is asserted in tests/modals/VersionSavesModal.spec.js.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, loadState } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
	loadState: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, vars = {}) => p.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../../src/modals/VersionSavesModal.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'VersionSavesModal',
			props: ['open', 'appSlug', 'version', 'canEdit'],
			emits: ['update:open', 'restored'],
			render() {
				return h('div', {
					class: 'saves-modal',
					'data-open': this.open ? 'true' : 'false',
					'data-version': this.version ? this.version.name : '',
					'data-can-edit': this.canEdit ? 'true' : 'false',
				})
			},
		},
	}
})
vi.mock('../../../src/modals/RollbackConfirmModal.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'RollbackConfirmModal',
			props: ['open', 'version'],
			emits: ['confirm', 'cancel', 'update:open'],
			render() {
				return h('div', { class: 'rollback-modal', 'data-open': this.open ? 'true' : 'false' }, [
					h('button', { class: 'rollback-modal__confirm', onClick: () => this.$emit('confirm', this.version) }, 'Roll back'),
					h('button', { class: 'rollback-modal__cancel', onClick: () => this.$emit('cancel') }, 'Cancel'),
				])
			},
		},
	}
})

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)
})
afterAll(() => {
	globalThis.t = realT
})

import ApplicationVersionsTab from '../../../src/components/tabs/ApplicationVersionsTab.vue'

const APP = {
	'@self': { id: 'app-uuid' },
	slug: 'permit-desk',
	status: 'draft',
	productionVersion: 'v-current',
	permissions: { owners: ['makers'], editors: [], viewers: [] },
}

const WITH_PERMITS = { pages: [{ id: 'home' }, { id: 'permits' }] }
const WITHOUT_PERMITS = { pages: [{ id: 'home' }] }

const VERSIONS = [
	{ uuid: 'v-current', name: 'Development', version: '1.1.0', status: 'draft', publishedAt: '2026-11-20T10:15:00Z', manifest: WITHOUT_PERMITS },
	{ uuid: 'v-before', name: 'Development', version: '1.0.0', status: 'published', publishedAt: '2026-11-19T09:00:00Z', manifest: WITH_PERMITS },
]

/**
 * Mount the Version history tab and wait for the versions to load.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function openVersionHistory() {
	const wrapper = mount(ApplicationVersionsTab, {
		props: { object: APP },
		global: {
			stubs: { RouterLink: { template: '<a><slot /></a>' }, PromptTextDialog: true, MergeVersionDialog: true, ReleaseTestReportDialog: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The row of the version with this semver.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted tab.
 * @param {string} semver The version's semver.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function rowOf(wrapper, semver) {
	return wrapper.findAll('.version-history__row').find((row) => row.text().includes(semver))
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.put.mockReset()
	loadState.mockReturnValue(['makers'])
	axiosMock.get.mockResolvedValue({ data: VERSIONS })
	axiosMock.put.mockImplementation((url, body) => Promise.resolve({ data: body }))
})

describe('REQ-BQCV-015 a maker rolls a version back to an earlier manifest', () => {
	it('A maker undoes a broken page change: confirming writes the earlier manifest, with permits, through the manifest endpoint', async () => {
		const wrapper = await openVersionHistory()
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/permit-desk/versions')

		await rowOf(wrapper, '1.0.0').findAll('button').find((b) => b.text() === 'Roll back').trigger('click')
		expect(wrapper.find('.rollback-modal').attributes('data-open')).toBe('true')

		await wrapper.find('.rollback-modal__confirm').trigger('click')
		await flushPromises()

		const manifestWrites = axiosMock.put.mock.calls.filter(([url]) => url.endsWith('/manifest'))
		expect(manifestWrites).toEqual([['/apps/buildiq/api/applications/permit-desk/manifest', { manifest: WITH_PERMITS }]])
		expect(manifestWrites[0][1].manifest.pages.map((p) => p.id)).toContain('permits')
		expect(wrapper.text()).not.toContain('Rollback failed')
	})

	it('Cancelling changes nothing: no manifest or application write', async () => {
		const wrapper = await openVersionHistory()

		await rowOf(wrapper, '1.0.0').findAll('button').find((b) => b.text() === 'Roll back').trigger('click')
		await wrapper.find('.rollback-modal__cancel').trigger('click')
		await flushPromises()

		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(wrapper.find('.rollback-modal').attributes('data-open')).toBe('false')
	})

	it('Each version row opens its saves, for the app and that version', async () => {
		const wrapper = await openVersionHistory()
		expect(wrapper.find('.saves-modal').attributes('data-open')).toBe('false')

		await rowOf(wrapper, '1.0.0').findAll('button').find((b) => b.text() === 'Saves').trigger('click')

		const modal = wrapper.find('.saves-modal')
		expect(modal.attributes('data-open')).toBe('true')
		expect(modal.attributes('data-version')).toBe('Development')
		expect(modal.attributes('data-can-edit')).toBe('true')
		expect(axiosMock.put).not.toHaveBeenCalled()
	})
})
