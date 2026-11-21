/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning the edges of PreviewSandbox that the main spec leaves
 * open: the render-error report's fallbacks, a route table the router
 * rejects, the route kept across a rebuild, which keys count as an
 * activation, ownership by `aria-owns`, and the data-binding signature.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key) => key,
}))

const PreviewSandbox = (
	await import('../../../src/components/page-editor/PreviewSandbox.vue')
).default

const MANIFEST = {
	pages: [
		{ id: 'dashboard', type: 'dashboard', route: '/' },
		{ id: 'pets', type: 'index', route: '/pets', config: { register: 'r', schema: 's' } },
	],
	menu: [],
}

function mountSandbox(props = {}) {
	return mount(PreviewSandbox, {
		propsData: { appId: 'openbuild-preview-pet-store', manifest: MANIFEST, ...props },
		attachTo: document.body,
	})
}

function key(target, name) {
	const seen = vi.fn()
	target.addEventListener('keydown', seen)
	target.dispatchEvent(new window.KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true }))
	return seen.mock.calls.length > 0
}

describe('PreviewSandbox render-error report', () => {
	it.each([
		[new Error('boom'), { $options: { name: 'A', __name: 'B' } }, 'A', 'boom'],
		[new Error(''), { $options: { __name: 'B' } }, 'B', 'Error'],
		['plain', { $options: {} }, 'unknown', 'plain'],
		[null, null, 'unknown', 'null'],
	])('reports %s from %j', async (error, instance, component, message) => {
		const diagnostics = vi.fn()
		const wrapper = mountSandbox({ diagnostics })
		await flushPromises()
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
		wrapper.vm._sandboxApp.config.errorHandler(error, instance)
		const report = diagnostics.mock.calls[0][0]
		expect(Object.keys(report)).toEqual(['kind', 'at', 'pageId', 'component', 'message'])
		expect(report).toMatchObject({ kind: 'render-error', pageId: 'dashboard', component, message })
		expect(typeof report.at).toBe('number')
		expect(consoleError).toHaveBeenCalledWith(error)
		consoleError.mockRestore()
		wrapper.unmount()
	})

	it('still logs to the console without a diagnostics listener', () => {
		const wrapper = mountSandbox()
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
		wrapper.vm._sandboxApp.config.errorHandler(new Error('x'), null)
		expect(consoleError).toHaveBeenCalledTimes(1)
		consoleError.mockRestore()
		wrapper.unmount()
	})
})

describe('PreviewSandbox sandbox lifecycle', () => {
	it('leaves the preview empty when the router rejects the route table', () => {
		const wrapper = mountSandbox({
			manifest: { pages: [{ id: 'a', route: '/a/:x([)' }], menu: [] },
		})
		expect(wrapper.vm._sandboxApp).toBe(null)
		expect(wrapper.vm._sandboxRouter).toBe(null)
		expect(wrapper.element.childNodes.length).toBe(0)
		wrapper.unmount()
	})

	it('keeps the current route across a rebuild', async () => {
		const wrapper = mountSandbox()
		await flushPromises()
		await wrapper.vm._sandboxRouter.push('/pets')
		await wrapper.setProps({
			manifest: { ...MANIFEST, pages: [...MANIFEST.pages, { id: 'more', route: '/more' }] },
		})
		await flushPromises()
		expect(wrapper.vm._sandboxRouter.currentRoute.value.fullPath).toBe('/pets')
		wrapper.unmount()
	})

	it('rebuilds when a page changes its data source', async () => {
		const wrapper = mountSandbox()
		const first = wrapper.vm._sandboxApp
		const pages = MANIFEST.pages.map((p) =>
			p.id === 'pets' ? { ...p, config: { ...p.config, dataSource: { kind: 'x' } } } : p,
		)
		await wrapper.setProps({ manifest: { ...MANIFEST, pages } })
		expect(wrapper.vm._sandboxApp).not.toBe(first)
		const again = wrapper.vm._sandboxApp
		await wrapper.setProps({ manifest: { ...MANIFEST, pages: pages.map((p) => ({ ...p })) } })
		expect(wrapper.vm._sandboxApp).toBe(again)
		wrapper.unmount()
	})

	it('signs a page whose config cannot be serialised as an empty binding', () => {
		const circular = {}
		circular.self = circular
		const wrapper = mountSandbox({
			manifest: { pages: [{ id: 'a', route: '/', config: { dataSource: circular } }], menu: [] },
		})
		expect(wrapper.vm.routeSignature).toBe('a@/@')
		wrapper.unmount()
	})
})

describe('PreviewSandbox activation keys and ownership', () => {
	it('swallows Space on a button and lets Escape and Tab through', () => {
		const wrapper = mountSandbox()
		const button = document.createElement('button')
		wrapper.element.appendChild(button)
		expect(key(button, ' ')).toBe(false)
		expect(key(button, 'Escape')).toBe(true)
		expect(key(button, 'Tab')).toBe(true)
		wrapper.unmount()
	})

	it('owns a teleported menu linked through aria-owns, not an unlinked one', () => {
		const wrapper = mountSandbox()
		const trigger = document.createElement('button')
		trigger.setAttribute('aria-owns', 'menu-1')
		wrapper.element.appendChild(trigger)
		const menu = document.createElement('div')
		menu.id = 'menu-1'
		const item = document.createElement('span')
		menu.appendChild(item)
		document.body.appendChild(menu)
		const stray = document.createElement('div')
		stray.id = 'other'
		document.body.appendChild(stray)
		expect(wrapper.vm.ownsTarget(item)).toBe(true)
		expect(wrapper.vm.ownsTarget(stray)).toBe(false)
		expect(wrapper.vm.ownsTarget(null)).toBe(false)
		expect(wrapper.vm.ownsTarget({})).toBe(false)
		menu.remove()
		stray.remove()
		wrapper.unmount()
	})

	it('lets an uppercase _BLANK anchor open its tab and blocks a same-tab one', () => {
		const wrapper = mountSandbox()
		const link = document.createElement('a')
		link.setAttribute('href', 'https://example.test')
		link.setAttribute('target', '_BLANK')
		wrapper.element.appendChild(link)
		const newTab = { defaultPrevented: false, target: link, preventDefault: vi.fn() }
		wrapper.vm.blockAnchorNavigation(newTab)
		expect(newTab.preventDefault).not.toHaveBeenCalled()
		link.setAttribute('target', '_self')
		const sameTab = { defaultPrevented: false, target: link, preventDefault: vi.fn() }
		wrapper.vm.blockAnchorNavigation(sameTab)
		expect(sameTab.preventDefault).toHaveBeenCalledTimes(1)
		const claimed = { defaultPrevented: true, target: link, preventDefault: vi.fn() }
		wrapper.vm.blockAnchorNavigation(claimed)
		expect(claimed.preventDefault).not.toHaveBeenCalled()
		wrapper.unmount()
	})
})
