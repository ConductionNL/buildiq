/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Dutch field formats a form field can carry (form-dutch-field-formats D1):
 * their stored names, labels and hints for the designer's "Format" select, and
 * the client check. The rule tables come from lib/Settings/dutch-formats.json,
 * which the server's DutchFormatValidator reads too, so the form and the save
 * check cannot drift.
 *
 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-the-form-designer-offers-dutch-formats-for-a-field-req-bqdf-001
 */
import { translate as t } from '@nextcloud/l10n'
import rules from '../../lib/Settings/dutch-formats.json'

/** The stored format names, in the order the designer lists them. */
export const DUTCH_FORMATS = Object.freeze([...rules.formats])

/**
 * Each format's label and one-line hint, translated.
 *
 * @return {Array<{id: string, label: string, hint: string}>} The options.
 */
export function dutchFormatOptions() {
	return [
		{ id: 'bsn', label: t('buildiq', 'BSN'), hint: t('buildiq', 'Checks the eleven test') },
		{ id: 'iban', label: t('buildiq', 'IBAN'), hint: t('buildiq', 'Checks the check digits') },
		{ id: 'nl-licence-plate', label: t('buildiq', 'Licence plate'), hint: t('buildiq', 'With or without dashes') },
		{ id: 'phone-nl', label: t('buildiq', 'Phone number (Dutch)'), hint: t('buildiq', 'Ten digits, starting with 0 or +31') },
		{ id: 'phone-international', label: t('buildiq', 'Phone number (international)'), hint: t('buildiq', '+ and the country code') },
		{ id: 'postcode', label: t('buildiq', 'Postcode'), hint: t('buildiq', '1234 AB, the space is optional') },
		{ id: 'kvk', label: t('buildiq', 'KvK number'), hint: t('buildiq', 'Eight digits') },
		{ id: 'kvk-branch', label: t('buildiq', 'Branch number'), hint: t('buildiq', 'Twelve digits') },
	]
}

/**
 * Whether a format name is one of the Dutch formats.
 *
 * @param {string} format The name.
 * @return {boolean}
 */
export function isDutchFormat(format) {
	return DUTCH_FORMATS.includes(format)
}

/**
 * The message for a value that fails a format, translated.
 *
 * @param {string} format The format name.
 * @return {string} The message.
 */
export function dutchFormatMessage(format) {
	const messages = {
		bsn: t('buildiq', 'This BSN is not valid. Check the nine digits.'),
		iban: t('buildiq', 'This IBAN is not valid. Check the digits.'),
		'nl-licence-plate': t('buildiq', 'This is not a Dutch licence plate.'),
		'phone-nl': t('buildiq', 'Enter a Dutch phone number of ten digits.'),
		'phone-international': t('buildiq', 'Enter the number with + and the country code.'),
		postcode: t('buildiq', 'Enter a postcode like 1234 AB.'),
		kvk: t('buildiq', 'A KvK number has eight digits.'),
		'kvk-branch': t('buildiq', 'A branch number has twelve digits.'),
	}
	return messages[format] || t('buildiq', 'This value does not have the expected format.')
}

const digits = (value, length) => (new RegExp(`^\\d{${length}}$`).test(value) ? value : null)

/**
 * A BSN: nine digits, not all zero, passing the elfproef.
 *
 * @param {string} value The value.
 * @return {string|null}
 */
function bsn(value) {
	if (digits(value, 9) === null || value === '000000000') {
		return null
	}
	let sum = -Number(value[8])
	for (let index = 0; index < 8; index++) {
		sum += (9 - index) * Number(value[index])
	}
	return sum % 11 === 0 ? value : null
}

/**
 * The ISO 7064 mod-97 remainder of an IBAN in capitals.
 *
 * @param {string} iban The IBAN.
 * @return {number}
 */
function mod97(iban) {
	let remainder = 0
	for (const char of iban.slice(4) + iban.slice(0, 4)) {
		const number = /[A-Z]/.test(char) ? String(char.charCodeAt(0) - 55) : char
		remainder = Number(String(remainder) + number) % 97
	}
	return remainder
}

/**
 * An IBAN without spaces, in capitals, of its country's length, passing mod 97.
 *
 * @param {string} value The value.
 * @return {string|null}
 */
function iban(value) {
	const normalised = value.replace(/\s+/g, '').toUpperCase()
	if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(normalised)) {
		return null
	}
	const length = rules.ibanLengths[normalised.slice(0, 2)]
	if (length !== undefined && normalised.length !== length) {
		return null
	}
	return mod97(normalised) === 1 ? normalised : null
}

/**
 * A Dutch licence plate in capitals without dashes, fitting an RDW side code.
 *
 * @param {string} value The value.
 * @return {string|null}
 */
function plate(value) {
	const normalised = value.replace(/[\s-]+/g, '').toUpperCase()
	if (!/^[A-Z0-9]{6}$/.test(normalised)) {
		return null
	}
	const shape = normalised.replace(/[A-Z]/g, 'L').replace(/\d/g, 'D')
	const sideCode = rules.plateSideCodes.indexOf(shape) + 1
	if (sideCode === 0) {
		return null
	}
	const forbidden = [...rules.plateForbiddenLetters]
	if (sideCode >= rules.plateVowelFreeFrom && forbidden.some((letter) => normalised.includes(letter))) {
		return null
	}
	return normalised
}

/**
 * A phone number without spaces, dashes and brackets, matching the pattern.
 *
 * @param {string} value The value.
 * @param {RegExp} pattern The pattern.
 * @return {string|null}
 */
function phone(value, pattern) {
	const number = value.replace(/[\s\-()]+/g, '')
	return pattern.test(number) ? number : null
}

/**
 * A Dutch postcode in capitals without the space.
 *
 * @param {string} value The value.
 * @return {string|null}
 */
function postcode(value) {
	const match = /^([1-9]\d{3})\s?([A-Za-z]{2})$/.exec(value)
	if (match === null) {
		return null
	}
	const letters = match[2].toUpperCase()
	return rules.postcodeForbiddenLetters.includes(letters) ? null : match[1] + letters
}

const CHECKS = {
	bsn,
	iban,
	'nl-licence-plate': plate,
	'phone-nl': (value) => phone(value, /^(0[1-9]\d{8}|(\+|00)31[1-9]\d{8})$/),
	'phone-international': (value) => phone(value, /^\+[1-9]\d{6,14}$/),
	postcode,
	kvk: (value) => digits(value, 8),
	'kvk-branch': (value) => digits(value, 12),
}

/**
 * The value in its stored form, or null when it fails the format; the same
 * rules the server applies on save.
 *
 * @param {string} format The format name.
 * @param {string} value The value as entered.
 * @return {string|null} The normalised value; null when it fails or the format is unknown.
 */
export function normaliseDutchFormat(format, value) {
	const check = CHECKS[format]
	return check ? check(String(value ?? '').trim()) : null
}
