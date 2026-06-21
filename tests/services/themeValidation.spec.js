/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side runtime.theme validation.
 *
 * Spec: nldesign-theme-selection (REQ-NTS-001).
 */
import { describe, it, expect } from 'vitest'
import { validateTheme, THEME_SOURCES } from '../../src/services/manifestValidation/theme.js'

const validTheme = {
	source: 'nldesign',
	tokenSet: 'amsterdam',
	tokenSetName: 'Gemeente Amsterdam',
	preview: { primaryColor: '#004699', backgroundColor: '#FFFFFF' },
}

const withTheme = (theme) => ({ runtime: { theme } })

describe('validateTheme', () => {
	it('passes a valid theme', () => {
		expect(validateTheme(withTheme(validTheme))).toEqual([])
	})

	it('returns nothing when runtime.theme is absent', () => {
		expect(validateTheme({ runtime: {} })).toEqual([])
		expect(validateTheme({})).toEqual([])
	})

	it('accepts a theme without the optional preview', () => {
		expect(validateTheme(withTheme({ source: 'nldesign', tokenSet: 'utrecht', tokenSetName: 'Utrecht' }))).toEqual([])
	})

	it('rejects an unknown source', () => {
		const errs = validateTheme(withTheme({ ...validTheme, source: 'material' }))
		expect(errs.some((e) => e.includes('unknown-source'))).toBe(true)
	})

	it('rejects a missing tokenSet', () => {
		const errs = validateTheme(withTheme({ source: 'nldesign', tokenSetName: 'X' }))
		expect(errs.some((e) => e.includes('token-set-required'))).toBe(true)
	})

	it('rejects a non-kebab-case tokenSet', () => {
		const errs = validateTheme(withTheme({ ...validTheme, tokenSet: 'Den Haag' }))
		expect(errs.some((e) => e.includes('token-set-not-kebab'))).toBe(true)
	})

	it('accepts a multi-segment kebab tokenSet', () => {
		expect(validateTheme(withTheme({ ...validTheme, tokenSet: 'bodegraven-reeuwijk' }))).toEqual([])
	})

	it('rejects a missing tokenSetName', () => {
		const errs = validateTheme(withTheme({ source: 'nldesign', tokenSet: 'amsterdam' }))
		expect(errs.some((e) => e.includes('token-set-name-required'))).toBe(true)
	})

	it('rejects a non-hex preview colour', () => {
		const errs = validateTheme(withTheme({ ...validTheme, preview: { primaryColor: 'blue' } }))
		expect(errs.some((e) => e.includes('preview-color-not-hex'))).toBe(true)
	})

	it('accepts 3-digit hex preview colours', () => {
		expect(validateTheme(withTheme({ ...validTheme, preview: { primaryColor: '#abc' } }))).toEqual([])
	})

	it('rejects an unknown key on the theme', () => {
		const errs = validateTheme(withTheme({ ...validTheme, logo: 'x' }))
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})

	it('rejects an unknown key on preview', () => {
		const errs = validateTheme(withTheme({ ...validTheme, preview: { primaryColor: '#004699', accent: '#fff' } }))
		expect(errs.some((e) => e.includes('preview') && e.includes('unknown-key'))).toBe(true)
	})

	it('rejects an array theme (single object only)', () => {
		const errs = validateTheme(withTheme([validTheme]))
		expect(errs.some((e) => e.includes('invalid-shape'))).toBe(true)
	})

	it('only knows nldesign as a source', () => {
		expect(THEME_SOURCES).toEqual(['nldesign'])
	})
})
