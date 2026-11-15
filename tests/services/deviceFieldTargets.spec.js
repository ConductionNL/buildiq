/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The properties a device field writes, and which of them a target schema
 * still lacks (forms-signature-audio-and-location, REQ-BQSA-002).
 *
 * @spec openspec/changes/forms-signature-audio-and-location/specs/form-device-fields/spec.md#requirement-the-value-lands-in-a-matching-property-req-bqsa-002
 */
import { describe, expect, it } from 'vitest'
import { deviceFieldTargets, missingDeviceTargets } from '../../src/services/deviceFields.js'

describe('deviceFieldTargets', () => {
	it('puts a signature and an audio clip in a file property under the field key', () => {
		expect(deviceFieldTargets({ key: 'handtekening', type: 'signature' })).toEqual({ handtekening: { type: 'file' } })
		expect(deviceFieldTargets({ key: ' spraaknotitie ', type: 'audio' })).toEqual({ spraaknotitie: { type: 'file' } })
	})

	it('writes nothing for a signature without a key', () => {
		expect(deviceFieldTargets({ key: '  ', type: 'signature' })).toEqual({})
	})

	it('puts a location in number properties, the accuracy only when named', () => {
		expect(deviceFieldTargets({ key: 'p', type: 'location', latField: 'lat', lngField: 'lng' })).toEqual({
			lat: { type: 'number' },
			lng: { type: 'number' },
		})
		expect(
			deviceFieldTargets({ key: 'p', type: 'location', latField: 'lat', lngField: 'lng', accuracyField: 'acc' }),
		).toEqual({ lat: { type: 'number' }, lng: { type: 'number' }, acc: { type: 'number' } })
	})

	it('writes nothing for an ordinary field', () => {
		expect(deviceFieldTargets({ key: 'naam', type: 'string' })).toEqual({})
		expect(deviceFieldTargets(null)).toEqual({})
	})
})

describe('missingDeviceTargets', () => {
	const location = { key: 'p', type: 'location', latField: 'lat', lngField: 'lng' }

	it('offers only the properties the schema does not have', () => {
		expect(missingDeviceTargets(location, { lat: { type: 'number' } })).toEqual({ lng: { type: 'number' } })
	})

	it('does not offer a property that exists with another type', () => {
		expect(missingDeviceTargets({ key: 'handtekening', type: 'signature' }, { handtekening: { type: 'string' } })).toEqual({})
	})

	it('offers nothing while the schema is unknown', () => {
		expect(missingDeviceTargets(location, null)).toEqual({})
	})

	it('offers every target to a schema without properties', () => {
		expect(missingDeviceTargets(location, {})).toEqual({ lat: { type: 'number' }, lng: { type: 'number' } })
	})
})
