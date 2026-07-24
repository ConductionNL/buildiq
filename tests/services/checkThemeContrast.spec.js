/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the app-theming WCAG contrast guardrail.
 *
 * Spec: app-theming (requirement "WCAG contrast guardrail blocks saving a
 * non-compliant theme").
 */
import { describe, it, expect } from 'vitest'
import {
	checkThemeContrast,
	parseHexColor,
	relativeLuminance,
	contrastRatio,
	accessibleTextColor,
	BACKGROUND_HEX,
	TEXT_MIN_RATIO,
	UI_MIN_RATIO,
} from '../../src/services/checkThemeContrast.js'

describe('parseHexColor', () => {
	it('parses a 6-digit hex color', () => {
		expect(parseHexColor('#1D4ED8')).toEqual({ r: 29, g: 78, b: 216 })
	})

	it('parses a 3-digit hex color by doubling each digit', () => {
		expect(parseHexColor('#fff')).toEqual({ r: 255, g: 255, b: 255 })
	})

	it('returns null for a non-hex string', () => {
		expect(parseHexColor('blue')).toBeNull()
		expect(parseHexColor('#12')).toBeNull()
		expect(parseHexColor('')).toBeNull()
		expect(parseHexColor(null)).toBeNull()
	})
})

describe('relativeLuminance', () => {
	it('is 1 for white and 0 for black', () => {
		expect(relativeLuminance({ r: 255, g: 255, b: 255 })).toBeCloseTo(1, 5)
		expect(relativeLuminance({ r: 0, g: 0, b: 0 })).toBeCloseTo(0, 5)
	})
})

describe('contrastRatio', () => {
	it('is 21:1 for black on white (the maximum WCAG ratio)', () => {
		expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 0)
	})

	it('is 1:1 for a color against itself', () => {
		expect(contrastRatio('#1D4ED8', '#1D4ED8')).toBeCloseTo(1, 5)
	})

	it('is symmetric', () => {
		expect(contrastRatio('#1D4ED8', '#ffffff')).toBeCloseTo(contrastRatio('#ffffff', '#1D4ED8'), 5)
	})

	it('returns null for invalid input', () => {
		expect(contrastRatio('nope', '#fff')).toBeNull()
	})
})

describe('accessibleTextColor', () => {
	it('picks white text on a dark background', () => {
		expect(accessibleTextColor('#0F172A')).toBe('#ffffff')
	})

	it('picks black text on a light background', () => {
		expect(accessibleTextColor('#F5F5F5')).toBe('#000000')
	})
})

describe('checkThemeContrast', () => {
	it('passes a known-good theme (verified defaults)', () => {
		const result = checkThemeContrast({
			primaryColor: '#1D4ED8',
			secondaryColor: '#0F172A',
			accentColor: '#B45309',
		})
		expect(result).toEqual({ passed: true, failures: [] })
	})

	it('fails a known-bad primary color (low contrast, both text and UI)', () => {
		const result = checkThemeContrast({
			primaryColor: '#E5E5E5', // near-white on white background
			secondaryColor: '#0F172A',
			accentColor: '#B45309',
		})
		expect(result.passed).toBe(false)
		const textFailure = result.failures.find((f) => f.pair === 'primaryColor-on-background' && f.kind === 'text')
		expect(textFailure).toBeTruthy()
		expect(textFailure.required).toBe(TEXT_MIN_RATIO)
		const uiFailure = result.failures.find((f) => f.pair === 'primaryColor-on-background' && f.kind === 'ui')
		expect(uiFailure).toBeTruthy()
		expect(uiFailure.required).toBe(UI_MIN_RATIO)
	})

	it('reports the failing accentColor pair with ratio + required threshold', () => {
		// #F59E0B (amber) computes to ~2.1:1 against #FFFFFF — below the 3:1 UI threshold.
		const result = checkThemeContrast({
			primaryColor: '#1D4ED8',
			secondaryColor: '#0F172A',
			accentColor: '#F59E0B',
		})
		expect(result.passed).toBe(false)
		const failure = result.failures.find((f) => f.pair === 'accentColor-on-background')
		expect(failure).toBeTruthy()
		expect(failure.kind).toBe('ui')
		expect(failure.required).toBe(UI_MIN_RATIO)
		expect(failure.ratio).toBeLessThan(UI_MIN_RATIO)
		expect(failure.ratio).toBeGreaterThan(2)
	})

	it('passes trivially when theme is null/undefined (nothing to check yet)', () => {
		expect(checkThemeContrast(null)).toEqual({ passed: true, failures: [] })
		expect(checkThemeContrast(undefined)).toEqual({ passed: true, failures: [] })
	})

	it('skips a color that is not valid hex (shape validation reports that separately)', () => {
		const result = checkThemeContrast({
			primaryColor: 'not-a-color',
			secondaryColor: '#0F172A',
			accentColor: '#B45309',
		})
		expect(result.failures.some((f) => f.pair === 'primaryColor-on-background')).toBe(false)
	})

	it('background reference is the NC default light main-background', () => {
		expect(BACKGROUND_HEX).toBe('#FFFFFF')
	})
})
