/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning CalculationEditor.vue's `newNameError`: an empty
 * name says nothing, then the name shape, then a register-file calculation,
 * then an existing property calculation.
 */

import { shallowMount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/services/calculations.js', async (importOriginal) => ({
	...(await importOriginal()),
	loadOperatorCatalogue: vi.fn(async () => ({ operators: [], categories: [] })),
	tryCalculation: vi.fn(),
}))

const { default: CalculationEditor } =
	await import('../../../src/components/schema-editor/CalculationEditor.vue')

function editor(props = {}) {
	return shallowMount(CalculationEditor, {
		props: { calculations: null, propertyCalculations: {}, fieldNames: [], refusals: {}, ...props },
		global: { mocks: { t: (app, s) => s } },
	}).vm
}

const SHAPE = 'Use letters, digits and underscores, starting with a letter.'
const IN_FILE = 'The register file already calculates this field.'
const EXISTS = 'This field already has a calculation.'

describe('CalculationEditor newNameError', () => {
	it.each([
		['', {}, ''],
		['   ', {}, ''],
		['total', {}, ''],
		[' total ', {}, ''],
		['_x9', {}, ''],
		['9lives', {}, SHAPE],
		['has-dash', {}, SHAPE],
		['age', { calculations: { age: {} } }, IN_FILE],
		['age', { calculations: { age: {} }, propertyCalculations: { age: {} } }, IN_FILE],
		['total', { propertyCalculations: { total: {} } }, EXISTS],
		['total', { propertyCalculations: null }, ''],
		['toString', {}, ''],
		['age', { calculations: [{ age: {} }] }, ''],
	])('of %j with %j is %j', (name, props, expected) => {
		const vm = editor(props)
		vm.newName = name
		expect(vm.newNameError).toBe(expected)
	})
})
