/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/appTranslate.js`
 * (experience-multilingual-apps, REQ-BQML-003, task T05).
 */

import { describe, expect, it, vi } from 'vitest'
import { createAppTranslate } from '../../src/services/appTranslate.js'

const buildiqCatalogue = { Delete: 'Verwijderen', 'Hello {name}': 'Hallo {name}' }
const buildiqT = vi.fn((key, vars) => {
	const text = buildiqCatalogue[key] || key
	return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
})

const translated = {
	menu: [{ label: 'Delete' }],
	pages: [{ title: 'Permits for {name}' }],
	i18n: { sourceLanguage: 'en', languages: ['nl'], labels: { nl: {} } },
}

describe('createAppTranslate', () => {
	it('answers a maker label as written for an app with languages', () => {
		const translate = createAppTranslate(() => translated, buildiqT)

		expect(translate('Delete')).toBe('Delete')
		expect(translate('Permits for {name}', { name: 'Ada' })).toBe('Permits for Ada')
	})

	it('still answers buildiq\'s own strings from buildiq\'s catalogue', () => {
		const translate = createAppTranslate(() => translated, buildiqT)

		expect(translate('Hello {name}', { name: 'Ada' })).toBe('Hallo Ada')
	})

	it('keeps the old lookup for an app without languages', () => {
		const legacy = { menu: [{ label: 'Delete' }], pages: [] }
		const translate = createAppTranslate(() => legacy, buildiqT)

		expect(translate('Delete')).toBe('Verwijderen')
	})

	it('sees a label edited in place once the cache has aged', () => {
		let clock = 0
		const manifest = { menu: [{ label: 'Old' }], pages: [], i18n: { sourceLanguage: 'en', languages: [], labels: {} } }
		const translate = createAppTranslate(() => manifest, buildiqT, { maxAgeMs: 100, now: () => clock })

		expect(translate('Delete')).toBe('Verwijderen')
		manifest.menu[0].label = 'Delete'
		expect(translate('Delete')).toBe('Verwijderen')
		clock = 200
		expect(translate('Delete')).toBe('Delete')
	})
})
