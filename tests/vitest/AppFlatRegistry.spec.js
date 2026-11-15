/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning App.vue's `flatRegistry`: each v2 registry entry
 * unwrapped to its component (bare components kept, empty entries dropped),
 * with the connection header-action handlers merged on top.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => {},
}))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ getIsAdmin: false }),
}))

import App from '../../src/App.vue'

function flatRegistryOf(registry) {
	const wrapper = shallowMount(App, { props: { manifest: {}, registry } })
	const out = wrapper.vm.flatRegistry
	wrapper.unmount()
	return out
}

describe('App flatRegistry', () => {
	it('unwraps kind-tagged entries, keeps bare components and drops empty ones', () => {
		const A = { name: 'A' }
		const B = { name: 'B' }
		const out = flatRegistryOf({
			a: { kind: 'widget', component: A },
			b: B,
			c: { kind: 'widget', component: null },
			d: null,
			e: 'Named',
			f: () => 'fn',
		})
		expect(out.a).toEqual(A)
		expect(out.b).toEqual(B)
		expect('c' in out).toBe(false)
		expect('d' in out).toBe(false)
		expect(out.e).toBe('Named')
		expect(typeof out.f).toBe('function')
		expect(typeof out.openIntegriqConnections).toBe('function')
	})

	it('lets the connection handlers win over a same-named entry', () => {
		const out = flatRegistryOf({ openIntegriqConnections: { component: { name: 'X' } } })
		expect(typeof out.openIntegriqConnections).toBe('function')
	})

	it('holds only the handlers without a registry', () => {
		const out = flatRegistryOf(null)
		expect(Object.keys(out)).toEqual(['openIntegriqConnections'])
	})
})
