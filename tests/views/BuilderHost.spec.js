/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuilderHost.vue's app-theming wiring — the
 * `appThemeHeaderStyle` / `appThemeLogoRef` computeds, the
 * appCustomTheme-before-appTheme injection order in `applyTheme()`, and
 * the lazy Application fetch gated on `headerStyle === 'branded'`.
 *
 * BuilderHost's pre-existing behaviour (version routing, CnAppRoot props,
 * dataSourcesLoader) has no prior vitest coverage (only quarantined
 * Playwright specs, per Conduction/openbuild#41) — this spec is scoped to
 * the app-theming surface this change adds, stubbing the rest minimally.
 *
 * Spec: app-theming (requirements "Theme applies via the existing scoped
 * CSS-variable mechanism", "An active nldesign theme takes precedence
 * over appTheme colors", "Logo defaults to the Application's existing
 * icon fields").
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const axiosGetMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGetMock(...a) },
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

let versionHolder = null
vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => ({
		applicationVersion: ref(versionHolder),
		loading: ref(false),
		error: ref(null),
	}),
}))

const appThemeMock = { apply: vi.fn(), teardown: vi.fn() }
vi.mock('../../src/composables/useAppTheme.js', () => ({
	useAppTheme: () => appThemeMock,
}))

const appCustomThemeMock = { apply: vi.fn(), teardown: vi.fn() }
vi.mock('../../src/composables/useAppCustomTheme.js', () => ({
	useAppCustomTheme: () => appCustomThemeMock,
}))

vi.mock('../../src/runtimeRegistry.js', () => ({ runtimeRegistry: {} }))
vi.mock('../../src/store/schemas.js', () => ({ registerSlugForApp: () => 'openbuild-x' }))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchDataSources: () => Promise.resolve({ registers: [] }) }),
	registerScope: () => ({}),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnAppRoot: { name: 'CnAppRoot', props: ['appId', 'aiCompanion', 'bundledManifest', 'registry', 'dataSourcesLoader', 'options'], render(h) { return h('div', { staticClass: 'cnapproot-stub' }) } },
}))
vi.mock('../../src/components/AppBrandedHeader.vue', () => ({
	default: { name: 'AppBrandedHeader', props: ['appSlug', 'appName', 'logoRef', 'applicationUuid'], render(h) { return h('div', { staticClass: 'app-branded-header-stub' }) } },
}))

const BuilderHost = (await import('../../src/views/BuilderHost.vue')).default

const flush = async (wrapper) => {
	await new Promise((r) => setTimeout(r, 0))
	if (wrapper) await wrapper.vm.$nextTick()
}

function mountHost({ slug = 'kap', version = null, appList = [] } = {}) {
	versionHolder = version
	axiosGetMock.mockReset()
	axiosGetMock.mockResolvedValue({ data: { results: appList } })
	appThemeMock.apply.mockClear()
	appThemeMock.teardown.mockClear()
	appCustomThemeMock.apply.mockClear()
	appCustomThemeMock.teardown.mockClear()
	return mount(BuilderHost, {
		mocks: { $route: { params: { slug }, query: {} } },
	})
}

describe('BuilderHost — app-theming', () => {
	beforeEach(() => {
		versionHolder = null
	})

	it('appThemeHeaderStyle defaults to "default" when the app has no appTheme', async () => {
		const wrapper = mountHost({ version: { manifest: {} } })
		await flush(wrapper)
		expect(wrapper.vm.appThemeHeaderStyle).toBe('default')
	})

	it('appThemeHeaderStyle reads runtime.appTheme.headerStyle from the resolved version', async () => {
		const wrapper = mountHost({
			version: { manifest: { runtime: { appTheme: { headerStyle: 'branded', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } } },
		})
		await flush(wrapper)
		expect(wrapper.vm.appThemeHeaderStyle).toBe('branded')
	})

	it('renders AppBrandedHeader only when headerStyle is "branded"', async () => {
		const wrapper = mountHost({
			version: { manifest: { runtime: { appTheme: { headerStyle: 'compact', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } } },
		})
		await flush(wrapper)
		expect(wrapper.find('.app-branded-header-stub').exists()).toBe(false)

		const branded = mountHost({
			version: { manifest: { runtime: { appTheme: { headerStyle: 'branded', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } } },
		})
		await flush(branded)
		expect(branded.find('.app-branded-header-stub').exists()).toBe(true)
	})

	it('applyTheme() applies appCustomTheme BEFORE appTheme (design.md Decision D3)', async () => {
		const manifest = { runtime: { appTheme: { headerStyle: 'default', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } }
		const wrapper = mountHost({ version: { manifest } })
		await flush(wrapper)

		expect(appCustomThemeMock.apply).toHaveBeenCalledWith(manifest, 'kap')
		expect(appTheme_calledAfterCustomTheme()).toBe(true)

		function appTheme_calledAfterCustomTheme() {
			const customOrder = appCustomThemeMock.apply.mock.invocationCallOrder[0]
			const nldesignOrder = appThemeMock.apply.mock.invocationCallOrder[0]
			return customOrder < nldesignOrder
		}
	})

	it('lazily fetches the Application only when headerStyle is "branded"', async () => {
		mountHost({
			version: { manifest: { runtime: { appTheme: { headerStyle: 'default', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } } },
		})
		await flush()
		expect(axiosGetMock).not.toHaveBeenCalled()
	})

	it('fetches the Application (name + uuid) when headerStyle is "branded"', async () => {
		const wrapper = mountHost({
			version: { manifest: { runtime: { appTheme: { headerStyle: 'branded', primaryColor: '#1D4ED8', secondaryColor: '#0F172A', accentColor: '#B45309' } } } },
			appList: [{ slug: 'kap', name: 'KAP', '@self': { id: 'app-uuid-1' } }],
		})
		await flush(wrapper)
		await flush(wrapper)
		expect(axiosGetMock).toHaveBeenCalledWith('/apps/openregister/api/objects/openbuild/application', { params: { _limit: 100 } })
		expect(wrapper.vm.appName).toBe('KAP')
		expect(wrapper.vm.applicationUuid).toBe('app-uuid-1')
	})

	it('beforeDestroy tears down both appliers', async () => {
		const wrapper = mountHost({ version: { manifest: {} } })
		await flush(wrapper)
		wrapper.destroy()
		expect(appThemeMock.teardown).toHaveBeenCalledWith('kap')
		expect(appCustomThemeMock.teardown).toHaveBeenCalledWith('kap')
	})
})
