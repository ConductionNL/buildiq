// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => [],
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	imagePath: (app, file) => `/apps/${app}/img/${file}`,
}))

import ApplicationCard from '../../src/components/ApplicationCard.vue'

/**
 * A missing app icon is swapped for the bundled fallback exactly once: when
 * the fallback itself fails to load, the handler leaves the src alone instead
 * of re-setting it in a loop.
 */
describe('ApplicationCard icon fallback', () => {
	const factory = () =>
		shallowMount(ApplicationCard, {
			propsData: { object: { name: 'Hello', slug: 'hello' } },
			mocks: { t: (app, str) => str, $router: { push: vi.fn() } },
		})

	it('swaps a failing icon for the bundled app.svg', async () => {
		const img = factory().find('img.ob-app-card__icon')
		expect(img.attributes('src')).toBe('/index.php/apps/buildiq/icons/hello.svg')

		await img.trigger('error')

		expect(img.attributes('src')).toBe('/apps/buildiq/img/app.svg')
	})

	it('leaves the src alone when the fallback itself fails', () => {
		const vm = factory().vm
		const target = {
			getAttribute: vi.fn(() => '/apps/buildiq/img/app.svg'),
			src: 'http://host/apps/buildiq/img/app.svg',
		}

		vm.onIconError({ target })

		expect(target.getAttribute).toHaveBeenCalledWith('src')
		expect(target.src).toBe('http://host/apps/buildiq/img/app.svg')
	})
})
