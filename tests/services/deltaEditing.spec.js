/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for src/services/deltaEditing.js (app-delta-override D6).
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn() },
}))

import { mergeManifestDelta } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import {
	deltaOf,
	deltaToSave,
	editorManifest,
	fetchDeltaBase,
	isDeltaVersion,
} from '../../src/services/deltaEditing.js'

const BASE = {
	version: '1.0.0',
	menu: [{ id: 'home', label: 'Home' }],
	pages: [{ id: 'home', title: 'Home' }, { id: 'list', title: 'List' }],
}

describe('deltaEditing', () => {
	afterEach(() => {
		axios.get.mockReset()
	})

	it('tells a version that extends a base from a blob version', () => {
		expect(isDeltaVersion({ baseRef: { kind: 'template', id: 'permits' } })).toBe(true)
		expect(isDeltaVersion({ baseRef: { kind: 'template' } })).toBe(false)
		expect(isDeltaVersion({ manifest: {} })).toBe(false)
		expect(isDeltaVersion(null)).toBe(false)
	})

	it('reads the stored delta, {} when there is none', () => {
		expect(deltaOf({ manifestDelta: { pages: [] } })).toEqual({ pages: [] })
		expect(deltaOf({ manifestDelta: [] })).toEqual({})
		expect(deltaOf(null)).toEqual({})
	})

	it('asks the base endpoint for production or for the named version', async () => {
		axios.get.mockResolvedValueOnce({ data: { base: BASE, orphanedDeltaPaths: ['pages/gone'] } })
		expect(await fetchDeltaBase('petstore', '')).toEqual({ base: BASE, orphanedDeltaPaths: ['pages/gone'] })
		expect(axios.get).toHaveBeenLastCalledWith('/apps/buildiq/api/applications/petstore/manifest-base', { params: {} })

		axios.get.mockResolvedValueOnce({ data: { base: null } })
		expect(await fetchDeltaBase('petstore', 'next')).toEqual({ base: null, orphanedDeltaPaths: [] })
		expect(axios.get).toHaveBeenLastCalledWith('/apps/buildiq/api/applications/petstore/manifest-base', { params: { _version: 'next' } })
	})

	it('saves only what an edit changed, and base plus that delta is the edited manifest', () => {
		const shown = editorManifest(BASE, { pages: [{ id: 'home', title: 'Start' }] })
		expect(shown.pages.map((page) => page.title)).toEqual(['Start', 'List'])

		const edited = { ...shown, pages: [...shown.pages, { id: 'new', title: 'New' }] }
		const delta = deltaToSave(BASE, edited)

		expect(delta.menu).toBeUndefined()
		expect(delta.pages).toEqual([{ id: 'home', title: 'Start' }, { id: 'new', title: 'New' }])
		expect(mergeManifestDelta(BASE, delta).manifest).toEqual(edited)
		expect(deltaToSave(BASE, BASE)).toEqual({})
	})
})
