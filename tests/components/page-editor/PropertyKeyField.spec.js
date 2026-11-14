/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PropertyKeyField: a select over the bound schema's keys
 * once a schema is bound, a free-text input otherwise, both emitting the
 * picked or typed key as `update`.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PropertyKeyField from '../../../src/components/page-editor/fields/PropertyKeyField.vue'

describe('PropertyKeyField', () => {
	it('is a select over the keys, after the empty option, once a schema is bound', () => {
		const wrapper = mount(PropertyKeyField, {
			props: { bound: true, keys: ['title', 'status'], value: 'status', emptyLabel: '— none —' },
		})

		expect(wrapper.element.tagName).toBe('SELECT')
		const options = wrapper.findAll('option')
		expect(options.map((o) => o.attributes('value'))).toEqual(['', 'title', 'status'])
		expect(options[0].text()).toBe('— none —')
		expect(wrapper.element.value).toBe('status')
	})

	it('emits the picked key on change', async () => {
		const wrapper = mount(PropertyKeyField, { props: { bound: true, keys: ['title', 'status'] } })

		wrapper.element.value = 'title'
		await wrapper.trigger('change')

		expect(wrapper.emitted('update')).toEqual([['title']])
	})

	it('is a free-text input with the placeholder when no schema is bound', async () => {
		const wrapper = mount(PropertyKeyField, {
			props: { bound: false, keys: ['ignored'], value: 'owner', placeholder: 'property key' },
		})

		expect(wrapper.element.tagName).toBe('INPUT')
		expect(wrapper.attributes('type')).toBe('text')
		expect(wrapper.attributes('placeholder')).toBe('property key')
		expect(wrapper.element.value).toBe('owner')
		expect(wrapper.findAll('option')).toHaveLength(0)

		wrapper.element.value = 'assignee'
		await wrapper.trigger('input')
		expect(wrapper.emitted('update')).toEqual([['assignee']])
	})

	it('defaults to an empty free-text input without a placeholder', () => {
		const wrapper = mount(PropertyKeyField)

		expect(wrapper.element.tagName).toBe('INPUT')
		expect(wrapper.element.value).toBe('')
		expect(wrapper.attributes('placeholder')).toBeUndefined()
	})
})
