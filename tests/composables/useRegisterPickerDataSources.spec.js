/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the bounded data-source fan-out (pages-editor-data-sources).
 *
 * Before this change `fetchDataSources()` requested schemas for EVERY register on
 * the instance — one request each, dozens on a populated instance — even though
 * the pages editor only ever needs the app's own registers. These cover the
 * bounded `scope` argument and the `registerScope()` helper that builds it.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('@nextcloud/auth', () => ({
	getRequestToken: () => 'test-token',
}))

import {
	registerScope,
	useRegisterPicker,
} from '../../src/composables/useRegisterPicker.js'

const REGISTERS = [
	{
		slug: 'openbuild-cowboy-production',
		title: 'Buildiq — cowboy (production)',
	},
	{
		slug: 'openbuild-cowboy-development',
		title: 'Buildiq — cowboy (development)',
	},
	{ slug: 'unrelated-app', title: 'Some other app' },
	{ slug: 'another-one', title: 'Yet another' },
]

const SCHEMAS = {
	'openbuild-cowboy-production': [
		{ slug: 'cow', title: 'Cow', properties: { name: {} } },
		{ slug: 'barn', title: 'Barn', properties: { name: {}, size: {} } },
	],
}

/**
 * Intercept the composable's raw fetch calls and record which registers had
 * their schemas requested.
 *
 * @return {{schemaCalls: string[]}} - registers whose schemas were fetched.
 */
function stubFetch() {
	const schemaCalls = []
	global.fetch = vi.fn(async (url) => {
		const schemaMatch = url.match(/\/registers\/([^/]+)\/schemas$/)
		if (schemaMatch) {
			const register = decodeURIComponent(schemaMatch[1])
			schemaCalls.push(register)
			return {
				ok: true,
				json: async () => ({ results: SCHEMAS[register] || [] }),
			}
		}
		return { ok: true, json: async () => ({ results: REGISTERS }) }
	})
	return { schemaCalls }
}

describe('registerScope', () => {
	it('collects the per-app register, page registers and dataRegisters, deduped', () => {
		const manifest = {
			pages: [
				{ config: { register: 'openbuild-cowboy-production' } },
				{ config: { register: 'shared-crm' } },
				{ config: {} },
				{},
			],
		}
		const scope = registerScope('openbuild-cowboy-production', manifest, [
			{ register: 'shared-crm' },
			{ register: 'billing' },
		])
		expect(scope.sort()).toEqual([
			'billing',
			'openbuild-cowboy-production',
			'shared-crm',
		])
	})

	it('returns just the per-app register for a fresh app with no data pages', () => {
		expect(registerScope('openbuild-cowboy-production', { pages: [] })).toEqual([
			'openbuild-cowboy-production',
		])
	})

	it('tolerates a null manifest', () => {
		expect(registerScope('openbuild-cowboy-production', null)).toEqual([
			'openbuild-cowboy-production',
		])
	})
})

describe('fetchDataSources — bounded fan-out', () => {
	let originalFetch

	beforeEach(() => {
		originalFetch = global.fetch
	})
	afterEach(() => {
		global.fetch = originalFetch
	})

	it('only fetches schemas for registers in scope', async () => {
		const { schemaCalls } = stubFetch()
		const { fetchDataSources } = useRegisterPicker({ appSlug: 'cowboy' })

		const result = await fetchDataSources(['openbuild-cowboy-production'])

		expect(schemaCalls).toEqual(['openbuild-cowboy-production'])
		expect(result.registers).toHaveLength(1)
		expect(result.registers[0].value).toBe('openbuild-cowboy-production')
		expect(result.registers[0].schemas.map((s) => s.value)).toEqual([
			'cow',
			'barn',
		])
		expect(result.registers[0].schemas[1].columns).toEqual(['name', 'size'])
	})

	it('without a scope still fans out over every register (unchanged default)', async () => {
		const { schemaCalls } = stubFetch()
		const { fetchDataSources } = useRegisterPicker({ appSlug: 'cowboy' })

		const result = await fetchDataSources()

		expect(schemaCalls).toHaveLength(REGISTERS.length)
		expect(result.registers).toHaveLength(REGISTERS.length)
	})

	it('an empty scope array falls back to the unbounded default', async () => {
		const { schemaCalls } = stubFetch()
		const { fetchDataSources } = useRegisterPicker({ appSlug: 'cowboy' })

		await fetchDataSources([])

		expect(schemaCalls).toHaveLength(REGISTERS.length)
	})
})
