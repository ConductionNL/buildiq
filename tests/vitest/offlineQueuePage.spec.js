/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Waiting to sync" page an app with offline forms gets resolves in the
 * runtime registry to the library's CnOfflineQueue, and is added and removed
 * with the offline forms (pages-installable-offline-and-mobile T06).
 */

import { CnOfflineQueue } from '@conduction/nextcloud-vue'
import { describe, expect, it } from 'vitest'
import { getRuntimeRegistry } from '../../src/runtimeRegistry.js'
import { SYNC_QUEUE_COMPONENT, SYNC_QUEUE_PAGE_ID, withFormOffline } from '../../src/services/offlineManifest.js'

describe('the Waiting to sync page', () => {
	it('is a custom page whose component the runtime registry resolves to CnOfflineQueue', () => {
		const entry = getRuntimeRegistry()[SYNC_QUEUE_COMPONENT]
		expect(entry.kind).toBe('page')
		expect(entry.component).toBe(CnOfflineQueue)
	})

	it('comes and goes with the offline forms, page and menu entry together', () => {
		const base = { menu: [], pages: [{ id: 'report', type: 'form' }] }
		const on = withFormOffline(base, 'report', true)
		const page = on.pages.find((p) => p.id === SYNC_QUEUE_PAGE_ID)
		expect(page.type).toBe('custom')
		expect(page.component).toBe(SYNC_QUEUE_COMPONENT)
		expect(on.menu.map((m) => m.route)).toEqual([SYNC_QUEUE_PAGE_ID])

		const off = withFormOffline(on, 'report', false)
		expect(off.pages.map((p) => p.id)).toEqual(['report'])
		expect(off.menu).toEqual([])
	})
})
