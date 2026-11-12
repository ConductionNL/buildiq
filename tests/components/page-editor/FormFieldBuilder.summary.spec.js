/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning FormFieldBuilder.vue's collapsed-row logic summary
 * (`summaryFor`) and its dangling-condition warning (`danglingConditionMark`)
 * across every branch: validation object vs legacy flat keys, conditions,
 * defaults, calculations, and the condition shapes that are not checked.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

const FIELDS = [
	{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
	{ key: 'email', label: 'Email', type: 'string' },
]

function vm() {
	return mount(FormFieldBuilder, { propsData: { modelValue: FIELDS, showLogic: true } }).vm
}

describe('FormFieldBuilder summaryFor', () => {
	it.each([
		[null, ''],
		[undefined, ''],
		[{}, ''],
		[{ required: true, pattern: 'x' }, 'required · pattern'],
		[{ validation: {}, required: true, pattern: 'x' }, 'pattern'],
		[{ validation: { required: 1 } }, 'required'],
		[{ validation: { required: false, pattern: undefined }, pattern: '' }, ''],
		[{ validation: { pattern: '' } }, 'pattern'],
		[{ validation: { pattern: undefined }, pattern: 'p' }, 'pattern'],
		[{ visibleWhen: { field: 'a' } }, '1 condition'],
		[{ default: null }, 'prefilled'],
		[{ default: undefined }, ''],
		[{ calculate: { op: 'sum' } }, 'calculated'],
		[{ calculate: '' }, ''],
		[
			{
				validation: { required: true, pattern: '^a$' },
				visibleWhen: { field: 'a' },
				default: 0,
				calculate: 'x',
			},
			'required · pattern · 1 condition · prefilled · calculated',
		],
	])('summarises %j as %j', (field, expected) => {
		expect(vm().summaryFor(field)).toBe(expected)
	})
})

describe('FormFieldBuilder danglingConditionMark', () => {
	const clean = { hasError: false, message: '' }

	it.each([
		[null],
		[{}],
		[{ visibleWhen: null }],
		[{ visibleWhen: { field: 'gone', endpoint: '/x' } }],
		[{ visibleWhen: { field: 'gone', source: 'remote' } }],
		[{ visibleWhen: { field: 42 } }],
		[{ visibleWhen: { field: 'email' } }],
	])('reports nothing for %j', (field) => {
		expect(vm().danglingConditionMark(field)).toEqual(clean)
	})

	it('reports a condition on an undeclared key', () => {
		const mark = vm().danglingConditionMark({ visibleWhen: { field: 'gone' } })
		expect(mark.hasError).toBe(true)
		expect(mark.message).toContain('removed field')
	})
})
