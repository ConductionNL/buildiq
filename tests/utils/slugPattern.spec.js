/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/utils/slugPattern.js.
 *
 * Covers spec openbuilt-app-creation-wizard task 5.1:
 *   - SLUG_PATTERN is the expected constant string
 *   - toKebabCase handles spaces, uppercase, accents, special chars
 *   - validateSlug: happy path, leading underscore, invalid chars, too short
 */

import { describe, it, expect } from 'vitest'
import { SLUG_PATTERN, toKebabCase, validateSlug } from '../../src/utils/slugPattern.js'

// ─── SLUG_PATTERN ────────────────────────────────────────────────────────────

describe('SLUG_PATTERN', () => {
	it('is the canonical pattern string', () => {
		expect(SLUG_PATTERN).toBe('^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$')
	})

	it('matches valid slugs', () => {
		const regex = new RegExp(SLUG_PATTERN)
		expect(regex.test('my-app')).toBe(true)
		expect(regex.test('hello-world')).toBe(true)
		expect(regex.test('production')).toBe(true)
		expect(regex.test('ab')).toBe(true)
		expect(regex.test('a1-b2')).toBe(true)
	})

	it('rejects leading underscore', () => {
		const regex = new RegExp(SLUG_PATTERN)
		expect(regex.test('_internal')).toBe(false)
	})

	it('rejects single character', () => {
		const regex = new RegExp(SLUG_PATTERN)
		expect(regex.test('a')).toBe(false)
	})
})

// ─── toKebabCase ─────────────────────────────────────────────────────────────

describe('toKebabCase', () => {
	it('converts spaces to hyphens', () => {
		expect(toKebabCase('My App')).toBe('my-app')
	})

	it('lowercases uppercase characters', () => {
		expect(toKebabCase('MyApp')).toBe('myapp')
	})

	it('removes accents/combining marks', () => {
		expect(toKebabCase('Évaluation')).toBe('evaluation')
	})

	it('strips special characters', () => {
		expect(toKebabCase('My App!')).toBe('my-app')
		expect(toKebabCase('hello.world')).toBe('helloworld')
	})

	it('collapses multiple hyphens', () => {
		expect(toKebabCase('My  App')).toBe('my-app')
	})

	it('trims leading and trailing hyphens', () => {
		expect(toKebabCase(' App ')).toBe('app')
	})

	it('returns empty string for empty input', () => {
		expect(toKebabCase('')).toBe('')
	})

	it('returns empty string for non-string input', () => {
		expect(toKebabCase(null)).toBe('')
		expect(toKebabCase(undefined)).toBe('')
	})

	it('handles string of only special chars', () => {
		expect(toKebabCase('!!!')).toBe('')
	})
})

// ─── validateSlug ────────────────────────────────────────────────────────────

describe('validateSlug', () => {
	it('returns valid:true for a valid slug', () => {
		expect(validateSlug('my-app')).toEqual({ valid: true })
		expect(validateSlug('production')).toEqual({ valid: true })
		expect(validateSlug('ab')).toEqual({ valid: true })
	})

	it('returns valid:false with message for empty slug', () => {
		const result = validateSlug('')
		expect(result.valid).toBe(false)
		expect(result.message).toContain('empty')
	})

	it('returns valid:false for leading underscore', () => {
		const result = validateSlug('_internal')
		expect(result.valid).toBe(false)
		expect(result.message).toContain('reserved for openbuilt system use')
	})

	it('returns valid:false for invalid characters', () => {
		const result = validateSlug('my app!')
		expect(result.valid).toBe(false)
		expect(result.message).toContain('hyphens only')
	})

	it('returns valid:false for single character (pattern requires min 2)', () => {
		const result = validateSlug('a')
		expect(result.valid).toBe(false)
	})

	it('returns valid:false for uppercase', () => {
		const result = validateSlug('MyApp')
		expect(result.valid).toBe(false)
	})

	it('returns valid:false for leading hyphen', () => {
		const result = validateSlug('-my-app')
		expect(result.valid).toBe(false)
	})

	it('returns valid:false for trailing hyphen', () => {
		const result = validateSlug('my-app-')
		expect(result.valid).toBe(false)
	})
})
