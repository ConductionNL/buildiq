/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden spec for the reference check of a page layout tab.
 *
 * Pins missingReference() and layoutBlocked() over every tab kind, unknown and
 * prototype-named kinds, and missing or empty references.
 */
import { describe, expect, it } from 'vitest'

const { missingReference, layoutBlocked } =
	await import('../../src/components/page-editor/fields/PageLayoutBodyEditor.vue')

const KINDS = [
	undefined,
	null,
	'',
	'leaf',
	'fieldGroup',
	'relatedList',
	'widgets',
	'unknown',
	'constructor',
	'toString',
	'__proto__',
	'Leaf',
]
const SHAPES = [
	{},
	{ ref: 'detail' },
	{ ref: '' },
	{ fields: [] },
	{ fields: ['name'] },
	{ relatedRegister: 'cases' },
	{ relatedSchema: 'case' },
	{ relatedRegister: 'cases', relatedSchema: 'case' },
	{ relatedRegister: '', relatedSchema: 'case' },
]

function cases() {
	const out = [
		`null => ${JSON.stringify(missingReference(null))}`,
		`undefined => ${JSON.stringify(missingReference(undefined))}`,
	]
	KINDS.forEach((kind) => {
		SHAPES.forEach((shape) => {
			const tab = { ...shape, kind }
			out.push(
				`${JSON.stringify(tab)} => ${JSON.stringify(missingReference(tab))}`,
			)
		})
	})
	return out
}

describe('missingReference golden', () => {
	it('names the same missing key for every kind and shape', async () => {
		await expect(cases().join('\n') + '\n').toMatchFileSnapshot(
			'./__golden__/missingReference.txt',
		)
	})

	it('blocks a layout exactly when a tab misses its reference or an upload field has no default', () => {
		expect(layoutBlocked(null)).toBe(false)
		expect(layoutBlocked({ tabs: [{ kind: 'leaf', ref: 'x' }] })).toBe(false)
		expect(layoutBlocked({ tabs: [{ kind: 'leaf' }] })).toBe(true)
		expect(
			layoutBlocked({
				tabs: [],
				uploadFields: [{ field: 'a', visibility: 'hidden' }],
			}),
		).toBe(true)
	})
})
