/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The English browser catalogue carries only what changes a string.
 *
 * Every page loads l10n/en.js for an English reader. An entry that maps a
 * string to itself changes nothing, because a missing key already reads as
 * the key, yet it cost 253 KB on every page (ADR-124 speed budget).
 * scripts/build-l10n-js.js now drops those for English only; l10n/en.json
 * stays complete.
 */

import fs from 'fs'
import { createRequire } from 'module'
import path from 'path'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const { browserEntries } = require('../../scripts/build-l10n-js.js')
const L10N = path.resolve(__dirname, '../../l10n')

describe('browserEntries', () => {
	it('drops the English entries that map a string to itself', () => {
		const plural = ['%n check', '%n checks']
		expect(browserEntries('en', { Save: 'Save', '_%n check_::_%n checks_': plural }))
			.toEqual({ '_%n check_::_%n checks_': plural })
	})

	it('drops them for a regional English too', () => {
		expect(browserEntries('en_US', { Save: 'Save' })).toEqual({})
	})

	it('keeps every entry of another language, cognates included', () => {
		expect(browserEntries('nl', { Save: 'Opslaan', Status: 'Status' }))
			.toEqual({ Save: 'Opslaan', Status: 'Status' })
	})
})

describe('the shipped catalogues', () => {
	it('keep l10n/en.js small and l10n/en.json complete', () => {
		const enJson = JSON.parse(fs.readFileSync(path.join(L10N, 'en.json'), 'utf8')).translations
		expect(fs.statSync(path.join(L10N, 'en.js')).size).toBeLessThan(16 * 1024)
		expect(Object.keys(enJson).length).toBeGreaterThan(2000)
	})
})
