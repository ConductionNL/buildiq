/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/manifestValidation/i18n.js`
 * (experience-multilingual-apps, REQ-BQML-001, task T07).
 */

import { describe, expect, it } from 'vitest'
import { validateI18n } from '../../src/services/manifestValidation/i18n.js'

describe('validateI18n', () => {
	it('accepts a manifest without a block and a block of Nextcloud languages', () => {
		expect(validateI18n({})).toEqual([])
		expect(validateI18n(null)).toEqual([])
		expect(validateI18n({
			i18n: { sourceLanguage: 'nl', languages: ['en', 'pt_BR'], labels: { en: { A: 'B' } } },
		})).toEqual([])
	})

	it('refuses codes Nextcloud does not know', () => {
		expect(validateI18n({
			i18n: { sourceLanguage: 'Dutch', languages: ['en', 'english'], labels: {} },
		})).toEqual([
			'/i18n/sourceLanguage: buildiq.i18n.error.unknown-language',
			'/i18n/languages/1: buildiq.i18n.error.unknown-language',
		])
	})

	it('leaves an undeclared but valid label language to the library check', () => {
		expect(validateI18n({
			i18n: { sourceLanguage: 'nl', languages: ['en'], labels: { de: {}, 'x y': {} } },
		})).toEqual(['/i18n/labels/x y: buildiq.i18n.error.unknown-language'])
	})
})
