/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the shared connector-synchronization loader.
 *
 * Both cases here are silent failures on the old code: the request went to the
 * `openconnector` register, which answers 404 on a renamed instance, and it
 * paged with `limit`, which OpenRegister's objects endpoint reads as a filter
 * on a property called `limit` and so matches nothing. Either one leaves the
 * picker empty with no error anywhere.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))

import axios from '@nextcloud/axios'
import {
	connectorSynchronizationsUrl,
	fetchConnectorSynchronizations,
} from '../../src/services/connectorSynchronizations.js'

beforeEach(() => {
	axios.get.mockReset()
	axios.get.mockResolvedValue({ data: { results: [] } })
})

afterEach(() => {
	delete globalThis.OC
})

describe('connectorSynchronizations', () => {
	it('reads the register the connector app is installed under', () => {
		globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
		expect(connectorSynchronizationsUrl()).toBe(
			'/apps/openregister/api/objects/integriq/synchronization',
		)
	})

	it('reads the pre-rename register on an instance still on the old id', () => {
		globalThis.OC = { appswebroots: { openconnector: '/apps/openconnector' } }
		expect(connectorSynchronizationsUrl()).toBe(
			'/apps/openregister/api/objects/openconnector/synchronization',
		)
	})

	it('pages with _limit, the key OpenRegister reads as a page size', async () => {
		globalThis.OC = { appswebroots: { integriq: '/apps/integriq' } }
		await fetchConnectorSynchronizations()
		const [url, config] = axios.get.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/integriq/synchronization')
		expect(config.params).toEqual({ _limit: 500 })
		expect(config.params.limit).toBeUndefined()
	})

	it('maps rows to picker options and drops rows without an id', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				results: [
					{ id: 's1', name: 'BRP sync' },
					{ uuid: 's2', title: 'KVK sync' },
					{ name: 'no id at all' },
				],
			},
		})
		expect(await fetchConnectorSynchronizations()).toEqual([
			{ id: 's1', label: 'BRP sync' },
			{ id: 's2', label: 'KVK sync' },
		])
	})

	it('reads a bare array as well as a results envelope', async () => {
		axios.get.mockResolvedValueOnce({ data: [{ id: 's3', name: 'Direct' }] })
		expect(await fetchConnectorSynchronizations()).toEqual([
			{ id: 's3', label: 'Direct' },
		])
	})
})
