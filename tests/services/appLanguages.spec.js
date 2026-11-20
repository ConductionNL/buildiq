/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/appLanguages.js`
 * (experience-multilingual-apps, REQ-BQML-001 / REQ-BQML-002).
 */

import { describe, expect, it } from 'vitest'
import {
	appI18n,
	isLanguageCode,
	languageName,
	languagesLosingTranslations,
	missingCounts,
	withLanguages,
	withTranslation,
} from '../../src/services/appLanguages.js'

const dutchApp = {
	pages: [],
	i18n: {
		sourceLanguage: 'nl',
		languages: ['en', 'de'],
		labels: { en: { Vergunningen: 'Permits', Status: 'Status' }, de: { Vergunningen: 'Genehmigungen' } },
	},
}

describe('appLanguages', () => {
	it('recognises Nextcloud language codes and refuses others', () => {
		expect(isLanguageCode('nl')).toBe(true)
		expect(isLanguageCode('pt_BR')).toBe(true)
		expect(isLanguageCode('sr@latin')).toBe(true)
		expect(isLanguageCode('Dutch')).toBe(false)
		expect(isLanguageCode('../x')).toBe(false)
		expect(isLanguageCode(7)).toBe(false)
	})

	it('names a language, falling back to its code', () => {
		expect(languageName('nl', 'en')).toBe('Dutch')
		expect(languageName('zz_ZZ_x', 'en')).toBe('zz_ZZ_x')
	})

	it('normalises a missing i18n block', () => {
		expect(appI18n({}, 'en')).toEqual({ sourceLanguage: 'en', languages: [], labels: {} })
		expect(appI18n(dutchApp).languages).toEqual(['en', 'de'])
	})

	it('adds English to a Dutch app (scenario: a maker adds English)', () => {
		const next = withLanguages({ pages: [] }, { sourceLanguage: 'nl', languages: ['en'] })

		expect(next.i18n).toEqual({ sourceLanguage: 'nl', languages: ['en'], labels: {} })
	})

	it('drops a removed language\'s translations and never translates into the written language', () => {
		const next = withLanguages(dutchApp, { sourceLanguage: 'nl', languages: ['en', 'nl', 'en', 'bogus!'] })

		expect(next.i18n.languages).toEqual(['en'])
		expect(next.i18n.labels).toEqual({ en: { Vergunningen: 'Permits', Status: 'Status' } })
		expect(dutchApp.i18n.labels.de).toEqual({ Vergunningen: 'Genehmigungen' })
	})

	it('removes the block when no language is left at all', () => {
		const next = withLanguages(dutchApp, { sourceLanguage: '', languages: [] })

		expect(next).not.toHaveProperty('i18n')
	})

	it('names the removed languages that still hold translations', () => {
		expect(languagesLosingTranslations(dutchApp, ['en'])).toEqual(['de'])
		expect(languagesLosingTranslations(dutchApp, ['en', 'de'])).toEqual([])
	})

	it('sets, trims and removes a translation, returning the same manifest when nothing changes', () => {
		const set = withTranslation(dutchApp, 'de', 'Status', '  Zustand ')
		expect(set.i18n.labels.de).toEqual({ Vergunningen: 'Genehmigungen', Status: 'Zustand' })

		const removed = withTranslation(set, 'de', 'Vergunningen', '')
		expect(removed.i18n.labels.de).toEqual({ Status: 'Zustand' })

		const emptied = withTranslation(removed, 'de', 'Status', ' ')
		expect(emptied.i18n.labels).not.toHaveProperty('de')

		expect(withTranslation(dutchApp, 'en', 'Vergunningen', 'Permits')).toBe(dutchApp)
		expect(withTranslation(dutchApp, 'fr', 'Vergunningen', 'Permis')).toBe(dutchApp)
		expect(withTranslation(dutchApp, 'de', 'Nieuw', '')).toBe(dutchApp)
	})

	it('counts what each language is missing (scenario: 4 of 64 left)', () => {
		const texts = Array.from({ length: 64 }, (_, i) => `Label ${i}`)
		const labels = Object.fromEntries(texts.slice(0, 60).map((text) => [text, `EN ${text}`]))
		const manifest = { i18n: { sourceLanguage: 'nl', languages: ['en'], labels: { en: labels } } }

		expect(missingCounts(manifest, texts)).toEqual({ en: 4 })
		expect(missingCounts(dutchApp, ['Vergunningen', 'Status', 'Nieuw'])).toEqual({ en: 1, de: 2 })
	})
})
