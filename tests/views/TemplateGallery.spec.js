/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/views/TemplateGallery.vue`.
 *
 * The store is GitHub-only (github-only-store): there are no Local or Registry
 * source tabs. This spec covers the store shell + the install→redirect flow;
 * the GitHub search + card behaviour lives in TemplateGalleryGithub.spec.js.
 *
 * Covers:
 *   - the store runs a GitHub search on mount (empty query) and renders cards
 *   - "Install" on a GitHub card opens CloneTemplateDialog in GitHub mode
 *   - on a successful install, the store navigates to the page editor surface
 *     for the newly created application (REQ-OBTC-008)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: axiosMock,
}))

vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template', 'github', 'githubRepo'],
		emits: ['close', 'installed'],
		render() {
			return null
		},
	},
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const githubCards = [
	{
		owner: 'conduction',
		repo: 'petstore',
		slug: 'petstore',
		name: 'Pet Store',
		description: 'A pet store app',
		category: 'internal-operations',
		appType: 'virtual',
		version: '1.0.0',
		stars: 12,
		installable: true,
		unparseable: false,
		credentials: [],
	},
]

/**
 * Mount helper that injects a $router stub and the mount-time GitHub search.
 *
 * @param {object} routerOverrides optional stub overrides
 * @return {Promise<{wrapper: import('@vue/test-utils').Wrapper, $router: object}>}
 */
async function mountGallery(routerOverrides = {}) {
	// mounted() fires two GETs: the GitHub search (searchGithub) and the github
	// credential probe (fetchGithubCredentials). Dispatch by URL.
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		if (u.includes('shop/github/search')) {
			return Promise.resolve({
				data: { outcome: 'ok', cards: githubCards, rateLimited: false },
			})
		}
		if (u.includes('credentials')) {
			return Promise.resolve({ data: [] })
		}
		return Promise.resolve({ data: {} })
	})

	const $router = {
		// redirectAfterClone probes registered route names via hasRoute(), which
		// reads $router.options.routes (routes are built flat from the manifest
		// with name = page.id). Provide the surfaces it feature-detects.
		options: {
			routes: [
				{ name: 'PageEditor' },
				{ name: 'VirtualApps' },
				{ name: 'Dashboard' },
			],
		},
		push: vi.fn(),
		...routerOverrides,
	}

	const wrapper = mount(TemplateGallery, {
		mocks: {
			$router,
		},
		stubs: {
			NcButton: {
				name: 'NcButton',
				props: ['type', 'disabled'],
				template:
					'<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
			},
			NcTextField: {
				name: 'NcTextField',
				props: ['value', 'label', 'placeholder'],
				template:
					'<input class="nc-textfield-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
			},
			NcLoadingIcon: true,
			NcEmptyContent: {
				name: 'NcEmptyContent',
				props: ['name'],
				template: '<div class="nc-empty-stub">{{ name }}</div>',
			},
			NcNoteCard: {
				name: 'NcNoteCard',
				props: ['type'],
				template:
					'<div class="nc-note-stub" :data-type="type"><slot /></div>',
			},
		},
	})

	// Wait for the mounted() axios calls to resolve.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return { wrapper, $router }
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
		const { wrapper } = await mountGallery()

		expect(axiosMock.get).toHaveBeenCalled()
		const urls = axiosMock.get.mock.calls.map((c) => String(c[0]))
		expect(
			urls.some((u) => u.includes('/apps/openbuild/api/shop/github/search')),
		).toBe(true)

		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(1)
		expect(cards.at(0).find('.template-card__title').text()).toBe('Pet Store')
	})

	it('"Install" opens CloneTemplateDialog in GitHub mode seeded with the repo', async () => {
		const { wrapper } = await mountGallery()

		wrapper.vm.openGithubInstall(githubCards[0])
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.cloneOpen).toBe(true)
		expect(wrapper.vm.cloneGithubRepo).toEqual({
			owner: 'conduction',
			repo: 'petstore',
		})
		expect(wrapper.vm.cloneTarget.slug).toBe('petstore')
	})

	it('on install success, redirects to the page editor surface', async () => {
		const { wrapper, $router } = await mountGallery()

		// The dialog owns the POST and emits `installed` with the created app.
		wrapper.vm.onInstalled({ uuid: 'new-app', slug: 'my-petstore' })

		// PageEditor is a registered route, so redirectAfterClone navigates there
		// by name (no $router.resolve() probing — that logs a vue-router warning
		// for unknown names).
		expect($router.push).toHaveBeenCalled()
		const firstPushArgs = $router.push.mock.calls[0][0]
		expect(firstPushArgs.name).toBe('PageEditor')
		expect(firstPushArgs.params.slug).toBe('my-petstore')
	})
})
