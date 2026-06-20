/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the remote template "store" surface added to
 * `src/views/TemplateGallery.vue`.
 *
 * Covers openbuild-remote-template-store:
 *   - when a registry is configured, the gallery searches the remote store
 *     and renders the returned cards as the PRIMARY surface
 *   - when no registry is configured, the gallery shows the built-in
 *     templates and makes NO store request
 *   - clicking "Install" on a store card opens the remote install dialog
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

const { settingsState } = vi.hoisted(() => ({
	settingsState: { storeConfigured: false, isAdmin: false },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: axiosMock,
}))

// The gallery reads store config via the settings pinia store; stub it so the
// test controls `storeConfigured` without standing up a real pinia instance.
vi.mock('../../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({
		getSettings: settingsState,
		fetchSettings: vi.fn().mockResolvedValue(settingsState),
	}),
}))

vi.mock('../../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template', 'remote', 'remoteSlug'],
		emits: ['close', 'submit', 'installed'],
		methods: { setError(message) { this.lastError = message } },
		render() { return null },
	},
}))

import TemplateGallery from '../../../src/views/TemplateGallery.vue'

const storeCards = [
	{ slug: 'permit-tracker', title: 'Permit Tracker', useCase: 'Permits', description: 'Permits app', category: 'government-services', version: '1.0.0' },
	{ slug: 'incident-reporter', title: 'Incident Reporter', useCase: 'Incidents', description: 'Field logging', category: 'field-work', version: '2.1.0' },
]

const localTemplates = [
	{ uuid: 'tpl-1', slug: 'employee-onboarding', title: 'Employee Onboarding', useCase: 'New hires', description: 'Checklist', category: 'internal-operations' },
]

/**
 * Mount the gallery with the shared component stubs.
 *
 * @return {Promise<{wrapper: import('@vue/test-utils').Wrapper, $router: object}>}
 */
async function mountGallery() {
	const $router = {
		resolve: vi.fn().mockReturnValue({ resolved: { matched: [{}], fullPath: '/applications/x' } }),
		push: vi.fn(),
	}

	const wrapper = mount(TemplateGallery, {
		mocks: { $router },
		stubs: {
			NcButton: { name: 'NcButton', template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>' },
			NcTextField: { name: 'NcTextField', props: ['value', 'label', 'placeholder'], template: '<input class="nc-textfield-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />' },
			NcSelect: true,
			NcLoadingIcon: true,
			NcNoteCard: { name: 'NcNoteCard', template: '<div class="nc-notecard-stub"><slot /></div>' },
			NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-stub">{{ name }}</div>' },
		},
	})

	// Let mounted() async work (loadStoreState + fetchTemplates) settle.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return { wrapper, $router }
}

describe('TemplateGallery.vue — remote store surface', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		settingsState.storeConfigured = false
		settingsState.isAdmin = false
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('searches the remote store and renders its cards when a registry is configured', async () => {
		settingsState.storeConfigured = true
		// First GET = store search, second GET = local templates (mounted order).
		axiosMock.get.mockImplementation((url) => {
			if (String(url).includes('/apps/openbuild/api/store/templates')) {
				return Promise.resolve({ data: { outcome: 'ok', cards: storeCards } })
			}
			return Promise.resolve({ data: { results: localTemplates } })
		})

		const { wrapper } = await mountGallery()

		expect(wrapper.vm.storeConfigured).toBe(true)
		const storeRequest = axiosMock.get.mock.calls.find((c) => String(c[0]).includes('/apps/openbuild/api/store/templates'))
		expect(storeRequest).toBeTruthy()

		expect(wrapper.vm.storeCards.length).toBe(2)
		const storeSection = wrapper.find('.template-gallery__store')
		expect(storeSection.exists()).toBe(true)
		const titles = storeSection.findAll('.template-card__title').wrappers.map((c) => c.text())
		expect(titles).toEqual(expect.arrayContaining(['Permit Tracker', 'Incident Reporter']))
	})

	it('shows built-in templates and makes NO store request when no registry is configured', async () => {
		settingsState.storeConfigured = false
		axiosMock.get.mockResolvedValue({ data: { results: localTemplates } })

		const { wrapper } = await mountGallery()

		expect(wrapper.vm.storeConfigured).toBe(false)
		const storeRequest = axiosMock.get.mock.calls.find((c) => String(c[0]).includes('/apps/openbuild/api/store/templates'))
		expect(storeRequest).toBeFalsy()

		// The store section is not rendered; the built-in (local) section is.
		expect(wrapper.find('.template-gallery__store').exists()).toBe(false)
		expect(wrapper.find('.template-gallery__local').exists()).toBe(true)
		expect(wrapper.vm.templates.length).toBe(1)
	})

	it('clicking Install on a store card opens the remote install dialog', async () => {
		settingsState.storeConfigured = true
		axiosMock.get.mockImplementation((url) => {
			if (String(url).includes('/apps/openbuild/api/store/templates')) {
				return Promise.resolve({ data: { outcome: 'ok', cards: storeCards } })
			}
			return Promise.resolve({ data: { results: localTemplates } })
		})

		const { wrapper } = await mountGallery()

		expect(wrapper.vm.installOpen).toBe(false)
		wrapper.vm.openInstall(storeCards[0])
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.installOpen).toBe(true)
		expect(wrapper.vm.installTarget.slug).toBe('permit-tracker')

		// The remote install dialog receives :remote and the seeded slug.
		const dialog = wrapper.findComponent({ name: 'CloneTemplateDialog' })
		const remoteDialogs = wrapper.findAllComponents({ name: 'CloneTemplateDialog' }).wrappers
		const installDialog = remoteDialogs.find((d) => d.props('remote') === true)
		expect(installDialog).toBeTruthy()
		expect(installDialog.props('open')).toBe(true)
		expect(installDialog.props('remoteSlug')).toBe('permit-tracker')
		// Sanity: at least one dialog component exists.
		expect(dialog.exists()).toBe(true)
	})

	it('on a non-ok store outcome, surfaces the store error and keeps built-in templates', async () => {
		settingsState.storeConfigured = true
		axiosMock.get.mockImplementation((url) => {
			if (String(url).includes('/apps/openbuild/api/store/templates')) {
				return Promise.resolve({ data: { outcome: 'store_unreachable', cards: [] } })
			}
			return Promise.resolve({ data: { results: localTemplates } })
		})

		const { wrapper } = await mountGallery()

		expect(wrapper.vm.storeError).toBe(true)
		expect(wrapper.vm.storeCards.length).toBe(0)
		expect(wrapper.find('.template-gallery__store-error').exists()).toBe(true)
		// Built-in templates remain available as a fallback.
		expect(wrapper.vm.templates.length).toBe(1)
	})
})
