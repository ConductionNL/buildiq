/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the GitHub source tab additions in
 * `src/views/TemplateGallery.vue` (github-shop-catalogue).
 *
 * Covers template-catalogue-ui:
 *   - the Local + GitHub source tabs render; Registry is hidden when the store
 *     is not configured
 *   - the GitHub tab issues the search request only when it is selected, and
 *     renders the returned cards
 *   - Install on an installable card opens CloneTemplateDialog seeded with the
 *     GitHub repo identity
 *   - a rate-limited search shows the degraded hint without breaking the Local
 *     grid, and points to a credential when none is present
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
		props: ['open', 'template', 'remote', 'remoteSlug', 'github', 'githubRepo'],
		render() { return null },
	},
}))
vi.mock('../../src/dialogs/EditTemplateMetadataDialog.vue', () => ({
	default: { name: 'EditTemplateMetadataDialog', props: ['open', 'template'], render() { return null } },
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const seededTemplates = [
	{ uuid: 'tpl-1', slug: 'permit-tracker', title: 'Permit Tracker', category: 'government-services', isSeeded: true },
]

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
		if (u.includes('application-template')) {
			return Promise.resolve({ data: { results: seededTemplates } })
		}
		if (u.includes('store/templates')) {
			return Promise.resolve({ data: { outcome: 'not_configured', cards: [] } })
		}
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
			NcSelect: { name: 'NcSelect', props: ['value', 'options'], template: '<select />' },
			NcLoadingIcon: true,
			NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-stub">{{ name }}</div>' },
			NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="nc-note-stub" :data-type="type"><slot /></div>' },
			NcDialog: { name: 'NcDialog', props: ['open', 'name'], template: '<div><slot /><slot name="actions" /></div>' },
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

describe('TemplateGallery.vue — GitHub source tab', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders the Local + GitHub tabs and hides Registry when the store is not configured', async () => {
		const wrapper = await mountGallery()
		const tabLabels = wrapper.findAll('.template-gallery__tabs .nc-button-stub').wrappers.map((b) => b.text())
		expect(tabLabels).toContain('Local')
		expect(tabLabels).toContain('GitHub')
		expect(tabLabels).not.toContain('Registry')
		expect(wrapper.vm.storeConfigured).toBe(false)
	})

	it('does not search GitHub until the tab is selected, then renders the cards', async () => {
		const wrapper = await mountGallery()
		expect(githubSearchCalls()).toBe(0)

		wrapper.vm.setSource('github')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(githubSearchCalls()).toBe(1)
		expect(wrapper.vm.githubCards.length).toBe(2)
		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(2)
	})

	it('marks an unparseable repo as a non-installable card (no Install action)', async () => {
		const wrapper = await mountGallery()
		wrapper.vm.setSource('github')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		const badges = wrapper.findAll('.template-card__badge--warn')
		expect(badges.length).toBe(1)
	})

	it('Install seeds CloneTemplateDialog with the GitHub repo identity', async () => {
		const wrapper = await mountGallery()
		wrapper.vm.setSource('github')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		wrapper.vm.openGithubInstall(githubCards[0])
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.cloneOpen).toBe(true)
		expect(wrapper.vm.cloneMode).toBe('github')
		expect(wrapper.vm.cloneGithubRepo).toEqual({ owner: 'conduction', repo: 'petstore' })
		expect(wrapper.vm.cloneTarget.slug).toBe('petstore')
	})

	it('shows a rate-limit hint (with a credential pointer) without breaking the Local grid', async () => {
		const wrapper = await mountGallery({ outcome: 'github_rate_limited', cards: [], rateLimited: true, brokerCredentialAvailable: false }, [])

		// Local grid still renders its template.
		expect(wrapper.findAll('.template-card').length).toBe(1)

		wrapper.vm.setSource('github')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.githubUnavailable).toBe(true)
		expect(wrapper.vm.hasGithubCredential).toBe(false)
		const hint = wrapper.find('.template-gallery__github-hint')
		expect(hint.exists()).toBe(true)
	})
})
