// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import ApplicationCard from '../../src/components/ApplicationCard.vue'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['team-alpha'],
}))

const t = (app, str) => str

/**
 * Spec A (openbuilt-nextcloud-nav) removed the top-level `status` and
 * `version` fields from Application; they now live on ApplicationVersion.
 * The card resolves these via the Application's `productionVersion` relation
 * object (a nested ApplicationVersion), which OR returns inline when the
 * caller uses `?extend=productionVersion` (or the store pre-fetches it).
 *
 * Spec A also removed the redundant "Live" chip (task 4.2): no element
 * with class `ob-app-card__chip--live` or text "Live" should appear.
 */
describe('ApplicationCard', () => {
	const factory = (object, extra = {}) => shallowMount(ApplicationCard, {
		propsData: { object, ...extra },
		mocks: { t, $router: { push: vi.fn() } },
	})

	// --- name / slug / icon ------------------------------------------------

	it('renders the app name, slug chip, and icon img', () => {
		const w = factory({ name: 'Hello World', slug: 'hello-world' })
		const text = w.text()
		expect(text).toContain('Hello World')
		expect(text).toContain('/hello-world')
		// icon <img> is always rendered (falls back to app.svg on error)
		expect(w.find('img.ob-app-card__icon').exists()).toBe(true)
		expect(w.find('img.ob-app-card__icon').attributes('src')).toMatch(/icons\/hello-world\.svg$/)
	})

	it('falls back to the slug when there is no name', () => {
		const w = factory({ slug: 'untitled-app' })
		expect(w.text()).toContain('untitled-app')
	})

	// --- status from productionVersion (spec C/A) ---------------------------

	it('reads status from productionVersion when present', () => {
		const w = factory({
			slug: 'my-app',
			productionVersion: { status: 'published', semver: '2.0.0' },
		})
		expect(w.text()).toContain('Published')
		expect(w.find('.ob-app-card__badge--published').exists()).toBe(true)
	})

	it('reads semver from productionVersion when present', () => {
		const w = factory({
			slug: 'my-app',
			productionVersion: { status: 'published', semver: '2.3.4' },
		})
		expect(w.text()).toContain('2.3.4')
	})

	it('defaults status to draft and version to — when productionVersion is absent', () => {
		const w = factory({ slug: 'untitled-app' })
		expect(w.text()).toContain('Draft')
		expect(w.find('.ob-app-card__badge--draft').exists()).toBe(true)
		expect(w.text()).toContain('—')
	})

	it('defaults status to draft when productionVersion has no status', () => {
		const w = factory({ slug: 'x', productionVersion: { semver: '1.0.0' } })
		expect(w.find('.ob-app-card__badge--draft').exists()).toBe(true)
	})

	// --- "Live" chip removed (spec A task 4.2) ------------------------------

	it('never shows a "Live" chip regardless of currentVersion field', () => {
		// Pre-spec-A objects may still carry currentVersion in OR; the card
		// must ignore it now that the field has moved to ApplicationVersion.
		expect(factory({ slug: 'x', status: 'draft', currentVersion: 'snap-uuid' }).text()).not.toContain('Live')
		expect(factory({ slug: 'x', status: 'draft' }).text()).not.toContain('Live')
		expect(factory({ slug: 'x', currentVersion: null }).find('.ob-app-card__chip--live').exists()).toBe(false)
	})

	// --- RBAC role label ---------------------------------------------------

	it('shows the caller\'s role when they have one', () => {
		const owned = factory({ slug: 'x', permissions: { owners: ['team-alpha'] } })
		expect(owned.text()).toContain('Owner')
		const none = factory({ slug: 'x', permissions: { owners: ['other-team'] } })
		expect(none.text()).not.toContain('Owner')
	})

	// --- interaction -------------------------------------------------------

	it('emits click on click and on Enter', async () => {
		const w = factory({ slug: 'x' })
		await w.find('.ob-app-card__inner').trigger('click')
		await w.find('.ob-app-card__inner').trigger('keyup.enter')
		expect(w.emitted('click')).toHaveLength(2)
	})
})
