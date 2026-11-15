/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `SchemaHeaderForm.vue`.
 *
 * The form is controlled through `value` and emits the whole next header on
 * `input`. Field errors appear only once a field has been blurred, except a
 * server-side `slugError`, which shows at once.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SchemaHeaderForm from '../../../src/components/schema-editor/SchemaHeaderForm.vue'

const stubs = {
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'error', 'helperText'],
		emits: ['update:modelValue', 'blur'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')" />',
	},
}

const SLUG_HELP = 'Slug must be kebab-case (lowercase letters, digits, hyphens) and start with a letter.'
const header = (overrides = {}) => ({ slug: 'customer', title: 'Customer', version: '1.0.0', ...overrides })
const mountWith = (props) => mount(SchemaHeaderForm, { props, global: { stubs } })
function fields (wrapper) {
	const [slug, title, description, version] = wrapper.findAllComponents({ name: 'NcTextField' })
	return { slug, title, description, version }
}

describe('SchemaHeaderForm', () => {
	it('emits the whole header with the edited field replaced', async () => {
		const wrapper = mountWith({ value: header() })
		const inputs = wrapper.findAll('input')

		await inputs[0].setValue('client')
		await inputs[2].setValue('People we bill')
		await inputs[3].setValue('2.0.0')

		expect(wrapper.emitted('input')).toEqual([
			[header({ slug: 'client' })],
			[header({ description: 'People we bill' })],
			[header({ version: '2.0.0' })],
		])
	})

	it('shows no field error before a field is blurred', () => {
		const { slug, title, version } = fields(mountWith({ value: header({ slug: 'Bad Slug', title: ' ', version: '1' }) }))

		expect(slug.props('error')).toBe(false)
		expect(slug.props('helperText')).toBe('')
		expect(title.props('error')).toBe(false)
		expect(version.props('error')).toBe(false)
	})

	it('shows each invalid field\'s message after it is blurred', async () => {
		const wrapper = mountWith({ value: header({ slug: '1-customer', title: '   ', version: '1.0' }) })

		for (const input of wrapper.findAll('input')) {
			await input.trigger('blur')
		}

		const { slug, title, version } = fields(wrapper)
		expect(slug.props('error')).toBe(true)
		expect(slug.props('helperText')).toBe(SLUG_HELP)
		expect(title.props('error')).toBe(true)
		expect(title.props('helperText')).toBe('Title is required.')
		expect(version.props('error')).toBe(true)
		expect(version.props('helperText')).toBe('Version must follow semver MAJOR.MINOR.PATCH.')
	})

	it('shows no message for valid fields after blur', async () => {
		const wrapper = mountWith({ value: header({ slug: 'customer-2' }) })

		for (const input of wrapper.findAll('input')) {
			await input.trigger('blur')
		}

		const { slug, title, version } = fields(wrapper)
		expect([slug.props('error'), title.props('error'), version.props('error')]).toEqual([false, false, false])
		expect(slug.props('helperText')).toBe('')
		expect(wrapper.vm.allValid).toBe(true)
	})

	it('shows a server slug error at once, ahead of the format message', async () => {
		const wrapper = mountWith({ value: header({ slug: 'Bad' }), slugError: 'This slug is already taken.' })

		expect(fields(wrapper).slug.props('error')).toBe(true)
		expect(fields(wrapper).slug.props('helperText')).toBe('This slug is already taken.')

		await wrapper.findAll('input')[0].trigger('blur')
		expect(fields(wrapper).slug.props('helperText')).toBe('This slug is already taken.')
	})

	it('locks the slug field when asked and shows an absent description as empty', () => {
		const { slug, description } = fields(mountWith({ value: header(), lockedSlug: true }))

		expect(slug.props('disabled')).toBe(true)
		expect(description.props('modelValue')).toBe('')
	})

	it('reports overall validity from all three rules', () => {
		expect(mountWith({ value: header() }).vm.allValid).toBe(true)
		expect(mountWith({ value: header({ slug: 'Customer' }) }).vm.allValid).toBe(false)
		expect(mountWith({ value: header({ slug: undefined }) }).vm.allValid).toBe(false)
		expect(mountWith({ value: header({ title: undefined }) }).vm.allValid).toBe(false)
		expect(mountWith({ value: header({ version: '1.0.0-beta' }) }).vm.allValid).toBe(false)
		expect(mountWith({ value: header({ version: undefined }) }).vm.allValid).toBe(false)
	})
})
