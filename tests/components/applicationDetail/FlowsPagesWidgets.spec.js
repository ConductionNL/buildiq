// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Vitest spec for the Flows and Pages widgets on the application detail page.
 *
 * Covers:
 *  - FlowsWidget: row key, name and enabled state (including unknown), and
 *    the OpenRegister flow link, none without an id; the empty list.
 *  - PagesWidget: add-page is forwarded; opening a page pushes the page
 *    designer with its id, with and without a version; a page without an id
 *    does nothing.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars) => (vars ? path.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : path),
}))

import FlowsWidget from '../../../src/components/applicationDetail/widgets/FlowsWidget.vue'
import PagesWidget from '../../../src/components/applicationDetail/widgets/PagesWidget.vue'

describe('FlowsWidget', () => {
	const mountFlows = (flows = []) => mount(FlowsWidget, { props: { flows } })

	it('keys a row by uuid, then id, then name', () => {
		const vm = mountFlows().vm
		expect(vm.flowKey({ uuid: 'u', id: 'i', name: 'n' })).toBe('u')
		expect(vm.flowKey({ id: 7, name: 'n' })).toBe('7')
		expect(vm.flowKey({ name: 'n' })).toBe('n')
		expect(vm.flowKey(null)).toBe('')
	})

	it('names a flow, falling back to its uuid or a dash', () => {
		const vm = mountFlows().vm
		expect(vm.flowName({ name: 'Intake', uuid: 'u' })).toBe('Intake')
		expect(vm.flowName({ uuid: 'u' })).toBe('u')
		expect(vm.flowName({})).toBe('—')
	})

	it('shows enabled, disabled or unknown', () => {
		const vm = mountFlows().vm
		expect(vm.flowState({ enabled: true })).toBe('Enabled')
		expect(vm.flowState({ enabled: false })).toBe('Disabled')
		expect(vm.flowState({ enabled: null })).toBe('Unknown')
		expect(vm.flowState({})).toBe('Unknown')
		expect(vm.flowState(null)).toBe('Unknown')
	})

	it('links a flow by uuid or id, and not at all without one', () => {
		const vm = mountFlows().vm
		expect(vm.flowUrl({ uuid: 'abc' })).toBe('/apps/openregister/flows/abc')
		expect(vm.flowUrl({ id: 12 })).toBe('/apps/openregister/flows/12')
		expect(vm.flowUrl({ name: 'x' })).toBeNull()
	})

	it('lists one row per flow', () => {
		const wrapper = mountFlows([{ uuid: 'a', name: 'A', enabled: true }, { uuid: 'b', name: 'B' }])
		expect(wrapper.findAll('.ob-flows-widget__list li')).toHaveLength(2)
		expect(wrapper.text()).toContain('A')
		expect(wrapper.text()).toContain('Unknown')
	})

	it('shows no list without flows', () => {
		expect(mountFlows([]).find('.ob-flows-widget__list').exists()).toBe(false)
	})
})

describe('PagesWidget', () => {
	const mountPages = (props = {}) => {
		const router = { push: vi.fn() }
		const wrapper = mount(PagesWidget, {
			props: { appSlug: 'hello', pages: [{ id: 'p1', title: 'Home' }], ...props },
			global: { mocks: { $router: router } },
		})
		return { wrapper, router }
	}

	it('forwards add-page', () => {
		const { wrapper } = mountPages()
		wrapper.vm.addPage()
		expect(wrapper.emitted('addPage')).toHaveLength(1)
	})

	it('opens a page in the designer of the version', () => {
		const { wrapper, router } = mountPages({ versionSlug: 'staging' })
		wrapper.vm.openPage({ id: 'p1' })
		expect(router.push).toHaveBeenCalledWith(expect.objectContaining({
			name: 'PageDesigner',
			params: { slug: 'hello' },
			query: { _version: 'staging', pageId: 'p1' },
		}))
	})

	it('opens a page without a version query when none is active', () => {
		const { wrapper, router } = mountPages()
		wrapper.vm.openPage({ id: 'p2' })
		expect(router.push.mock.calls[0][0].query).toEqual({ pageId: 'p2' })
	})

	it('ignores a page without an id', () => {
		const { wrapper, router } = mountPages()
		wrapper.vm.openPage({ title: 'x' })
		wrapper.vm.openPage(null)
		expect(router.push).not.toHaveBeenCalled()
	})
})
