/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for App.vue's shell around CnAppRoot: what it hands the root
 * (app id, manifest, page types, translate, permissions), the "OpenRegister
 * is required" state with its install button for admins only, the admin
 * check's fallback when the settings store is not ready, and a failing store
 * initialisation that is logged instead of breaking the shell.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const stores = vi.hoisted(() => ({ initialize: vi.fn(), settings: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: (...a) => stores.initialize(...a) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => stores.settings() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url) => `/index.php${url}`,
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => `${app}:${text}` }))
vi.mock('@nextcloud/vue', () => ({
	NcAppContent: { render() { return h('main', this.$slots.default?.()) } },
	NcEmptyContent: {
		props: ['name', 'description'],
		render() { return h('section', { class: 'empty' }, [h('h2', this.name), this.$slots.icon?.(), this.$slots.action?.()]) },
	},
	NcButton: { props: ['variant', 'href'], render() { return h('a', { class: 'install', href: this.href }, this.$slots.default?.()) } },
}))

const { default: App } = await import('../../src/App.vue')

const CnAppRoot = {
	name: 'CnAppRoot',
	props: ['appId', 'aiCompanion', 'manifest', 'registry', 'customComponents', 'pageTypes', 'translate', 'permissions'],
	render() { return h('div', { class: 'root' }, this.$slots['dependency-missing']?.()) },
}

/**
 * Mount the shell with a stub root that renders the dependency-missing slot.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function mountApp(props = {}) {
	const wrapper = mount(App, {
		props: { manifest: { pages: [] }, ...props },
		global: { stubs: { CnAppRoot } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	stores.initialize.mockReset().mockResolvedValue(undefined)
	stores.settings.mockReset().mockReturnValue({ getIsAdmin: false })
})

afterEach(() => {
	delete window.OC
	vi.restoreAllMocks()
})

describe('App shell', () => {
	it('hands the root its app id, manifest, page types, translate and the user permissions', async () => {
		window.OC = { currentUser: { permissions: ['buildiq.publish'] } }
		const manifest = { pages: [{ id: 'home' }] }
		const pageTypes = { index: {} }
		const root = (await mountApp({ manifest, pageTypes })).findComponent({ name: 'CnAppRoot' })

		expect(root.props('appId')).toBe('buildiq')
		expect(root.props('aiCompanion')).toBe(true)
		expect(root.props('manifest')).toEqual(manifest)
		expect(root.props('pageTypes')).toEqual(pageTypes)
		expect(root.props('permissions')).toEqual(['buildiq.publish'])
		expect(root.props('translate')('Save')).toBe('buildiq:Save')
		expect(stores.initialize).toHaveBeenCalledTimes(1)
	})

	it('hands no permissions without a signed-in user', async () => {
		const root = (await mountApp()).findComponent({ name: 'CnAppRoot' })
		expect(root.props('permissions')).toEqual([])
	})

	it('asks an admin to install OpenRegister from the app store', async () => {
		stores.settings.mockReturnValue({ getIsAdmin: true })
		const wrapper = await mountApp()

		expect(wrapper.find('h2').text()).toBe('OpenRegister is required')
		expect(wrapper.find('img').attributes('src')).toBe('/apps/buildiq/img/app-dark.svg')
		expect(wrapper.find('a.install').attributes('href')).toBe('/index.php/settings/apps/integration/openregister')
		expect(wrapper.find('a.install').text()).toBe('Install OpenRegister')
	})

	it('shows no install button to anyone else', async () => {
		const wrapper = await mountApp()
		expect(wrapper.find('h2').text()).toBe('OpenRegister is required')
		expect(wrapper.find('a.install').exists()).toBe(false)
	})

	it('falls back to Nextcloud for the admin check when the settings store is not ready', async () => {
		stores.settings.mockImplementation(() => { throw new Error('no active pinia') })

		window.OC = { isUserAdmin: () => true }
		expect((await mountApp()).find('a.install').exists()).toBe(true)

		window.OC = {}
		expect((await mountApp()).find('a.install').exists()).toBe(false)
	})

	it('logs a failing store initialisation and still renders', async () => {
		const failure = new Error('register missing')
		stores.initialize.mockRejectedValue(failure)
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})

		const wrapper = await mountApp()

		expect(error).toHaveBeenCalledWith('buildiq: initializeStores() failed', failure)
		expect(wrapper.find('h2').text()).toBe('OpenRegister is required')
	})
})
