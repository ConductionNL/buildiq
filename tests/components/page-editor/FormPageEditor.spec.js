/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPageEditor (REQ-OBPD-006).
 *
 * Covers:
 *  - Submit shape radio renders both options.
 *  - submitHandler / submitEndpoint are mutually exclusive: setting one
 *    clears the other on `update:config`.
 *  - Switching radio variants clears the inactive field.
 *  - submitMethod enum picker forwards POST/PUT/PATCH.
 *  - Mode enum forwards public/create/edit.
 *  - Optional submitLabel + successMessage propagate.
 *  - FormFieldBuilder add forwards through update:config.
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('../../../src/components/page-editor/fields/FormFieldBuilder.vue', () => ({
	default: {
		name: 'FormFieldBuilder',
		props: ['modelValue'],
		render(h) { return h('div', { staticClass: 'form-field-builder-stub' }) },
	},
}))

const FormPageEditor = (await import('../../../src/components/page-editor/FormPageEditor.vue')).default

function mountEditor(config = {}) {
	return mount(FormPageEditor, { propsData: { config } })
}

describe('FormPageEditor', () => {
	it('renders the editor title', () => {
		const wrapper = mountEditor()
		expect(wrapper.text()).toContain('Form page')
	})

	it('renders both submit-shape radios', () => {
		const wrapper = mountEditor()
		const radios = wrapper.findAll('input[type="radio"]')
		expect(radios).toHaveLength(2)
	})

	it('submitHandler is the default shape', () => {
		const wrapper = mountEditor()
		expect(wrapper.vm.submitShape).toBe('handler')
	})

	it('config with submitEndpoint reports endpoint shape', () => {
		const wrapper = mountEditor({ submitEndpoint: '/api/objects/x' })
		expect(wrapper.vm.submitShape).toBe('endpoint')
	})

	it('setSubmitHandler clears submitEndpoint (mutex)', async () => {
		const wrapper = mountEditor({ submitEndpoint: '/api/objects/x' })
		wrapper.vm.setSubmitHandler('saveDraft')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.submitHandler).toBe('saveDraft')
		expect(next).not.toHaveProperty('submitEndpoint')
	})

	it('setSubmitEndpoint clears submitHandler (mutex)', async () => {
		const wrapper = mountEditor({ submitHandler: 'saveDraft' })
		wrapper.vm.setSubmitEndpoint('/api/objects/x')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.submitEndpoint).toBe('/api/objects/x')
		expect(next).not.toHaveProperty('submitHandler')
	})

	it('clearing submitHandler with empty string removes it', async () => {
		const wrapper = mountEditor({ submitHandler: 'saveDraft' })
		wrapper.vm.setSubmitHandler('')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('submitHandler')
	})

	it('radio toggle to endpoint clears submitHandler without setting value yet', async () => {
		const wrapper = mountEditor({ submitHandler: 'saveDraft' })
		wrapper.vm.setSubmitShape('endpoint')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('submitHandler')
	})

	it('radio toggle to handler clears submitEndpoint', async () => {
		const wrapper = mountEditor({ submitEndpoint: '/api/x' })
		wrapper.vm.setSubmitShape('handler')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('submitEndpoint')
	})

	it('submitMethod enum forwards POST/PUT/PATCH', async () => {
		const wrapper = mountEditor({})
		for (const method of ['POST', 'PUT', 'PATCH']) {
			wrapper.vm.update('submitMethod', method)
			await wrapper.vm.$nextTick()
		}
		const emissions = wrapper.emitted('update:config')
		expect(emissions[0][0].submitMethod).toBe('POST')
		expect(emissions[1][0].submitMethod).toBe('PUT')
		expect(emissions[2][0].submitMethod).toBe('PATCH')
	})

	it('mode enum forwards public/create/edit', async () => {
		const wrapper = mountEditor({})
		for (const mode of ['public', 'create', 'edit']) {
			wrapper.vm.update('mode', mode)
			await wrapper.vm.$nextTick()
		}
		const emissions = wrapper.emitted('update:config')
		expect(emissions[0][0].mode).toBe('public')
		expect(emissions[1][0].mode).toBe('create')
		expect(emissions[2][0].mode).toBe('edit')
	})

	it('submitLabel + successMessage propagate when set', async () => {
		const wrapper = mountEditor({})
		wrapper.vm.update('submitLabel', 'form.submit.label')
		wrapper.vm.update('successMessage', 'form.success.message')
		await wrapper.vm.$nextTick()
		const emissions = wrapper.emitted('update:config')
		expect(emissions[0][0].submitLabel).toBe('form.submit.label')
		expect(emissions[1][0].successMessage).toBe('form.success.message')
	})

	it('update with empty string deletes the key', async () => {
		const wrapper = mountEditor({ submitLabel: 'foo' })
		wrapper.vm.update('submitLabel', '')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('submitLabel')
	})

	it('FormFieldBuilder add forwards through update:config', async () => {
		const wrapper = mountEditor({ fields: [] })
		const ffb = wrapper.findComponent({ name: 'FormFieldBuilder' })
		ffb.vm.$emit('update:modelValue', [{ key: 'name', label: 'Name', type: 'string' }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.fields).toHaveLength(1)
		expect(next.fields[0].key).toBe('name')
	})
})
