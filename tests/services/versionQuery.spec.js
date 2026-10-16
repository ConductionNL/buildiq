/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/versionQuery.js: a running app opened with
 * `?_version=` keeps it on every link and navigation.
 */
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { keepVersionQuery, withVersion } from '../../src/services/versionQuery.js'

const Page = { render: () => null }

function makeRouter(versionSlug) {
	const router = createRouter({
		history: createMemoryHistory(),
		routes: [
			{ name: 'Dashboard', path: '/', component: Page },
			{ name: 'MessagesIndex', path: '/messages', component: Page },
			{ path: '/:catchAll(.*)', redirect: '/' },
		],
	})
	return keepVersionQuery(router, versionSlug)
}

describe('versionQuery', () => {
	it('adds the version to string and object locations', () => {
		expect(withVersion('/messages', 'development')).toBe(
			'/messages?_version=development',
		)
		expect(withVersion('/messages?page=2#top', 'development')).toBe(
			'/messages?page=2&_version=development#top',
		)
		expect(withVersion({ name: 'MessagesIndex' }, 'development')).toEqual({
			name: 'MessagesIndex',
			query: { _version: 'development' },
		})
	})

	it('leaves a location alone without a version, or when it names one', () => {
		expect(withVersion('/messages', '')).toBe('/messages')
		expect(withVersion('/m?_version=staging', 'development')).toBe(
			'/m?_version=staging',
		)
		expect(
			withVersion(
				{ path: '/m', query: { _version: 'staging' } },
				'development',
			),
		).toEqual({ path: '/m', query: { _version: 'staging' } })
	})

	it('builds menu hrefs that keep the version', () => {
		// Regression: menu links were built from route names only, so the
		// href carried no ?_version= and a click left the development preview.
		const router = makeRouter('development')
		expect(router.resolve({ name: 'MessagesIndex' }).fullPath).toBe(
			'/messages?_version=development',
		)
	})

	it('keeps the version on a push', async () => {
		const router = makeRouter('development')
		await router.push('/?_version=development')
		await router.push({ name: 'MessagesIndex' })
		expect(router.currentRoute.value.fullPath).toBe(
			'/messages?_version=development',
		)
		await router.push('/nowhere')
		expect(router.currentRoute.value.fullPath).toBe('/?_version=development')
	})

	it('changes nothing for a production URL', async () => {
		const router = makeRouter('')
		await router.push({ name: 'MessagesIndex' })
		expect(router.currentRoute.value.fullPath).toBe('/messages')
	})
})
