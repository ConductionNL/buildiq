/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for BackToVirtualApps: the running app's way back to
 * Buildiq's app list (openbuild-runtime, runtime-back-to-virtual-apps).
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => `/index.php${p}`,
}))

const {
	default: BackToVirtualApps,
	declaresPrimaryAction,
	showBackToVirtualApps,
} = await import('../../../src/components/runtime/BackToVirtualApps.vue')

describe('BackToVirtualApps', () => {
	it("links to Buildiq's app list", () => {
		const wrapper = mount(BackToVirtualApps)
		const link = wrapper.find('a')
		expect(link.attributes('href')).toBe('/index.php/apps/buildiq/applications')
		// The label follows the navigation item, which is "Apps". It said
		// "virtual apps" until #878, and this assertion is what caught the
		// rename reaching the component.
		expect(link.text()).toContain('Back to apps')
	})

	it('shows in a version preview', () => {
		expect(showBackToVirtualApps({ versionSlug: 'development' })).toBe(true)
	})

	it("shows for the app's owner on the production URL", () => {
		expect(
			showBackToVirtualApps({
				versionSlug: '',
				manifest: { runtime: { user: { isOwner: true } } },
			}),
		).toBe(true)
	})

	it('stays hidden for other users of a published app', () => {
		expect(
			showBackToVirtualApps({
				manifest: { runtime: { user: { isOwner: false } } },
			}),
		).toBe(false)
		expect(showBackToVirtualApps({ manifest: { pages: [] } })).toBe(false)
		expect(showBackToVirtualApps()).toBe(false)
	})

	it('steps aside where the app declares its own primary action', () => {
		const manifest = {
			pages: [
				{ id: 'list', primaryAction: { label: 'New order' } },
				{ id: 'home' },
			],
		}
		expect(declaresPrimaryAction(manifest, 'list')).toBe(true)
		expect(declaresPrimaryAction(manifest, 'home')).toBe(false)
		expect(
			declaresPrimaryAction(
				{ nav: { primaryAction: { label: 'New' } } },
				'home',
			),
		).toBe(true)
		expect(declaresPrimaryAction(null, 'home')).toBe(false)
	})
})
