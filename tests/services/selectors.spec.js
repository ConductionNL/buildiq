/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the shared dot-path selector helpers.
 *
 * Spec: openconnector-api-sources (REQ-OCAS-003, REQ-OCAS-006).
 */
import { describe, expect, it } from 'vitest'
import {
	extractItems,
	flattenSample,
	projectFields,
	resolveSelector,
} from '../../src/services/selectors.js'

describe('resolveSelector', () => {
	it('resolves a nested dot path', () => {
		expect(resolveSelector({ a: { b: { c: 1 } } }, 'a.b.c')).toBe(1)
	})
	it('indexes into arrays with numeric segments', () => {
		expect(resolveSelector({ list: [{ x: 'y' }] }, 'list.0.x')).toBe('y')
	})
	it('returns root for empty selector', () => {
		const root = { a: 1 }
		expect(resolveSelector(root, '')).toBe(root)
	})
	it('returns undefined for a broken path', () => {
		expect(resolveSelector({ a: 1 }, 'a.b.c')).toBeUndefined()
		expect(resolveSelector({ a: 1 }, 'missing')).toBeUndefined()
	})
})

describe('projectFields', () => {
	it('projects fields and reports missing selectors', () => {
		const missing = []
		const row = projectFields(
			{ naam: 'Acme', kvkNummer: '123' },
			{ name: 'naam', kvk: 'kvkNummer', dead: 'nope' },
			(field, sel) => missing.push([field, sel]),
		)
		expect(row).toEqual({ name: 'Acme', kvk: '123', dead: null })
		expect(missing).toEqual([['dead', 'nope']])
	})
})

describe('extractItems', () => {
	it('extracts a list under itemsPath', () => {
		const resp = { resultaten: [{ a: 1 }, { a: 2 }], totaal: 2 }
		expect(extractItems(resp, 'resultaten')).toHaveLength(2)
	})
	it('wraps the root as a single item when itemsPath is absent', () => {
		expect(extractItems({ a: 1 })).toEqual([{ a: 1 }])
	})
	it('returns empty list for a non-array resolution', () => {
		expect(extractItems({ resultaten: 'x' }, 'resultaten')).toEqual([])
	})
})

describe('flattenSample', () => {
	it('flattens arrays and objects with array/leaf flags', () => {
		const nodes = flattenSample({ resultaten: [{ naam: 'Acme' }] })
		const arr = nodes.find((n) => n.path === 'resultaten')
		const leaf = nodes.find((n) => n.path === 'resultaten.0.naam')
		expect(arr.isArray).toBe(true)
		expect(leaf.isLeaf).toBe(true)
		expect(leaf.value).toBe('Acme')
	})
})

describe('selector edges', () => {
	const sample = { a: [{ b: null, c: 'text', d: 0 }], n: { 0: 'zero' } }

	it('pins every way a path resolves or breaks', () => {
		expect(resolveSelector(sample, null)).toBe(sample)
		expect(resolveSelector(sample, undefined)).toBe(sample)
		expect(resolveSelector(sample, 0)).toBeUndefined()
		expect(resolveSelector(sample.n, 0)).toBe('zero')
		expect(resolveSelector(sample, 'a.0.b')).toBeNull()
		expect(resolveSelector(sample, 'a.0.b.x')).toBeUndefined()
		expect(resolveSelector(sample, 'a.0.c.length')).toBeUndefined()
		expect(resolveSelector(sample, 'a.0.d')).toBe(0)
		expect(resolveSelector(sample, 'a.length')).toBe(1)
		expect(resolveSelector('text', 'length')).toBeUndefined()
		expect(resolveSelector(null, 'a')).toBeUndefined()
		expect(resolveSelector(sample, 'a.')).toBeUndefined()
	})

	it('wraps only a present root and requires a list under itemsPath', () => {
		expect(extractItems(null)).toEqual([])
		expect(extractItems(undefined, '')).toEqual([])
		expect(extractItems(0, null)).toEqual([0])
		expect(extractItems('', undefined)).toEqual([''])
		expect(extractItems(sample, 'a')).toBe(sample.a)
		expect(extractItems(sample, 'n')).toEqual([])
		expect(extractItems(null, 'a')).toEqual([])
	})
})
