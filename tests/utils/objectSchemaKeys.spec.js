/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for objectSchemaKeys / objectRegisterKeys / matchesKey.
 *
 * Covers:
 *  - keys come from the context, the object and its @self, in that order,
 *    once each, as strings, skipping blanks.
 *  - a context given as a ref or as a plain object.
 *  - matchesKey never matches a blank value and compares as strings.
 */

import { describe, expect, it } from 'vitest'
import { ref } from 'vue'
import { matchesKey, objectRegisterKeys, objectSchemaKeys } from '../../src/utils/objectSchemaKeys.js'

const OBJECT = {
	schema: 7,
	register: 'zaken',
	'@self': { schemaSlug: 'case', schema: '7', registerSlug: 'zaken', register: 3 },
}

describe('objectSchemaKeys', () => {
	it('collects schema keys from context, object and @self, once each', () => {
		expect(objectSchemaKeys(OBJECT, { schema: 'case-file' })).toEqual(['case-file', '7', 'case'])
	})

	it('accepts the context as a ref', () => {
		expect(objectSchemaKeys(OBJECT, ref({ schema: 'from-ref' }))[0]).toBe('from-ref')
		expect(objectSchemaKeys(OBJECT, ref(null))).toEqual(['7', 'case'])
	})

	it('skips blank candidates and copes with no object', () => {
		expect(objectSchemaKeys({ schema: '', '@self': { schema: null } })).toEqual([])
		expect(objectSchemaKeys(null)).toEqual([])
	})
})

describe('objectRegisterKeys', () => {
	it('collects register keys from context, object and @self, once each', () => {
		expect(objectRegisterKeys(OBJECT, { register: 'zaken' })).toEqual(['zaken', '3'])
		expect(objectRegisterKeys(undefined, ref({ register: 'r' }))).toEqual(['r'])
	})
})

describe('matchesKey', () => {
	it('matches a value naming one of the keys, as a string', () => {
		expect(matchesKey('case', ['7', 'case'])).toBe(true)
		expect(matchesKey(7, ['7'])).toBe(true)
		expect(matchesKey('task', ['7', 'case'])).toBe(false)
	})

	it('never matches a blank value', () => {
		expect(matchesKey('', [''])).toBe(false)
		expect(matchesKey(null, ['null'])).toBe(false)
		expect(matchesKey(undefined, ['undefined'])).toBe(false)
	})
})
