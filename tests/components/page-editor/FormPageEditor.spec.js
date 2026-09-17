/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
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

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

// `vi.mock` factories are hoisted above the imports, so `h` is pulled in with
// a lazy dynamic import inside the (async) factory. Vue 3 does not pass `h`
// into render(), and vnode classes use `class`, not Vue 2's `staticClass`.
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

// External access (REQ-EFP-002) pulls in the real NcDialog tree via
// ExternalFormAccessDialog; stubbed here so these submit-shape/config tests
// stay isolated. See FormPageEditor.externalAccess.spec.js for the dialog wiring.
vi.mock('../../../src/dialogs/ExternalFormAccessDialog.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'ExternalFormAccessDialog',
			props: ['open', 'register', 'schema', 'pageId', 'entry'],
			render() {
				return h('div', { class: 'external-form-access-dialog-stub' })
			},
		},
	}
})

const fetchRegistersMock = vi.fn(async () => [
	{ slug: 'openbuild-shop-development', title: 'Shop (development)' },
])
const fetchSchemasMock = vi.fn(async () => [{ slug: 'order', title: 'Order' }])
vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: fetchRegistersMock,
		fetchSchemas: fetchSchemasMock,
	}),
}))

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

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

	it('a new form saves to a register by default', () => {
		const wrapper = mountEditor()
		expect(wrapper.vm.submitShape).toBe('endpoint')
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
		ffb.vm.$emit('update:modelValue', [
			{ key: 'name', label: 'Name', type: 'string' },
		])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.fields).toHaveLength(1)
		expect(next.fields[0].key).toBe('name')
	})

	describe('saving into a register', () => {
		it('keeps the register choice after switching away from a handler', async () => {
			// Regression: switching clears submitHandler, which left neither key
			// set, and the editor fell back to handler mode. The URL field never
			// appeared, so a form could not be pointed at a register.
			const wrapper = mountEditor({ submitHandler: 'createOrder' })
			await wrapper.findAll('input[type="radio"]')[0].setValue(true)
			const emitted = wrapper.emitted('update:config').at(-1)[0]
			expect(emitted).not.toHaveProperty('submitHandler')
			await wrapper.setProps({ config: emitted })
			expect(wrapper.vm.submitShape).toBe('endpoint')
			expect(wrapper.find('select.form-page-editor__register').exists()).toBe(
				true,
			)
		})

		it('keeps the handler choice when a new form switches to it', async () => {
			const wrapper = mountEditor({})
			await wrapper.findAll('input[type="radio"]')[1].setValue(true)
			await wrapper.setProps({
				config: wrapper.emitted('update:config').at(-1)[0],
			})
			expect(wrapper.vm.submitShape).toBe('handler')
		})

		it('writes the objects URL once a register and schema are picked', async () => {
			const wrapper = mountEditor({})
			await flushPromises()
			const register = wrapper.find('select.form-page-editor__register')
			expect(register.findAll('option')).toHaveLength(2)
			await register.setValue('openbuild-shop-development')
			await flushPromises()
			expect(fetchSchemasMock).toHaveBeenCalledWith('openbuild-shop-development')
			await wrapper.find('select.form-page-editor__schema').setValue('order')
			expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
				submitEndpoint:
					'/apps/openregister/api/objects/openbuild-shop-development/order',
			})
		})

		it('reads the register and schema back from a stored URL', async () => {
			const wrapper = mountEditor({
				submitEndpoint:
					'/apps/openregister/api/objects/openbuild-shop-development/order',
			})
			await flushPromises()
			expect(wrapper.vm.targetRegister).toBe('openbuild-shop-development')
			expect(wrapper.vm.targetSchema).toBe('order')
			expect(wrapper.vm.externalTarget).toEqual({
				register: 'openbuild-shop-development',
				schema: 'order',
			})
		})
	})
})
