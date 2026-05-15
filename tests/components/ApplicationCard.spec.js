// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, vi } from 'vitest'
import { shallowMount } from '@vue/test-utils'
import ApplicationCard from '../../src/components/ApplicationCard.vue'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ['team-alpha'],
}))

const t = (app, str) => str

describe('ApplicationCard', () => {
	const factory = (object, extra = {}) => shallowMount(ApplicationCard, {
		propsData: { object, ...extra },
		mocks: { t, $router: { push: vi.fn() } },
	})

	it('renders the app name, status pill and version', () => {
		const w = factory({ name: 'Hello World', slug: 'hello-world', status: 'published', version: '1.2.0' })
		const text = w.text()
		expect(text).toContain('Hello World')
		expect(text).toContain('Published')
		expect(text).toContain('1.2.0')
		expect(text).toContain('/hello-world')
	})

	it('falls back to the slug when there is no name, and defaults the status to draft', () => {
		const w = factory({ slug: 'untitled-app' })
		expect(w.text()).toContain('untitled-app')
		expect(w.text()).toContain('Draft')
		expect(w.find('.ob-app-card__badge--draft').exists()).toBe(true)
	})

	it('shows a "Live" marker when a published snapshot exists', () => {
		expect(factory({ slug: 'x', status: 'draft', currentVersion: 'snap-uuid' }).text()).toContain('Live')
		expect(factory({ slug: 'x', status: 'draft' }).text()).not.toContain('Live')
	})

	it('shows the caller\'s role when they have one', () => {
		const owned = factory({ slug: 'x', permissions: { owners: ['team-alpha'] } })
		expect(owned.text()).toContain('Owner')
		const none = factory({ slug: 'x', permissions: { owners: ['other-team'] } })
		expect(none.text()).not.toContain('Owner')
	})

	it('emits click on click and on Enter', async () => {
		const w = factory({ slug: 'x' })
		await w.find('.ob-app-card__inner').trigger('click')
		await w.find('.ob-app-card__inner').trigger('keyup.enter')
		expect(w.emitted('click')).toHaveLength(2)
	})
})
