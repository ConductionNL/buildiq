/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ColumnBuilder's `onLabelInput`: a label promotes the
 * row to `{ key, label }`, clearing it drops the label and collapses a
 * key-only object back to the string shorthand.
 */
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ColumnBuilder from '../../../src/components/page-editor/fields/ColumnBuilder.vue'

function emittedAfter(columns, index, value) {
	const wrapper = shallowMount(ColumnBuilder, { props: { modelValue: columns } })
	wrapper.vm.onLabelInput(index, value)
	return wrapper.emitted('update:modelValue')[0][0]
}

describe('ColumnBuilder onLabelInput', () => {
	it.each([
		[['title'], 0, 'Title', [{ key: 'title', label: 'Title' }]],
		[[{ property: 'p', width: 2 }], 0, 'P', [{ key: 'p', label: 'P' }]],
		[[{ key: 'k', label: 'Old' }], 0, '', ['k']],
		[[{ key: 'k', label: 'Old', width: 2 }], 0, '', [{ key: 'k', width: 2 }]],
		[[{ label: 'Old', width: 2 }], 0, '', [{ width: 2 }]],
		[[{ key: '', label: 'Old' }], 0, '', [{ key: '' }]],
		[['title'], 0, '', ['title']],
		[[null], 0, '', [null]],
		[['a', 'b'], 1, 'B', ['a', { key: 'b', label: 'B' }]],
	])('on %j row %i with %j emits %j', (columns, index, value, expected) => {
		const next = emittedAfter(columns, index, value)
		expect(next).toEqual(expected)
		expect(next).not.toBe(columns)
	})
})
