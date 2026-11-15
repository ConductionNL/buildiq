/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for GitHubSyncModal.vue's loading and follow-up paths: a status
 * that cannot be loaded, the credential and version envelopes and their
 * failures, the publish hint per cause, linking and publishing completing,
 * and closing the modal.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars ? String(key).replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? `{${k}}`)) : key
})
afterAll(() => {
	globalThis.t = realT
})

const { axiosMock, toast } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
	toast: { showError: vi.fn(), showSuccess: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('../../src/modals/LinkRepoDialog.vue', () => ({
	default: { name: 'LinkRepoDialog', props: ['open', 'slug'], emits: ['close', 'linked'], render: () => null },
}))
vi.mock('../../src/modals/PublishConfirmDialog.vue', () => ({
	default: {
		name: 'PublishConfirmDialog',
		props: ['open', 'slug', 'credentialId', 'credentialName', 'versions', 'repo'],
		emits: ['close', 'published'],
		render: () => null,
	},
}))

import GitHubSyncModal from '../../src/modals/GitHubSyncModal.vue'

const linkedStatus = {
	githubRepo: { owner: 'conduction', name: 'petstore' },
	githubDefaultBranch: 'main',
	lastPushedSha: 'abc1234567',
	lastPulledSha: null,
	brokerCredentialAvailable: true,
	publishAvailable: true,
}

const STUBS = {
	NcModal: { name: 'NcModal', props: ['name', 'size'], emits: ['close'], template: '<div class="nc-modal-stub"><slot /></div>' },
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button class="nc-button-stub" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options', 'inputLabel'], template: '<select class="nc-select-stub" />' },
	NcLoadingIcon: true,
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="nc-note-stub" :data-type="type"><slot /></div>' },
}

/**
 * Answer the three loads by URL.
 *
 * @param {object} answers Per-load answer: a value to resolve with, or an Error to reject with.
 * @return {void}
 */
function answer({ status = linkedStatus, credentials = [], versions = [] } = {}) {
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		const value = u.includes('/github/status') ? status : u.includes('/credentials') ? credentials : versions
		return value instanceof Error ? Promise.reject(value) : Promise.resolve({ data: value })
	})
}

/**
 * Mount closed, then open so the loads run.
 *
 * @param {object} answers The load answers.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function openModal(answers = {}) {
	answer(answers)
	const wrapper = mount(GitHubSyncModal, { props: { open: false, slug: 'petstore', isOwner: true }, global: { stubs: STUBS } })
	await wrapper.setProps({ open: true })
	await flushPromises()
	return wrapper
}

/**
 * The note cards of a type.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The modal.
 * @param {string} type The note type.
 * @return {Array<string>}
 */
function notes(wrapper, type) {
	return wrapper.findAll(`.nc-note-stub[data-type="${type}"]`).map((n) => n.text())
}

describe('GitHubSyncModal status and follow-ups', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		toast.showError.mockReset()
		toast.showSuccess.mockReset()
	})

	it('reports a status that cannot be loaded', async () => {
		const wrapper = await openModal({ status: new Error('500') })
		expect(wrapper.vm.status).toBe(null)
		expect(notes(wrapper, 'error')).toContain('Could not load the GitHub status.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('keeps only GitHub credentials from a results envelope, labelled by name else id', async () => {
		const wrapper = await openModal({
			credentials: { results: [{ id: 'c1', name: 'Bot', provider: 'github' }, { id: 'c2', provider: 'github' }, { id: 'c3', provider: 'gitlab' }, null] },
		})
		expect(wrapper.vm.credentialOptions).toEqual([{ id: 'c1', label: 'Bot' }, { id: 'c2', label: 'c2' }])
	})

	it('offers no credentials or versions when they cannot be read', async () => {
		const wrapper = await openModal({ credentials: new Error('403'), versions: new Error('500') })
		expect(wrapper.vm.credentials).toEqual([])
		expect(wrapper.vm.versions).toEqual([])
	})

	it('reads versions from a list or a results envelope, and nothing else', async () => {
		expect((await openModal({ versions: { results: [{ slug: 'v1' }] } })).vm.versions).toEqual([{ slug: 'v1' }])
		expect((await openModal({ versions: { unexpected: true } })).vm.versions).toEqual([])
	})

	it.each([
		[{ ...linkedStatus, publishAvailable: false, brokerCredentialAvailable: false }, 'the credential broker or its GitHub write rules are not enabled'],
		[{ ...linkedStatus, publishAvailable: false, brokerCredentialAvailable: true }, 'until a GitHub credential is configured'],
	])('explains why publishing is unavailable for %j', async (status, reason) => {
		const wrapper = await openModal({ status })
		expect(notes(wrapper, 'warning').some((n) => n.includes(reason))).toBe(true)
	})

	it('opens the link dialog and, once linked, closes it, confirms and reloads the status', async () => {
		const wrapper = await openModal()
		await wrapper.findAll('button').find((b) => b.text() === 'Re-link repository').trigger('click')
		const link = wrapper.findComponent({ name: 'LinkRepoDialog' })
		expect(link.props('open')).toBe(true)

		axiosMock.get.mockClear()
		await link.vm.$emit('linked')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'LinkRepoDialog' }).props('open')).toBe(false)
		expect(toast.showSuccess).toHaveBeenCalledWith('Repository linked.')
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/petstore/github/status')
	})

	it('closes the link dialog without reloading when it is dismissed', async () => {
		const wrapper = await openModal()
		wrapper.vm.linkOpen = true
		axiosMock.get.mockClear()
		await wrapper.findComponent({ name: 'LinkRepoDialog' }).vm.$emit('close')
		expect(wrapper.vm.linkOpen).toBe(false)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it('records a published commit, confirms it by short sha and reloads', async () => {
		const wrapper = await openModal()
		wrapper.vm.publishOpen = true
		axiosMock.get.mockReset()
		axiosMock.get.mockImplementation(() => new Promise(() => {}))
		await wrapper.findComponent({ name: 'PublishConfirmDialog' }).vm.$emit('published', { commitSha: 'def9876543210' })
		expect(wrapper.vm.publishOpen).toBe(false)
		expect(wrapper.vm.status.lastPushedSha).toBe('def9876543210')
		expect(toast.showSuccess).toHaveBeenCalledWith('Published commit def98765.')
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/petstore/github/status')
	})

	it('confirms a publish without a sha with a dash', async () => {
		const wrapper = await openModal()
		await wrapper.findComponent({ name: 'PublishConfirmDialog' }).vm.$emit('published', null)
		expect(toast.showSuccess).toHaveBeenCalledWith('Published commit —.')
	})

	it('does not open the publish dialog until publishing is possible', async () => {
		const wrapper = await openModal()
		wrapper.vm.openPublish()
		expect(wrapper.vm.publishOpen).toBe(false)
		wrapper.vm.selectedCredential = { id: 'c1', label: 'Bot' }
		wrapper.vm.openPublish()
		expect(wrapper.vm.publishOpen).toBe(true)
		await wrapper.findComponent({ name: 'PublishConfirmDialog' }).vm.$emit('close')
		expect(wrapper.vm.publishOpen).toBe(false)
	})

	it('asks to close when the modal closes', async () => {
		const wrapper = await openModal()
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
