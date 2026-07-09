/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the GitHub-only store in
 * `src/views/TemplateGallery.vue` (github-only-store).
 *
 * The store is GitHub-only: there are no Local/Registry source tabs. It runs
 * a GitHub search on mount (empty query lists all topic:openbuild-app repos),
 * re-searches on input, installs via the shared CloneTemplateDialog GitHub
 * path, and shows a non-blocking degraded hint when GitHub is rate-limited.
 *
 * Covers template-catalogue-ui:
 *   - GitHub search runs on mount and renders the returned cards
 *   - no Local/Registry source tabs are rendered
 *   - an unparseable repo renders a non-installable card (no Install action)
 *   - Install on an installable card opens CloneTemplateDialog seeded with the
 *     GitHub repo identity
 *   - a rate-limited search shows the degraded hint and points to a credential
 *     when none is present
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template', 'github', 'githubRepo'],
		render() { return null },
	},
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const githubCards = [
	{ owner: 'conduction', repo: 'petstore', slug: 'petstore', name: 'Pet Store', description: 'A pet store app', category: 'internal-operations', appType: 'virtual', version: '1.0.0', stars: 12, installable: true, unparseable: false, credentials: [] },
	{ owner: 'conduction', repo: 'broken', slug: 'broken', name: 'Broken', description: '', installable: false, unparseable: true, credentials: [] },
]

/**
 * Mount the gallery with dispatched mount-time GETs. `githubResponse` shapes
 * the response returned by the github search endpoint.
 *
 * @param {object} githubResponse The `/shop/github/search` response body.
 * @param {object} credentialsResponse The `/credentials` response body.
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountGallery(githubResponse = { outcome: 'ok', cards: githubCards, rateLimited: false, brokerCredentialAvailable: false }, credentialsResponse = []) {
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		if (u.includes('shop/github/search')) {
			return Promise.resolve({ data: githubResponse })
		}
		if (u.includes('credentials')) {
			return Promise.resolve({ data: credentialsResponse })
		}
		return Promise.resolve({ data: {} })
	})

	const wrapper = mount(TemplateGallery, {
		mocks: { $router: { resolve: vi.fn(), push: vi.fn() } },
		stubs: {
			NcButton: { name: 'NcButton', props: ['type', 'disabled'], template: '<button class="nc-button-stub" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>' },
			NcTextField: { name: 'NcTextField', props: ['value', 'label', 'placeholder'], template: '<input class="nc-textfield-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
			NcLoadingIcon: true,
			NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-stub">{{ name }}</div>' },
			NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="nc-note-stub" :data-type="type"><slot /></div>' },
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * Count GETs issued to the github search endpoint.
 *
 * @return {number}
 */
function githubSearchCalls() {
	return axiosMock.get.mock.calls.filter((c) => String(c[0]).includes('shop/github/search')).length
}

describe('TemplateGallery.vue — GitHub-only store', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('searches GitHub on mount and renders the returned cards', async () => {
		const wrapper = await mountGallery()
		expect(githubSearchCalls()).toBe(1)
		expect(wrapper.vm.githubCards.length).toBe(2)
		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(2)
	})

	it('renders no Local/Registry source tabs', async () => {
		const wrapper = await mountGallery()
		expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
		expect(wrapper.find('.template-gallery__tabs').exists()).toBe(false)
	})

	it('does not fetch local application-template or registry store endpoints', async () => {
		await mountGallery()
		const urls = axiosMock.get.mock.calls.map((c) => String(c[0]))
		expect(urls.some((u) => u.includes('application-template'))).toBe(false)
		expect(urls.some((u) => u.includes('store/templates'))).toBe(false)
	})

	it('marks an unparseable repo as a non-installable card (no Install action)', async () => {
		const wrapper = await mountGallery()
		const badges = wrapper.findAll('.template-card__badge--warn')
		expect(badges.length).toBe(1)
	})

	it('Install seeds CloneTemplateDialog with the GitHub repo identity', async () => {
		const wrapper = await mountGallery()

		wrapper.vm.openGithubInstall(githubCards[0])
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.cloneOpen).toBe(true)
		expect(wrapper.vm.cloneGithubRepo).toEqual({ owner: 'conduction', repo: 'petstore' })
		expect(wrapper.vm.cloneTarget.slug).toBe('petstore')
	})

	it('shows a rate-limit hint with a credential pointer when none is present', async () => {
		const wrapper = await mountGallery({ outcome: 'github_rate_limited', cards: [], rateLimited: true, brokerCredentialAvailable: false }, [])

		expect(wrapper.vm.githubUnavailable).toBe(true)
		expect(wrapper.vm.hasGithubCredential).toBe(false)
		const hint = wrapper.find('.template-gallery__github-hint')
		expect(hint.exists()).toBe(true)
	})
})
