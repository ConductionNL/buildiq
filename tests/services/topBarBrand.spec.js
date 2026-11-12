/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/topBarBrand.js: the Nextcloud app-menu name, icon
 * and trigger label follow the virtual app, and stay so on re-render.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace('{app}', vars.app),
}))

const HEADER = `<header id="header"><span class="app-menu__current-app-name">Buildiq</span>
	<img class="app-menu__current-app-icon" src="/old.svg">
	<button aria-label="Open apps menu, currently in Buildiq"></button></header>`

/**
 * Describe the top-bar as one line.
 *
 * @return {string}
 */
function topBar() {
	const name = document.querySelector('.app-menu__current-app-name')
	const icon = document.querySelector('.app-menu__current-app-icon')
	const trigger = document.querySelector('button')
	return [
		name ? name.textContent : '-',
		icon ? `${icon.getAttribute('src')} ${icon.getAttribute('alt')} ${icon.style.filter}` : '-',
		trigger ? trigger.getAttribute('aria-label') : '-',
	].join(' | ')
}

/**
 * Let the MutationObserver callbacks run.
 *
 * @return {Promise<void>}
 */
function flush() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

describe('brandTopBar', () => {
	let brandTopBar
	beforeEach(async () => {
		vi.resetModules()
		document.body.innerHTML = HEADER
		;({ brandTopBar } = await import('../../src/services/topBarBrand.js'))
	})

	it('brands the name, white icon and trigger label', () => {
		brandTopBar('Pet Store', 'pet-store')
		expect(topBar()).toBe(
			'Pet Store | /index.php/apps/buildiq/icons/pet-store.svg Pet Store brightness(0) invert(1) | Open apps menu, currently in Pet Store',
		)
	})

	it('falls back to the slug as the name', () => {
		brandTopBar('', 'shop')
		expect(topBar()).toBe(
			'shop | /index.php/apps/buildiq/icons/shop.svg shop brightness(0) invert(1) | Open apps menu, currently in shop',
		)
	})

	it('refines an existing brand and keeps the name when none is given', () => {
		brandTopBar('Early', 'a')
		brandTopBar('Real', 'b')
		expect(topBar()).toBe(
			'Real | /index.php/apps/buildiq/icons/b.svg Real brightness(0) invert(1) | Open apps menu, currently in Real',
		)
		brandTopBar('', 'c')
		expect(topBar()).toBe(
			'Real | /index.php/apps/buildiq/icons/c.svg Real brightness(0) invert(1) | Open apps menu, currently in Real',
		)
	})

	it('re-applies after the header re-renders', async () => {
		brandTopBar('Pet Store', 'pet-store')
		document.querySelector('.app-menu__current-app-name').textContent = 'Buildiq'
		document
			.querySelector('button')
			.setAttribute('aria-label', 'Open apps menu, currently in Buildiq')
		await flush()
		expect(topBar()).toBe(
			'Pet Store | /index.php/apps/buildiq/icons/pet-store.svg Pet Store brightness(0) invert(1) | Open apps menu, currently in Pet Store',
		)
		document.querySelector('button').setAttribute('aria-label', 'other')
		document.querySelector('.app-menu__current-app-name').textContent = 'Again'
		await flush()
		expect(topBar()).toBe(
			'Pet Store | /index.php/apps/buildiq/icons/pet-store.svg Pet Store brightness(0) invert(1) | other',
		)
	})

	it('leaves an icon that already shows the app icon untouched', () => {
		document.body.innerHTML = HEADER.replace('/old.svg', '/index.php/apps/buildiq/icons/x.svg')
		brandTopBar('X', 'x')
		expect(topBar()).toBe(
			'X | /index.php/apps/buildiq/icons/x.svg null  | Open apps menu, currently in X',
		)
	})

	it('skips the parts the page does not have', async () => {
		document.body.innerHTML = '<div><span class="app-menu__current-app-name">Old</span></div>'
		brandTopBar('Only Name', 'o')
		expect(topBar()).toBe('Only Name | - | -')
		document.body.innerHTML = '<button aria-label="Unrelated"></button>'
		brandTopBar('Again', 'o')
		expect(topBar()).toBe('- | - | Unrelated')
		await flush()
	})
})
