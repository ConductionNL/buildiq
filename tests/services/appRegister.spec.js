/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/appRegister.js: the app's register is read off
 * its ApplicationVersion, never rebuilt from the slug.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const getMock = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => getMock(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { fetchAppRegister, fetchProductionRegister, versionRegister } =
	await import('../../src/services/appRegister.js')

const VERSIONS = [
	{
		slug: 'development',
		register: 'openbuild-shop-development',
		'@self': { id: 'ver-dev' },
	},
	{
		slug: 'production',
		register: 'openbuild-shop-production',
		'@self': { id: 'ver-prod' },
	},
]

function route(table) {
	getMock.mockImplementation(async (url, options) => {
		const key = options && options.params ? `${url}?slug` : url
		if (!(key in table)) {
			throw Object.assign(new Error('404'), { response: { status: 404 } })
		}
		return { data: table[key] }
	})
}

describe('appRegister', () => {
	beforeEach(() => {
		getMock.mockReset()
	})

	it('reads the register off a version record', () => {
		expect(versionRegister(VERSIONS[0])).toBe('openbuild-shop-development')
		expect(versionRegister(null)).toBe('')
		expect(versionRegister({ register: 7 })).toBe('')
	})

	it('finds the production register by the version uuid', async () => {
		route({ '/apps/buildiq/api/applications/shop/versions': VERSIONS })
		await expect(fetchProductionRegister('shop', 'ver-prod')).resolves.toBe(
			'openbuild-shop-production',
		)
	})

	it('uses an embedded production version without a request', async () => {
		await expect(
			fetchProductionRegister('shop', { register: 'openbuild-shop-live' }),
		).resolves.toBe('openbuild-shop-live')
		expect(getMock).not.toHaveBeenCalled()
	})

	it('answers empty when the production version is unknown', async () => {
		route({ '/apps/buildiq/api/applications/shop/versions': VERSIONS })
		await expect(fetchProductionRegister('shop', 'ver-gone')).resolves.toBe('')
		await expect(fetchProductionRegister('shop', null)).resolves.toBe('')
	})

	it('resolves the named version when ?_version= is set', async () => {
		route({
			'/apps/buildiq/api/applications/shop/versions/development': VERSIONS[0],
		})
		await expect(fetchAppRegister('shop', 'development')).resolves.toBe(
			'openbuild-shop-development',
		)
	})

	it('resolves the production version when no version is named', async () => {
		route({
			'/apps/openregister/api/objects/buildiq/built-app?slug': {
				results: [{ slug: 'shop', productionVersion: 'ver-prod' }],
			},
			'/apps/buildiq/api/applications/shop/versions': VERSIONS,
		})
		await expect(fetchAppRegister('shop')).resolves.toBe(
			'openbuild-shop-production',
		)
	})

	it('never answers the bare openbuild-{slug} name', async () => {
		route({})
		await expect(fetchAppRegister('shop')).resolves.toBe('')
		await expect(fetchAppRegister('shop', 'development')).resolves.toBe('')
	})

	it('reads the uuid off @self, uuid or id of an embedded record without a register', async () => {
		const list = [
			{ uuid: 'by-uuid', register: 'r-uuid' },
			{ id: 7, register: 'r-id' },
			{ '@self': { id: 'by-self' }, uuid: 'other', register: 'r-self' },
		]
		route({ '/apps/buildiq/api/applications/shop/versions': { results: list } })
		await expect(fetchProductionRegister('shop', { uuid: 'by-uuid' })).resolves.toBe('r-uuid')
		await expect(fetchProductionRegister('shop', { id: 7 })).resolves.toBe('r-id')
		await expect(
			fetchProductionRegister('shop', { '@self': { id: 'by-self' } }),
		).resolves.toBe('r-self')
		await expect(fetchProductionRegister('shop', {})).resolves.toBe('')
		expect(getMock).toHaveBeenCalledTimes(3)
	})

	it('answers empty without a request when the slug is missing', async () => {
		await expect(fetchProductionRegister('', 'ver-prod')).resolves.toBe('')
		await expect(fetchAppRegister('')).resolves.toBe('')
		await expect(fetchAppRegister(undefined, 'development')).resolves.toBe('')
		expect(getMock).not.toHaveBeenCalled()
	})

	it('treats a versions answer that is neither a list nor results as empty', async () => {
		route({ '/apps/buildiq/api/applications/shop/versions': { other: VERSIONS } })
		await expect(fetchProductionRegister('shop', 'ver-prod')).resolves.toBe('')
		route({ '/apps/buildiq/api/applications/shop/versions': null })
		await expect(fetchProductionRegister('shop', 'ver-prod')).resolves.toBe('')
		route({})
		await expect(fetchProductionRegister('shop', 'ver-prod')).resolves.toBe('')
	})

	it('encodes the slugs and answers empty when the app is not listed', async () => {
		route({
			'/apps/buildiq/api/applications/a%20b/versions/v%2F1': { register: 'r-v1' },
			'/apps/openregister/api/objects/buildiq/built-app?slug': {
				results: [null, { slug: 'other', productionVersion: 'ver-prod' }],
			},
		})
		await expect(fetchAppRegister('a b', 'v/1')).resolves.toBe('r-v1')
		await expect(fetchAppRegister('a b')).resolves.toBe('')
		route({ '/apps/openregister/api/objects/buildiq/built-app?slug': null })
		await expect(fetchAppRegister('shop')).resolves.toBe('')
		expect(getMock).toHaveBeenLastCalledWith(
			'/apps/openregister/api/objects/buildiq/built-app',
			{ params: { slug: 'shop', _limit: 1 } },
		)
	})

	it('uses an embedded production version from the built-app record', async () => {
		route({
			'/apps/openregister/api/objects/buildiq/built-app?slug': {
				results: [{ slug: 'shop', productionVersion: { register: 'r-embedded' } }],
			},
		})
		await expect(fetchAppRegister('shop')).resolves.toBe('r-embedded')
		expect(getMock).toHaveBeenCalledTimes(1)
	})
})
