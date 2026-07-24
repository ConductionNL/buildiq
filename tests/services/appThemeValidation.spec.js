/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side runtime.appTheme validation.
 *
 * Spec: app-theming (requirement "appTheme manifest block declares logo,
 * colors and header style").
 */
import { describe, it, expect } from 'vitest'
import { validateAppTheme, HEADER_STYLES } from '../../src/services/manifestValidation/appTheme.js'

const validTheme = {
	logoRef: null,
	primaryColor: '#1D4ED8',
	secondaryColor: '#0F172A',
	accentColor: '#F59E0B',
	headerStyle: 'branded',
}

const withAppTheme = (theme) => ({ runtime: { appTheme: theme } })

describe('validateAppTheme', () => {
	it('passes a valid appTheme', () => {
		expect(validateAppTheme(withAppTheme(validTheme))).toEqual([])
	})

	it('returns nothing when runtime.appTheme is absent (themeless app)', () => {
		expect(validateAppTheme({ runtime: {} })).toEqual([])
		expect(validateAppTheme({})).toEqual([])
	})

	it('accepts a dedicated logoRef object', () => {
		expect(validateAppTheme(withAppTheme({ ...validTheme, logoRef: { ref: 'theme-logo.svg' } }))).toEqual([])
	})

	it('rejects a logoRef with an unknown key', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, logoRef: { ref: 'x.svg', extra: 1 } }))
		expect(errs.some((e) => e.includes('logoRef') && e.includes('unknown-key'))).toBe(true)
	})

	it('rejects a logoRef missing ref', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, logoRef: {} }))
		expect(errs.some((e) => e.includes('logo-ref-required'))).toBe(true)
	})

	it('rejects a non-object logoRef', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, logoRef: 'x.svg' }))
		expect(errs.some((e) => e.includes('logo-ref-invalid-shape'))).toBe(true)
	})

	it('rejects a missing primaryColor', () => {
		const theme = { ...validTheme }
		delete theme.primaryColor
		const errs = validateAppTheme(withAppTheme(theme))
		expect(errs.some((e) => e.includes('primaryColor') && e.includes('color-required'))).toBe(true)
	})

	it('rejects a non-hex color', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, secondaryColor: 'navy' }))
		expect(errs.some((e) => e.includes('secondaryColor') && e.includes('color-not-hex'))).toBe(true)
	})

	it('accepts 3-digit hex colors', () => {
		expect(validateAppTheme(withAppTheme({ ...validTheme, accentColor: '#f90' }))).toEqual([])
	})

	it('rejects an unknown headerStyle value', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, headerStyle: 'fancy' }))
		expect(errs.some((e) => e.includes('unknown-header-style'))).toBe(true)
	})

	it('accepts every closed headerStyle enum value', () => {
		for (const headerStyle of HEADER_STYLES) {
			expect(validateAppTheme(withAppTheme({ ...validTheme, headerStyle }))).toEqual([])
		}
	})

	it('rejects an unknown key on the appTheme object', () => {
		const errs = validateAppTheme(withAppTheme({ ...validTheme, extra: 'x' }))
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})

	it('rejects an array appTheme (single object only)', () => {
		const errs = validateAppTheme(withAppTheme([validTheme]))
		expect(errs.some((e) => e.includes('invalid-shape'))).toBe(true)
	})

	it('is a closed 3-value enum', () => {
		expect(HEADER_STYLES).toEqual(['default', 'compact', 'branded'])
	})
})
