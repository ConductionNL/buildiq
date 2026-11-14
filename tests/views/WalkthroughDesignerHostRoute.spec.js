/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * WalkthroughDesignerHost.vue follows its route: another app or another
 * `?_version=` resolves the version again and reloads, a version that resolves
 * later is picked up, and without an app slug no version is looked up.
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a), put: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const resolved = vi.hoisted(() => ({ calls: [], refs: [] }))
vi.mock('../../src/composables/useApplicationVersion.js', () => ({
	useApplicationVersion: (slug, version) => {
		resolved.calls.push([slug, version])
		const holder = ref(null)
		resolved.refs.push(holder)
		return { applicationVersion: holder, loading: ref(false), error: ref(null) }
	},
}))
vi.mock('../../src/components/walkthrough-editor/WalkthroughDesigner.vue', async () => {
	const { h } = await import('vue')
	return { default: { name: 'WalkthroughDesigner', props: ['manifest', 'appSlug', 'versionSlug'], render: () => h('div') } }
})

const WalkthroughDesignerHost = (await import('../../src/views/WalkthroughDesignerHost.vue')).default

/**
 * Let the pending load settle.
 *
 * @param {object} wrapper The mounted wrapper.
 * @return {Promise<void>}
 */
async function flush(wrapper) {
	await new Promise((r) => setTimeout(r, 0))
	await wrapper.vm.$nextTick()
}

/**
 * Mount the host on a reactive route.
 *
 * @param {object} route The route's params and query.
 * @return {{wrapper: object, route: object}}
 */
function mountOn(route) {
	const $route = reactive(route)
	const wrapper = mount(WalkthroughDesignerHost, { global: { mocks: { $route } } })
	return { wrapper, route: $route }
}

beforeEach(() => {
	resolved.calls = []
	resolved.refs = []
	axiosGet.mockReset()
	axiosGet.mockResolvedValue({ data: { results: [] } })
})

describe('WalkthroughDesignerHost on its route', () => {
	it('resolves the version again and reloads when the app or the version changes', async () => {
		const { wrapper, route } = mountOn({ params: { slug: 'petstore' }, query: {} })
		await flush(wrapper)
		expect(resolved.calls).toEqual([['petstore', undefined]])
		const loads = axiosGet.mock.calls.length

		route.params.slug = 'zoo'
		await flush(wrapper)
		expect(resolved.calls).toEqual([['petstore', undefined], ['zoo', undefined]])
		expect(axiosGet.mock.calls.length).toBeGreaterThan(loads)

		route.query._version = 'v2'
		await flush(wrapper)
		expect(resolved.calls[2]).toEqual(['zoo', 'v2'])
		expect(wrapper.vm.versionSlug).toBe('v2')
	})

	it('takes up a version that resolves after the page opened', async () => {
		const { wrapper } = mountOn({ params: { slug: 'petstore' }, query: {} })
		await flush(wrapper)
		expect(wrapper.vm.applicationVersion).toBeNull()

		resolved.refs[0].value = { slug: 'v1' }
		await flush(wrapper)

		expect(wrapper.vm.applicationVersion).toEqual({ slug: 'v1' })
	})

	it('looks up no version without an app slug', async () => {
		const { wrapper } = mountOn({ params: {}, query: {} })
		await flush(wrapper)

		expect(resolved.calls).toEqual([])
		expect(wrapper.vm.routeSlug).toBe('')
		expect(wrapper.vm.applicationVersion).toBeNull()
	})
})
