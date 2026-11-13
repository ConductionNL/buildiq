/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the applicationVersions mixin's `$route.query._version` watcher: an
 * emptied query falls back to the production version, a known slug selects
 * that version, and an unknown slug leaves the selection alone.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { applicationVersionsMixin } = await import('../../src/mixins/applicationVersions.js')

const onVersionQuery = applicationVersionsMixin.watch['$route.query._version']

function vm(fields) {
	return {
		selectedVersionUuid: 'current',
		productionVersionUuid: '',
		orderedVersions: [
			{ uuid: 'u-2', slug: '2-0-0' },
			{ uuid: 'u-1', slug: '1-0-0' },
		],
		...fields,
	}
}

describe('applicationVersionsMixin $route.query._version watcher', () => {
	it.each([[''], [undefined], [null]])('falls back to the production version when the query is %j', (slug) => {
		const c = vm({ productionVersionUuid: 'u-prod' })
		onVersionQuery.call(c, slug)
		expect(c.selectedVersionUuid).toBe('u-prod')
	})

	it('keeps the selection when the query empties and there is no production version', () => {
		const c = vm()
		onVersionQuery.call(c, '')
		expect(c.selectedVersionUuid).toBe('current')
	})

	it('selects the version whose slug the query names', () => {
		const c = vm({ productionVersionUuid: 'u-prod' })
		onVersionQuery.call(c, '1-0-0')
		expect(c.selectedVersionUuid).toBe('u-1')
	})

	it('keeps the selection for a slug no version has', () => {
		const c = vm({ productionVersionUuid: 'u-prod' })
		onVersionQuery.call(c, '9-9-9')
		expect(c.selectedVersionUuid).toBe('current')
	})
})
