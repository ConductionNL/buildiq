/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The registry providers a field can take its value from, and the helpers the
 * field editor and the designer share (data-registry-backed-field-option).
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import {
	listPropertySources,
	propertySourceOf,
	sourceFromProperty,
	sourceRefusalsByField,
	sourceSummary,
} from '../../src/services/propertySources.js'

const KVK = { id: 'kvk', label: 'KvK organisation', identifier: 'kvkNummer' }

describe('listPropertySources', () => {
	it('asks integriq for its providers and returns them', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: { results: [KVK, { label: 'no id' }] } }) }

		const list = await listPropertySources({ client })

		expect(client.get).toHaveBeenCalledTimes(1)
		expect(client.get.mock.calls[0][0]).toMatch(/\/index\.php\/apps\/integriq\/api\/property-sources$/)
		expect(list).toEqual({ available: true, results: [KVK] })
	})

	it('is unavailable on a 404, a network error or an answer without a list', async () => {
		const notFound = Object.assign(new Error('Request failed with status code 404'), { response: { status: 404 } })
		for (const client of [
			{ get: vi.fn().mockRejectedValue(notFound) },
			{ get: vi.fn().mockRejectedValue(new Error('Network Error')) },
			{ get: vi.fn().mockResolvedValue({ data: '<html>' }) },
		]) {
			expect(await listPropertySources({ client })).toEqual({ available: false, results: [] })
		}
	})
})

describe('reading and writing a declaration', () => {
	it('reads provider, mode and a copy of config; nothing for a property without one', () => {
		const config = { x: 1 }
		const source = sourceFromProperty({
			type: 'string',
			'x-openregister-property-source': { provider: 'bag', mode: 'default', config },
		})

		expect(source).toEqual({ provider: 'bag', mode: 'default', config: { x: 1 } })
		expect(source.config).not.toBe(config)
		expect(sourceFromProperty({ type: 'string' })).toBeNull()
		expect(sourceFromProperty({ 'x-openregister-property-source': 'kvk' })).toBeNull()
	})

	it('writes config {} for a new declaration and keeps the mode it was given', () => {
		expect(propertySourceOf({ provider: 'kvk', mode: 'live' })).toEqual({ provider: 'kvk', mode: 'live', config: {} })
		expect(propertySourceOf({ provider: 'bag', mode: 'default', config: { x: 1 } })).toEqual({
			provider: 'bag',
			mode: 'default',
			config: { x: 1 },
		})
		expect(propertySourceOf(null)).toBeNull()
	})
})

describe('sourceSummary', () => {
	it('names the provider label and the mode, or the id when integriq did not publish it', () => {
		expect(sourceSummary({ provider: 'kvk', mode: 'live' }, [KVK])).toBe('KvK organisation, live')
		expect(sourceSummary({ provider: 'kvk', mode: 'default' }, [KVK])).toBe('KvK organisation, starting value')
		expect(sourceSummary({ provider: 'bag', mode: 'default' }, [])).toBe('bag, starting value')
		expect(sourceSummary(null, [KVK])).toBe('')
		expect(sourceSummary({ provider: '', mode: 'live' }, [KVK])).toBe('')
	})
})

describe('sourceRefusalsByField', () => {
	it('maps a refusal naming a property path onto that field only', () => {
		const message = "'x-openregister-property-source' at 'properties.kvkNummer' names the provider 'kvk!', which is not a provider id."
		const err = { status: 400, fields: [{ message }], details: 'Invalid schema' }

		expect(sourceRefusalsByField(err, ['naam', 'kvkNummer', 'source'])).toEqual({ kvkNummer: [message] })
	})

	it('reads the details text too, and ignores refusals about something else', () => {
		const message = "'x-openregister-property-source' at 'adres' has mode 'sometimes'. It must be one of: live, default."

		expect(sourceRefusalsByField({ details: message }, ['adres'])).toEqual({ adres: [message] })
		expect(sourceRefusalsByField({ details: "Calculation 'adres' refers to itself" }, ['adres'])).toEqual({})
		expect(sourceRefusalsByField(null, ['adres'])).toEqual({})
	})
})
