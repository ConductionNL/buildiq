/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/GitHubSyncModal.vue` (github-app-sync).
 *
 * Covers application-detail-ui:
 *   - the GitHub section renders write controls (picker + link + publish + pull)
 *     and the status readout for owners
 *   - a non-owner sees the status readout but not the write controls
 *   - Publish is disabled with a hint when publishAvailable is false, and
 *     enabled once a credential is chosen and publish is available
 *   - Pull calls the pull endpoint and surfaces the new draft version; a
 *     strict-parse failure is surfaced as an error naming the offending file
 */

import { mount } from '@vue/test-utils'
import {
	afterAll,
	afterEach,
	beforeAll,
	beforeEach,
	describe,
	expect,
	it,
	vi,
} from 'vitest'

// Give script-level t() real {placeholder} interpolation so the pull
// parse-error assertion can see the offending file name in the message.
const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars
			? String(key).replace(/\{(\w+)\}/g, (_, k) =>
					vars[k] !== null && vars[k] !== undefined ? vars[k] : `{${k}}`,
				)
			: key
})
afterAll(() => {
	globalThis.t = realT
})

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/modals/LinkRepoDialog.vue', () => ({
	default: {
		name: 'LinkRepoDialog',
		props: ['open', 'slug'],
		render() {
			return null
		},
	},
}))
vi.mock('../../src/modals/PublishConfirmDialog.vue', () => ({
	default: {
		name: 'PublishConfirmDialog',
		props: [
			'open',
			'slug',
			'credentialId',
			'credentialName',
			'versions',
			'repo',
		],
		render() {
			return null
		},
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
	NcModal: {
		name: 'NcModal',
		props: ['name', 'size'],
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template:
			'<button class="nc-button-stub" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['value', 'options', 'inputLabel'],
		template: '<select class="nc-select-stub" />',
	},
	NcLoadingIcon: true,
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="nc-note-stub" :data-type="type"><slot /></div>',
	},
}

/**
 * Mount the modal and open it (fires the load watchers), dispatching the
 * status / credentials / versions GETs by URL.
 *
 * @param {object} opts { isOwner, status, credentials, versions }
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountModal({
	isOwner = true,
	status = linkedStatus,
	credentials = [{ id: 'cred-1', name: 'My GitHub', provider: 'github' }],
	versions = [{ slug: 'v1', name: 'v1', semver: '1.0.0' }],
} = {}) {
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		if (u.includes('/github/status')) {
			return Promise.resolve({ data: status })
		}
		if (u.includes('/credentials')) {
			return Promise.resolve({ data: credentials })
		}
		if (u.includes('/versions')) {
			return Promise.resolve({ data: versions })
		}
		return Promise.resolve({ data: {} })
	})

	const wrapper = mount(GitHubSyncModal, {
		propsData: { open: false, slug: 'petstore', isOwner },
		stubs: STUBS,
	})
	await wrapper.setProps({ open: true })
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('GitHubSyncModal.vue', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders the status readout + write controls for an owner', async () => {
		const wrapper = await mountModal({ isOwner: true })

		expect(wrapper.vm.linked).toBe(true)
		expect(wrapper.vm.repoLabel).toBe('conduction/petstore')
		// Owner write controls present.
		expect(wrapper.find('.github-sync__actions').exists()).toBe(true)
		expect(wrapper.find('.github-sync__credential').exists()).toBe(true)
	})

	it('shows the status readout but no write controls for a non-owner', async () => {
		const wrapper = await mountModal({ isOwner: false })

		expect(wrapper.find('.github-sync__status').exists()).toBe(true)
		expect(wrapper.find('.github-sync__actions').exists()).toBe(false)
		expect(wrapper.find('.github-sync__credential').exists()).toBe(false)
	})

	it('disables Publish with a hint when publishAvailable is false', async () => {
		const wrapper = await mountModal({
			status: {
				...linkedStatus,
				publishAvailable: false,
				brokerCredentialAvailable: false,
			},
		})

		expect(wrapper.vm.publishAvailable).toBe(false)
		expect(wrapper.vm.canPublish).toBe(false)
		expect(wrapper.vm.publishHint.length).toBeGreaterThan(0)
	})

	it('enables Publish once a credential is chosen and publish is available', async () => {
		const wrapper = await mountModal({ isOwner: true })

		expect(wrapper.vm.canPublish).toBe(false) // no credential picked yet
		wrapper.vm.selectedCredential = { id: 'cred-1', label: 'My GitHub' }
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canPublish).toBe(true)

		wrapper.vm.openPublish()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.publishOpen).toBe(true)
	})

	it('Pull calls the pull endpoint and surfaces the new draft version', async () => {
		const wrapper = await mountModal({ isOwner: true })

		axiosMock.post.mockResolvedValueOnce({
			data: {
				outcome: 'ok',
				versionUuid: 'ver-2',
				versionSlug: 'draft-2',
				status: 'draft',
				sourceRef: 'main',
			},
		})

		await wrapper.vm.doPull()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/applications/petstore/github/pull')
		expect(body.ref).toBe('main')
		expect(wrapper.vm.pullResult).toBeTruthy()
		expect(wrapper.vm.pullResult.versionSlug).toBe('draft-2')
	})

	it('surfaces a pull parse failure as an error naming the offending file', async () => {
		const wrapper = await mountModal({ isOwner: true })

		axiosMock.post.mockRejectedValueOnce({
			response: { data: { error: 'manifest_invalid', file: 'manifest.json' } },
		})

		await wrapper.vm.doPull()

		expect(wrapper.vm.error).toContain('manifest.json')
		expect(wrapper.vm.pullResult).toBeNull()
	})
})
