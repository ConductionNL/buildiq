/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for services/dutchFormats.js: the client check accepts and
 * refuses the same samples as the server's DutchFormatValidatorTest.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const { DUTCH_FORMATS, dutchFormatMessage, dutchFormatOptions, isDutchFormat, normaliseDutchFormat } =
	await import('../../src/services/dutchFormats.js')

describe('dutchFormats', () => {
	it.each([
		['bsn', '111222333', '111222333'],
		['iban', 'nl91 abna 0417 1643 00', 'NL91ABNA0417164300'],
		['iban', 'DE89370400440532013000', 'DE89370400440532013000'],
		['nl-licence-plate', 'gz-482-k', 'GZ482K'],
		['nl-licence-plate', 'AB-12-34', 'AB1234'],
		['phone-nl', '06-12 34 56 78', '0612345678'],
		['phone-nl', '+31 20 123 4567', '+31201234567'],
		['phone-international', '+44 (20) 7946 0958', '+442079460958'],
		['postcode', '1234 ab', '1234AB'],
		['kvk', '12345678', '12345678'],
		['kvk-branch', '000012345678', '000012345678'],
	])('accepts %s %s as %s', (format, value, expected) => {
		expect(normaliseDutchFormat(format, value)).toBe(expected)
	})

	it.each([
		['bsn', '123456789'],
		['bsn', '000000000'],
		['iban', 'NL91ABNA0417164301'],
		['iban', 'NL91ABNA04171643000'],
		['nl-licence-plate', 'GA-482-K'],
		['nl-licence-plate', 'GZ4K82'],
		['phone-nl', '061234567'],
		['phone-international', '442079460958'],
		['postcode', '0123 AB'],
		['postcode', '1234 SS'],
		['kvk', '123456789'],
		['kvk-branch', '12345678901'],
		['email', 'a@b.nl'],
	])('refuses %s %s', (format, value) => {
		expect(normaliseDutchFormat(format, value)).toBeNull()
	})

	it('lists the eight formats with a label and hint each, in the stored order', () => {
		expect(DUTCH_FORMATS).toEqual(['bsn', 'iban', 'nl-licence-plate', 'phone-nl', 'phone-international', 'postcode', 'kvk', 'kvk-branch'])
		const options = dutchFormatOptions()
		expect(options.map((option) => option.id)).toEqual(DUTCH_FORMATS)
		expect(options.every((option) => option.label !== '' && option.hint !== '')).toBe(true)
		expect(isDutchFormat('iban')).toBe(true)
		expect(isDutchFormat('email')).toBe(false)
	})

	it('names the IBAN message the server uses', () => {
		expect(dutchFormatMessage('iban')).toBe('This IBAN is not valid. Check the digits.')
		expect(dutchFormatMessage('unknown')).toBe('This value does not have the expected format.')
	})
})
