/**
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * TemplateGallery's GitHub search, credential probe and install flow: the
 * search waits for a pause and sends the first GitHub credential, a failed
 * search reads as unreachable, every credential list shape is read, an
 * install opens the dialog for the card's repository, install warnings are
 * shown, the redirect falls back from page editor to app manager to the
 * dashboard, and a refused create names its reason.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, showWarning } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
	showWarning: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', async (importOriginal) => ({
	...(await importOriginal()),
	showWarning,
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

let credentials = { data: [] }

async function mountGallery(routes = ['PageEditor', 'VirtualApps', 'Dashboard']) {
	axiosMock.get.mockImplementation((url, config) => {
		const u = String(url)
		if (u.includes('shop/github/search')) {
			return Promise.resolve({
				data: {
					outcome: 'ok',
					cards: [],
					rateLimited: false,
					brokerCredentialAvailable: true,
				},
			})
		}
		if (u.includes('credentials')) {
			return Promise.resolve(credentials)
		}
		return Promise.resolve({ data: {} })
	})
	const $router = {
		options: { routes: routes.map((name) => ({ name })) },
		push: vi.fn(),
	}
	const wrapper = mount(TemplateGallery, {
		global: {
			mocks: { $router },
			stubs: {
				NcButton: true,
				NcTextField: true,
				NcLoadingIcon: true,
				NcEmptyContent: true,
				NcNoteCard: true,
				FormLibraryView: true,
			},
		},
	})
	await flushPromises()
	return { wrapper, $router }
}

describe('TemplateGallery GitHub search and install', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		showWarning.mockReset()
		credentials = { data: [] }
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('reads the first GitHub credential from every list shape, and none on failure', async () => {
		credentials = {
			data: {
				results: [
					{ id: 'c-0', provider: 'gitlab' },
					{ id: 'c-1', provider: 'github' },
					{ id: 'c-2', provider: 'github' },
				],
			},
		}
		let { wrapper } = await mountGallery()
		expect([
			wrapper.vm.hasGithubCredential,
			wrapper.vm.githubCredentialId,
		]).toEqual([true, 'c-1'])

		credentials = { data: [{ provider: 'github' }] }
		;({ wrapper } = await mountGallery())
		expect([
			wrapper.vm.hasGithubCredential,
			wrapper.vm.githubCredentialId,
		]).toEqual([true, null])

		credentials = { data: 'nope' }
		;({ wrapper } = await mountGallery())
		expect(wrapper.vm.hasGithubCredential).toBe(false)

		credentials = Promise.reject(new Error('down'))
		credentials.catch(() => {})
		;({ wrapper } = await mountGallery())
		expect([
			wrapper.vm.hasGithubCredential,
			wrapper.vm.githubCredentialId,
		]).toEqual([false, null])
	})

	it('searches after a pause with the trimmed query and the credential', async () => {
		credentials = { data: [{ id: 'c-1', provider: 'github' }] }
		const { wrapper } = await mountGallery()
		vi.useFakeTimers()
		axiosMock.get.mockClear()
		axiosMock.get.mockResolvedValue({
			data: {
				cards: [{ repo: 'x' }],
				outcome: 'ok',
				rateLimited: true,
				brokerCredentialAvailable: false,
			},
		})

		wrapper.vm.onGithubQuery('pet')
		wrapper.vm.onGithubQuery(' petstore ')
		await vi.advanceTimersByTimeAsync(349)
		expect(axiosMock.get).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith(
			'/apps/buildiq/api/shop/github/search',
			{ params: { q: 'petstore', credentialId: 'c-1' } },
		)
		expect(wrapper.vm.githubCards).toEqual([{ repo: 'x' }])
		expect([
			wrapper.vm.githubRateLimited,
			wrapper.vm.githubBrokerAvailable,
			wrapper.vm.githubSearched,
			wrapper.vm.githubLoading,
		]).toEqual([true, false, true, false])
	})

	it('reads a failed search as GitHub unreachable', async () => {
		const { wrapper } = await mountGallery()
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.searchGithub()
		expect([
			wrapper.vm.githubCards,
			wrapper.vm.githubOutcome,
			wrapper.vm.githubRateLimited,
		]).toEqual([[], 'github_unreachable', false])
		expect(axiosMock.get.mock.calls.at(-1)[1]).toEqual({ params: { q: '' } })
	})

	it('opens the install dialog for the card repository', async () => {
		const { wrapper } = await mountGallery()
		wrapper.vm.openGithubInstall({
			owner: 'conduction',
			repo: 'petstore',
			description: 'Pets',
		})
		expect(wrapper.vm.cloneMode).toBe('github')
		expect(wrapper.vm.cloneOpen).toBe(true)
		expect(wrapper.vm.cloneTarget).toEqual({
			title: 'petstore',
			slug: 'petstore',
			description: 'Pets',
		})
		expect(wrapper.vm.cloneGithubRepo).toEqual({
			owner: 'conduction',
			repo: 'petstore',
		})
	})

	it('shows install warnings and redirects to the first editor that exists', async () => {
		let { wrapper, $router } = await mountGallery()
		wrapper.vm.cloneOpen = true
		wrapper.vm.onInstalled({
			slug: 'pets',
			warnings: [{ message: 'Scope missing' }],
		})
		expect(wrapper.vm.cloneOpen).toBe(false)
		expect(showWarning).toHaveBeenCalledWith('Scope missing')
		expect($router.push).toHaveBeenCalledWith({
			name: 'PageEditor',
			params: { slug: 'pets' },
		})

		;({ wrapper, $router } = await mountGallery(['VirtualApps']))
		wrapper.vm.onInstalled({ slug: 'pets' })
		expect($router.push).toHaveBeenCalledWith({
			name: 'VirtualApps',
			params: { slug: 'pets' },
		})

		;({ wrapper, $router } = await mountGallery([]))
		wrapper.vm.onInstalled({ slug: 'pets' })
		expect($router.push).toHaveBeenCalledWith({ name: 'Dashboard' })

		$router.push.mockClear()
		wrapper.vm.onInstalled({})
		expect($router.push).not.toHaveBeenCalled()
	})

	it('names why a create from a template was refused', async () => {
		const { wrapper } = await mountGallery()
		const msg = (data, status) =>
			wrapper.vm.cloneErrorMessage({ response: { data, status } })
		expect(msg({ error: 'forbidden' })).toBe(
			'Only administrators can create an app from a template.',
		)
		expect(msg({}, 403)).toBe(
			'Only administrators can create an app from a template.',
		)
		expect(msg({ detail: 'Bad manifest' })).toBe('Bad manifest')
		expect(msg({ error: 'broken' })).toBe('broken')
		expect(wrapper.vm.cloneErrorMessage(new Error('x'))).toBe(
			'The app could not be created.',
		)
	})
})
