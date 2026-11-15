/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/composables/useConnectedDatabases.js`
 * (data-external-database-sources T01, REQ-BQDB-001 / REQ-BQDB-002).
 */

import { describe, expect, it, vi } from 'vitest'
import { databaseWriteSetting, fetchConnectedDatabases } from '../../src/composables/useConnectedDatabases.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const SOURCES = {
	results: [
		{ id: 7, uuid: 'src-permits', type: 'database', authConfig: { writable: true } },
		{ id: 8, uuid: 'src-archive', type: 'database', authConfig: { writable: false } },
		{ id: 9, uuid: 'src-api', type: 'api' },
	],
}

const REGISTERS = {
	results: [
		{ id: 1, slug: 'permits-db', title: 'Permits database', source: 7 },
		{ id: 2, slug: 'archive-db', source: 'src-archive' },
		{ id: 3, slug: 'publications', source: 9 },
		{ id: 4, slug: 'own' },
	],
}

/**
 * An axios-like client answering the two OpenRegister lists, recording the urls.
 *
 * @param {Array<string>} urls Filled with the urls read.
 * @return {object}
 */
function client(urls = []) {
	return {
		get: async (url) => {
			urls.push(url)
			return { data: url.endsWith('/sources') ? SOURCES : REGISTERS }
		},
	}
}

describe('useConnectedDatabases', () => {
	it('lists the database registers with their write setting, read from both lists', async () => {
		const urls = []
		const databases = await fetchConnectedDatabases(client(urls))

		expect(databases).toEqual([
			{ register: 'permits-db', label: 'Permits database', source: '7', writable: true },
			{ register: 'archive-db', label: 'archive-db', source: 'src-archive', writable: false },
		])
		expect(urls.sort()).toEqual(['/apps/openregister/api/registers', '/apps/openregister/api/sources'])
	})

	it('leaves out a register on a non-database source and one without a source', async () => {
		const registers = (await fetchConnectedDatabases(client())).map((entry) => entry.register)

		expect(registers).not.toContain('publications')
		expect(registers).not.toContain('own')
	})

	it('reads again on every call, so a change of the write setting is followed', async () => {
		const urls = []
		const http = client(urls)
		await fetchConnectedDatabases(http)
		await fetchConnectedDatabases(http)

		expect(urls).toHaveLength(4)
	})

	it('answers an empty list when OpenRegister fails', async () => {
		const failing = { get: async () => { throw new Error('503') } }

		expect(await fetchConnectedDatabases(failing)).toEqual([])
		expect(await fetchConnectedDatabases({ get: async () => ({ data: null }) })).toEqual([])
	})

	it('tells a read-only database from a writable one and from a register that is no database', () => {
		const databases = [
			{ register: 'permits-db', writable: true },
			{ register: 'archive-db', writable: false },
		]

		expect(databaseWriteSetting(databases, 'permits-db')).toEqual({ database: true, writable: true })
		expect(databaseWriteSetting(databases, 'archive-db')).toEqual({ database: true, writable: false })
		expect(databaseWriteSetting(databases, 'own')).toEqual({ database: false, writable: true })
		expect(databaseWriteSetting(undefined, 'own')).toEqual({ database: false, writable: true })
	})
})
