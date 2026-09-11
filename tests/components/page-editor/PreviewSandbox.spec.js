/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PreviewSandbox — the live preview's own Vue application.
 *
 * The point of the component is that the preview does NOT share the
 * designer's router: it builds a memory-history route table from the manifest
 * under edit, so a page body actually resolves and a click inside the preview
 * cannot navigate the designer.
 */

import { mount } from '@vue/test-utils'
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
		{ id: 'pets', type: 'index', route: '/pets' },
		{ id: 'pet', type: 'detail', route: '/pets/:id' },
	],
	menu: [],
}

function mountSandbox(manifest = MANIFEST) {
	return mount(PreviewSandbox, {
		propsData: { appId: 'openbuild-preview-pet-store', manifest },
		attachTo: document.body,
	})
}

describe('PreviewSandbox', () => {
	it('builds one route per manifest page, named by page id', () => {
		const wrapper = mountSandbox()
		const routes = wrapper.vm._sandboxRouter.getRoutes()
		// getRoutes() reports matcher order, not manifest order.
		const names = routes.map((r) => r.name).filter(Boolean)
		expect(names.sort()).toEqual(['dashboard', 'pet', 'pets'])
		// Route names ARE page ids: that is what CnPageRenderer matches on.
		expect(routes.find((r) => r.name === 'pet').path).toBe('/pets/:id')
		wrapper.unmount()
	})

	it('passes route params through to the page as props', () => {
		const wrapper = mountSandbox()
		const routes = wrapper.vm._sandboxRouter.getRoutes()
		expect(routes.find((r) => r.name === 'pet').props.default).toBe(true)
		expect(routes.find((r) => r.name === 'pets').props.default).toBe(false)
		wrapper.unmount()
	})

	it('lands on the first parameterless page', async () => {
		const wrapper = mountSandbox({
			pages: [
				{ id: 'pet', type: 'detail', route: '/pets/:id' },
				{ id: 'pets', type: 'index', route: '/pets' },
			],
			menu: [],
		})
		await wrapper.vm._sandboxRouter.isReady()
		expect(wrapper.vm._sandboxRouter.currentRoute.value.name).toBe('pets')
		wrapper.unmount()
	})

	it('skips pages with no id or no route', () => {
		const wrapper = mountSandbox({
			pages: [
				{ id: 'pets', type: 'index', route: '/pets' },
				{ id: 'no-route', type: 'index' },
				{ type: 'index', route: '/no-id' },
			],
			menu: [],
		})
		const names = wrapper.vm._sandboxRouter
			.getRoutes()
			.map((r) => r.name)
			.filter(Boolean)
		expect(names).toEqual(['pets'])
		wrapper.unmount()
	})

	it('keeps the running app across edits that do not change the routes', async () => {
		const wrapper = mountSandbox()
		const app = wrapper.vm._sandboxApp
		await wrapper.setProps({
			manifest: {
				...MANIFEST,
				pages: MANIFEST.pages.map((p) =>
					p.id === 'pets' ? { ...p, config: { title: 'Pets!' } } : p,
				),
			},
		})
		// A keystroke in an editor must not tear the preview down and back up.
		expect(wrapper.vm._sandboxApp).toBe(app)
		expect(wrapper.vm._sandboxState.manifest.pages[1].config.title).toBe(
			'Pets!',
		)
		wrapper.unmount()
	})

	it('rebuilds when a page is added', async () => {
		const wrapper = mountSandbox()
		const app = wrapper.vm._sandboxApp
		await wrapper.setProps({
			manifest: {
				...MANIFEST,
				pages: [...MANIFEST.pages, { id: 'owners', route: '/owners' }],
			},
		})
		expect(wrapper.vm._sandboxApp).not.toBe(app)
		expect(
			wrapper.vm._sandboxRouter.getRoutes().some((r) => r.name === 'owners'),
		).toBe(true)
		wrapper.unmount()
	})

	it('tells CnAppRoot the preview is not editable, so no Buildiq edit button', () => {
		const wrapper = mountSandbox()
		expect(wrapper.vm._sandboxState.manifest.openbuildEditable).toBe(false)
		wrapper.unmount()
	})

	it('swallows button clicks but lets links navigate', () => {
		const wrapper = mountSandbox()
		const host = wrapper.element

		const button = document.createElement('button')
		host.appendChild(button)
		const clicked = vi.fn()
		button.addEventListener('click', clicked)
		button.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
		expect(clicked).not.toHaveBeenCalled()

		const link = document.createElement('a')
		link.setAttribute('href', '/pets')
		host.appendChild(link)
		// preventDefault stands in for RouterLink's own handler; without it
		// jsdom tries to follow the href.
		const followed = vi.fn((e) => e.preventDefault())
		link.addEventListener('click', followed)
		link.dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }))
		expect(followed).toHaveBeenCalled()

		wrapper.unmount()
	})

	it('lets a disclosure widget open — a menu is not an action', () => {
		const wrapper = mountSandbox()
		const host = wrapper.element

		for (const attrs of [
			{ 'aria-expanded': 'false' },
			{ 'aria-haspopup': 'menu' },
			{ role: 'tab' },
		]) {
			const toggle = document.createElement('button')
			for (const [name, value] of Object.entries(attrs)) {
				toggle.setAttribute(name, value)
			}
			// The click usually lands on the icon inside the button.
			const icon = document.createElement('span')
			toggle.appendChild(icon)
			host.appendChild(toggle)

			const opened = vi.fn()
			toggle.addEventListener('click', opened)
			icon.dispatchEvent(
				new window.MouseEvent('click', { bubbles: true, cancelable: true }),
			)
			expect(opened).toHaveBeenCalled()
		}

		wrapper.unmount()
	})

	// NcActions teleports its menu to <body>, so the item is not inside the
	// host at all. The open trigger's `aria-controls` is the link back — this
	// is what let a "Refresh" item in an action menu still run.
	it('swallows the items inside a menu teleported out of the preview', () => {
		const wrapper = mountSandbox()

		const trigger = document.createElement('button')
		trigger.setAttribute('aria-haspopup', 'menu')
		trigger.setAttribute('aria-controls', 'menu-42')
		wrapper.element.appendChild(trigger)

		const popper = document.createElement('div')
		popper.className = 'v-popper__popper'
		const list = document.createElement('ul')
		list.id = 'menu-42'
		const item = document.createElement('button')
		item.className = 'action-button'
		list.appendChild(item)
		popper.appendChild(list)
		document.body.appendChild(popper)

		const refreshed = vi.fn()
		item.addEventListener('click', refreshed)
		item.dispatchEvent(
			new window.MouseEvent('click', { bubbles: true, cancelable: true }),
		)
		expect(refreshed).not.toHaveBeenCalled()

		popper.remove()
		wrapper.unmount()
	})

	it('leaves the designer around it alone', () => {
		const wrapper = mountSandbox()

		// Same shape, but no trigger in the preview points at it.
		const popper = document.createElement('div')
		const list = document.createElement('ul')
		list.id = 'designer-menu'
		const item = document.createElement('button')
		list.appendChild(item)
		popper.appendChild(list)
		document.body.appendChild(popper)

		const ran = vi.fn()
		item.addEventListener('click', ran)
		item.dispatchEvent(
			new window.MouseEvent('click', { bubbles: true, cancelable: true }),
		)
		expect(ran).toHaveBeenCalled()

		popper.remove()
		wrapper.unmount()
	})

	it('swallows Enter on a button but not in a text field', () => {
		const wrapper = mountSandbox()
		const host = wrapper.element

		const button = document.createElement('button')
		host.appendChild(button)
		const pressed = vi.fn()
		button.addEventListener('keydown', pressed)
		button.dispatchEvent(
			new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
		)
		expect(pressed).not.toHaveBeenCalled()

		const input = document.createElement('input')
		host.appendChild(input)
		const typed = vi.fn()
		input.addEventListener('keydown', typed)
		input.dispatchEvent(
			new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }),
		)
		expect(typed).toHaveBeenCalled()

		wrapper.unmount()
	})

	it('tears the app down on unmount', () => {
		const wrapper = mountSandbox()
		const host = wrapper.element
		expect(host.childNodes.length).toBeGreaterThan(0)
		wrapper.unmount()
		expect(host.childNodes.length).toBe(0)
	})
})
