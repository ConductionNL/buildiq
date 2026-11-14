/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The manifest's offline block and the "Waiting to sync" page that follows it
 * (pages-installable-offline-and-mobile T04, T06).
 */

import { describe, expect, it } from 'vitest'
import {
	normaliseOffline,
	normaliseTakeAlong,
	offlineForms,
	SYNC_QUEUE_COMPONENT,
	SYNC_QUEUE_PAGE_ID,
	withFormOffline,
	withOffline,
	withSyncQueuePage,
} from '../../src/services/offlineManifest.js'

const manifest = () => ({
	menu: [{ id: 'inspections', label: 'Inspections', route: 'inspections', order: 20 }],
	pages: [
		{ id: 'inspections', type: 'index', route: '/inspections' },
		{ id: 'report', type: 'form', route: '/report' },
	],
})

describe('normaliseTakeAlong', () => {
	it('caps the limit at 500 and the lifetime at 72 hours', () => {
		expect(normaliseTakeAlong({ schema: ' inspection ', filter: { a: 1 }, limit: 900, ttlHours: 100 }))
			.toEqual({ schema: 'inspection', filter: { a: 1 }, limit: 500, ttlHours: 72 })
	})

	it('uses the defaults for values that are not numbers, and at least 1', () => {
		expect(normaliseTakeAlong({ schema: 's', limit: 'x', ttlHours: 0 }))
			.toEqual({ schema: 's', filter: {}, limit: 50, ttlHours: 1 })
	})

	it('drops an entry without a schema, and a filter that is not an object', () => {
		expect(normaliseTakeAlong({ schema: '  ' })).toBeNull()
		expect(normaliseTakeAlong({ schema: 's', filter: ['a'] }).filter).toEqual({})
	})
})

describe('normaliseOffline', () => {
	it('is null when nothing is declared', () => {
		expect(normaliseOffline(null)).toBeNull()
		expect(normaliseOffline({ takeAlong: [{ schema: '' }], forms: [] })).toBeNull()
	})

	it('drops duplicate and empty form ids', () => {
		expect(normaliseOffline({ forms: ['report', '', 'report', ' other '] }))
			.toEqual({ takeAlong: [], forms: ['report', 'other'] })
	})
})

describe('withSyncQueuePage', () => {
	it('adds the page and a last menu entry when a form works offline', () => {
		const next = withSyncQueuePage({ ...manifest(), offline: { takeAlong: [], forms: ['report'] } })
		const page = next.pages.find((p) => p.id === SYNC_QUEUE_PAGE_ID)
		expect(page).toEqual({
			id: SYNC_QUEUE_PAGE_ID,
			route: '/waiting-to-sync',
			type: 'custom',
			title: 'Waiting to sync',
			component: SYNC_QUEUE_COMPONENT,
			config: {},
		})
		expect(next.menu.at(-1)).toEqual({ id: SYNC_QUEUE_PAGE_ID, label: 'Waiting to sync', icon: 'CloudSyncOutline', route: SYNC_QUEUE_PAGE_ID, order: 30 })
	})

	it('removes only the generated page when no form works offline', () => {
		const withPage = withSyncQueuePage({ ...manifest(), offline: { forms: ['report'] } })
		const without = withSyncQueuePage({ ...withPage, offline: undefined })
		expect(without.pages.map((p) => p.id)).toEqual(['inspections', 'report'])
		expect(without.menu.map((m) => m.id)).toEqual(['inspections'])
	})

	it('adds it once, however often it runs', () => {
		let next = { ...manifest(), offline: { forms: ['report'] } }
		next = withSyncQueuePage(withSyncQueuePage(next))
		expect(next.pages.filter((p) => p.id === SYNC_QUEUE_PAGE_ID)).toHaveLength(1)
		expect(next.menu.filter((m) => m.id === SYNC_QUEUE_PAGE_ID)).toHaveLength(1)
	})
})

describe('withOffline and withFormOffline', () => {
	it('switches a form page in and out, the sync page following', () => {
		const on = withFormOffline(manifest(), 'report', true)
		expect(offlineForms(on)).toEqual(['report'])
		expect(on.pages.some((p) => p.id === SYNC_QUEUE_PAGE_ID)).toBe(true)

		const off = withFormOffline(on, 'report', false)
		expect(off.offline).toBeUndefined()
		expect(off.pages.some((p) => p.id === SYNC_QUEUE_PAGE_ID)).toBe(false)
	})

	it('keeps the take-along when a form is switched off', () => {
		const start = withOffline(manifest(), { takeAlong: [{ schema: 'inspection' }], forms: ['report'] })
		const off = withFormOffline(start, 'report', false)
		expect(off.offline).toEqual({ takeAlong: [{ schema: 'inspection', filter: {}, limit: 50, ttlHours: 24 }], forms: [] })
	})

	it('does not change the manifest it was given', () => {
		const start = manifest()
		withFormOffline(start, 'report', true)
		expect(start).toEqual(manifest())
	})
})
