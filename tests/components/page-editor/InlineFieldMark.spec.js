/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for InlineFieldMark + the `pageEditorValidation` mixin
 * wiring (task 5.5 / REQ-OBPD-011).
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import InlineFieldMark from '../../../src/components/page-editor/fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../../src/mixins/pageEditorValidation.js'

describe('InlineFieldMark', () => {
	it('renders nothing when there is no error', () => {
		const wrapper = mount(InlineFieldMark, { propsData: { error: null } })
		expect(wrapper.find('.inline-field-mark').exists()).toBe(false)
	})

	it('renders nothing when hasError is false', () => {
		const wrapper = mount(InlineFieldMark, { propsData: { error: { hasError: false, message: '' } } })
		expect(wrapper.find('.inline-field-mark').exists()).toBe(false)
	})

	it('renders the message + role=alert when hasError is true', () => {
		const wrapper = mount(InlineFieldMark, { propsData: { error: { hasError: true, message: 'must be set' } } })
		const mark = wrapper.find('.inline-field-mark')
		expect(mark.exists()).toBe(true)
		expect(mark.attributes('role')).toBe('alert')
		expect(wrapper.text()).toContain('must be set')
	})

	it('falls back to a generic message when one is not supplied', () => {
		const wrapper = mount(InlineFieldMark, { propsData: { error: { hasError: true } } })
		expect(wrapper.text().length).toBeGreaterThan(0)
	})
})

describe('pageEditorValidation mixin', () => {
	const Host = {
		mixins: [pageEditorValidationMixin],
		props: ['config'],
		computed: {
			validatedConfigKeys() { return ['register', 'schema'] },
		},
		render(h) { return h('div') },
	}

	it('registers each validated config key on mount and unregisters on destroy', () => {
		const register = vi.fn()
		const unregister = vi.fn()
		const wrapper = mount(Host, {
			propsData: { config: {} },
			provide: { pageEditorValidator: { register, unregister, errorFor: () => ({ hasError: false, message: '' }) } },
		})
		expect(register).toHaveBeenCalledWith('register')
		expect(register).toHaveBeenCalledWith('schema')
		wrapper.destroy()
		expect(unregister).toHaveBeenCalledWith('register')
		expect(unregister).toHaveBeenCalledWith('schema')
	})

	it('markFor / isInvalid read through the injected errorFor', () => {
		const wrapper = mount(Host, {
			propsData: { config: {} },
			provide: {
				pageEditorValidator: {
					register: () => {},
					unregister: () => {},
					errorFor: (key) => (key === 'register' ? { hasError: true, message: 'required' } : { hasError: false, message: '' }),
				},
			},
		})
		expect(wrapper.vm.markFor('register')).toEqual({ hasError: true, message: 'required' })
		expect(wrapper.vm.isInvalid('register')).toBe(true)
		expect(wrapper.vm.isInvalid('schema')).toBe(false)
	})

	it('is a no-op without a provider (standalone mount)', () => {
		const wrapper = mount(Host, { propsData: { config: {} } })
		expect(wrapper.vm.markFor('register')).toBeNull()
		expect(wrapper.vm.isInvalid('register')).toBe(false)
		wrapper.destroy() // must not throw
	})
})
