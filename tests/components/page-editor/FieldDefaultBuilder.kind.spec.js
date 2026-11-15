/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation of `FieldDefaultBuilder::kind`: which kind of
 * default a stored value is, and the picked kind while nothing is stored.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldDefaultBuilder from '../../../src/components/page-editor/fields/FieldDefaultBuilder.vue'

describe('FieldDefaultBuilder kind', () => {
	it.each([
		[null, 'none'],
		[undefined, 'none'],
		// The prop lists Boolean first, so Vue casts an empty string to `true`.
		['', 'literal'],
		['@me', '@me'],
		['@me.displayName', '@me.displayName'],
		['@me.email', '@me.email'],
		['@today', '@today'],
		['@object.city', 'object'],
		['@object.', 'object'],
		['@other', 'literal'],
		['Amsterdam', 'literal'],
		[0, 'literal'],
		[false, 'literal'],
		[42, 'literal'],
	])('reads %j as %j', (modelValue, kind) => {
		const wrapper = mount(FieldDefaultBuilder, { props: { modelValue } })
		expect(wrapper.vm.kind).toBe(kind)
	})

	it('shows the picked kind while nothing is stored', () => {
		const wrapper = mount(FieldDefaultBuilder, { props: { modelValue: null } })
		wrapper.vm.pickedKind = 'object'
		expect(wrapper.vm.kind).toBe('object')
	})

	it('ignores the picked kind once a value is stored', () => {
		const wrapper = mount(FieldDefaultBuilder, { props: { modelValue: '@today' } })
		wrapper.vm.pickedKind = 'literal'
		expect(wrapper.vm.kind).toBe('@today')
	})
})
