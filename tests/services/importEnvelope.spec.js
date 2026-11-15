/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the shared import-envelope helpers: parsing the input and
 * recognising an envelope by kind and part.
 */
import { describe, expect, it, vi } from 'vitest'
import { isEnvelope, parsedInput } from '../../src/services/importEnvelope.js'

const envelope = { kind: 'buildiq.form', part: 'form' }

describe('parsedInput', () => {
	it('parses a JSON string', () => {
		expect(parsedInput('{"kind":"x","n":1}', vi.fn())).toEqual({ kind: 'x', n: 1 })
	})

	it('passes anything that is not a string through untouched', () => {
		const data = { kind: 'x' }
		const invalid = vi.fn()

		expect(parsedInput(data, invalid)).toBe(data)
		expect(parsedInput(null, invalid)).toBeNull()
		expect(invalid).not.toHaveBeenCalled()
	})

	it('throws the caller\'s own error for invalid JSON', () => {
		const error = new Error('That is not a valid export file.')
		const invalid = vi.fn(() => error)

		expect(() => parsedInput('{not json', invalid)).toThrow(error)
		expect(invalid).toHaveBeenCalledTimes(1)
	})
})

describe('isEnvelope', () => {
	it('recognises data of the right kind carrying its part as an object', () => {
		expect(isEnvelope({ kind: 'buildiq.form', form: { title: 'T' } }, envelope)).toBe(true)
		expect(isEnvelope({ kind: 'buildiq.form', form: [] }, envelope)).toBe(true)
	})

	it.each([
		['null', null],
		['a string', 'buildiq.form'],
		['another kind', { kind: 'buildiq.block', form: {} }],
		['no part', { kind: 'buildiq.form' }],
		['a scalar part', { kind: 'buildiq.form', form: 'T' }],
		['an empty part', { kind: 'buildiq.form', form: 0 }],
	])('rejects %s', (_label, data) => {
		expect(isEnvelope(data, envelope)).toBe(false)
	})
})
