/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SettingsSectionBuilder: the inputs an author actually types
 * into — title and id, the body-kind radios, the component key and its props
 * JSON (including a half-typed object), the widget rows — and what each one
 * writes back.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock(
	'../../../src/components/page-editor/fields/FormFieldBuilder.vue',
	async () => {
		const { h } = await import('vue')
		return {
			default: {
				name: 'FormFieldBuilder',
				props: ['modelValue'],
				render() {
					return h('div', { class: 'form-field-builder-stub' })
				},
			},
		}
	},
)

const SettingsSectionBuilder = (
	await import('../../../src/components/page-editor/fields/SettingsSectionBuilder.vue')
).default

/**
 * Mount the builder over sections.
 *
 * @param {Array<object>} modelValue - the sections.
 * @return {object} The wrapper.
 */
function mountBuilder(modelValue) {
	return mount(SettingsSectionBuilder, { props: { modelValue } })
}

/**
 * The last emitted sections.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>} The sections.
 */
function last(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

describe('SettingsSectionBuilder inputs', () => {
	it('writes the title and id, and clears the id when emptied', async () => {
		const wrapper = mountBuilder([{ title: 'old', id: 'general', fields: [] }])
		const [title, id] = wrapper.findAll('.settings-section-builder__head input')

		await title.setValue('settings.general')
		expect(last(wrapper)).toEqual([{ title: 'settings.general', id: 'general', fields: [] }])

		await id.setValue('')
		expect(last(wrapper)).toEqual([{ title: 'old', fields: [] }])
	})

	it('the remove and add-section buttons drop and append a section', async () => {
		const wrapper = mountBuilder([{ title: 'a', fields: [] }, { title: 'b', fields: [] }])

		await wrapper.findAll('.settings-section-builder__remove').at(0).trigger('click')
		expect(last(wrapper)).toEqual([{ title: 'b', fields: [] }])

		const adds = wrapper.findAll('.settings-section-builder__add')
		await adds.at(adds.length - 1).trigger('click')
		expect(last(wrapper)).toHaveLength(3)
	})

	it('the radios switch the body kind, keeping the other keys', async () => {
		const wrapper = mountBuilder([{ title: 't', id: 'x', widgets: [{ type: 'version-info' }] }])
		const radios = wrapper.findAll('input[type="radio"]')

		expect(radios.at(2).element.checked).toBe(true)

		await radios.at(1).trigger('change')
		expect(last(wrapper)).toEqual([{ title: 't', id: 'x', component: '' }])

		await radios.at(0).trigger('change')
		expect(last(wrapper)).toEqual([{ title: 't', id: 'x', fields: [] }])

		await radios.at(2).trigger('change')
		expect(last(wrapper)).toEqual([{ title: 't', id: 'x', widgets: [] }])
	})

	it('a fields body passes the field builder its list and takes its updates', async () => {
		const wrapper = mountBuilder([{ title: 't' }])
		const stub = wrapper.findComponent({ name: 'FormFieldBuilder' })

		expect(stub.props('modelValue')).toEqual([])
		stub.vm.$emit('update:modelValue', [])
		expect(last(wrapper)).toEqual([{ title: 't' }])
	})

	it('the component key and its stored props render and are written back', async () => {
		const wrapper = mountBuilder([{ title: 't', component: 'AppSettingsPanel', props: { mode: 'full' } }])
		const key = wrapper.find('.settings-section-builder__body input')
		const textarea = wrapper.find('textarea')

		expect(key.element.value).toBe('AppSettingsPanel')
		expect(textarea.element.value).toBe('{"mode":"full"}')

		await key.setValue('OtherPanel')
		expect(last(wrapper)[0].component).toBe('OtherPanel')
	})

	it('a half-typed props object shows the parse error and keeps the draft', async () => {
		const wrapper = mountBuilder([{ title: 't', component: 'X', props: { a: 1 } }])
		const textarea = wrapper.find('textarea')

		await textarea.setValue('{"a":')

		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
		expect(textarea.element.value).toBe('{"a":')

		await textarea.setValue('{"a":2}')
		expect(last(wrapper)[0].props).toEqual({ a: 2 })
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('props that cannot be stringified show as empty', () => {
		const circular = {}
		circular.self = circular
		const wrapper = mountBuilder([])

		expect(wrapper.vm.stringifyProps(circular)).toBe('')
		expect(wrapper.vm.stringifyProps(null)).toBe('')
	})

	it('a widget row writes its type, component name and props, and can be removed', async () => {
		const wrapper = mountBuilder([{ title: 't', widgets: [{ type: 'component', componentName: 'A' }] }])
		const row = wrapper.find('.settings-section-builder__widget')
		const inputs = row.findAll('input')

		await inputs.at(0).setValue('B')
		expect(last(wrapper)[0].widgets).toEqual([{ type: 'component', componentName: 'B' }])

		await inputs.at(1).setValue('{"x":1}')
		expect(last(wrapper)[0].widgets[0].props).toEqual({ x: 1 })

		const count = wrapper.emitted('update:modelValue').length
		await inputs.at(1).setValue('{"x":')
		expect(wrapper.emitted('update:modelValue')).toHaveLength(count)

		await inputs.at(1).setValue('  ')
		expect(last(wrapper)[0].widgets[0]).not.toHaveProperty('props')

		await row.find('select').setValue('register-mapping')
		expect(last(wrapper)[0].widgets[0].type).toBe('register-mapping')

		await row.find('.settings-section-builder__remove').trigger('click')
		expect(last(wrapper)[0].widgets).toEqual([])
	})

	it('the add-widget button appends a version-info widget', async () => {
		const wrapper = mountBuilder([{ title: 't', widgets: [] }])

		await wrapper.findAll('.settings-section-builder__add').at(0).trigger('click')

		expect(last(wrapper)[0].widgets).toEqual([{ type: 'version-info' }])
	})

	it('a value that is not a list reads as no sections', () => {
		const wrapper = mount(SettingsSectionBuilder, { props: { modelValue: null } })

		expect(wrapper.vm.localSections).toEqual([])
	})
})
