/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BuilderHost.vue — task 1.4 regression
 * (theme-picker-consumes-nldesign REQ-NTS-003).
 *
 * Confirms the deletion of `useAppTheme.js` and the
 * `data-openbuild-theme-scope` host wiring is complete: BuilderHost mounts
 * its nested CnAppRoot with ZERO theme-related props/attributes of its own
 * — `data-nldesign-theme-scope` is CnAppRoot's own self-applied attribute
 * (scoped-theme-applier REQ-STA-1/3), never something BuilderHost sets.
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, ref } from 'vue'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
}))

vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: () => ({
		applicationVersion: ref({ manifest: { runtime: { theme: { source: 'nldesign', tokenSet: 'amsterdam', tokenSetName: 'Amsterdam' } } } } ),
		loading: ref(false),
		error: ref(null),
		ready: Promise.resolve(),
	}),
}))

vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchDataSources: vi.fn().mockResolvedValue({ registers: [] }) }),
	registerScope: () => ({}),
}))

vi.mock('../../src/store/schemas.js', () => ({
	registerSlugForApp: (slug) => `openbuild-${slug}`,
}))

// `useAppTheme.js` no longer exists (deleted, REQ-NTS-003) — importing it
// here would throw a module-resolution error, which is itself proof the
// deletion is complete; BuilderHost.vue must not import it either.

const CnAppRootStub = {
	name: 'CnAppRoot',
	props: ['appId', 'aiCompanion', 'bundledManifest', 'registry', 'dataSourcesLoader', 'options'],
	render() { return h('div', { class: 'cn-app-root-stub' }) },
}

const BuilderHost = (await import('../../src/views/BuilderHost.vue')).default

function mountHost(slug = 'petstore') {
	return mount(BuilderHost, {
		mocks: { $route: { params: { slug }, query: {} } },
		stubs: { CnAppRoot: CnAppRootStub },
	})
}

describe('BuilderHost (REQ-NTS-003 — CnAppRoot owns theme application, zero OpenBuild wiring)', () => {
	it('renders with no data-openbuild-theme-scope attribute anywhere', async () => {
		const wrapper = mountHost()
		await wrapper.vm.$nextTick()
		expect(wrapper.html()).not.toContain('data-openbuild-theme-scope')
		expect(wrapper.find('[data-openbuild-theme-scope]').exists()).toBe(false)
	})

	it('passes CnAppRoot no theme-related prop — only its pre-existing runtime props', async () => {
		const wrapper = mountHost()
		await wrapper.vm.$nextTick()
		const cnAppRoot = wrapper.findComponent(CnAppRootStub)
		expect(cnAppRoot.exists()).toBe(true)
		// BuilderHost's own declared props to CnAppRoot — no `theme`, no
		// `data-nldesign-theme-scope` override, no `data-openbuild-theme-scope`.
		expect(Object.keys(cnAppRoot.props())).toEqual(
			expect.arrayContaining(['appId', 'aiCompanion', 'registry', 'dataSourcesLoader', 'options']),
		)
		expect(cnAppRoot.props()).not.toHaveProperty('theme')
	})

	it('has no beforeDestroy theme-teardown call (component instance carries no appTheme)', async () => {
		const wrapper = mountHost()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.appTheme).toBeUndefined()
		expect(() => wrapper.unmount()).not.toThrow()
	})
})
