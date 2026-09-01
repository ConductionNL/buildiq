import { mount } from '@vue/test-utils'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPageEditor's "External access" section
 * (external-form-provisioning REQ-EFP-002).
 */
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
					return h('div')
				},
			},
		}
	},
)
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

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

function mountEditor(propsData) {
	return mount(FormPageEditor, { propsData })
}

describe('FormPageEditor — External access section', () => {
	it('resolves register/schema from an OR-shaped submitEndpoint', () => {
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
		})
		expect(wrapper.vm.externalTarget).toEqual({
			register: 'intake',
			schema: 'report',
		})
	})

	it('is null (disabled) for a handler-shaped form', () => {
		const wrapper = mountEditor({
			config: { submitHandler: 'saveDraft' },
			pageId: 'page-1',
		})
		expect(wrapper.vm.externalTarget).toBeNull()
		expect(wrapper.text()).toContain('External access requires a submitEndpoint')
	})

	it('is null for a non-OR endpoint shape', () => {
		const wrapper = mountEditor({
			config: { submitEndpoint: '/apps/custom/whatever' },
			pageId: 'page-1',
		})
		expect(wrapper.vm.externalTarget).toBeNull()
	})

	it('finds the existing runtime.externalForms entry for this pageId only', () => {
		const forms = [
			{
				id: 'ef-1',
				pageId: 'page-1',
				register: 'intake',
				schema: 'report',
				status: 'enabled',
			},
			{
				id: 'ef-2',
				pageId: 'page-2',
				register: 'other',
				schema: 'x',
				status: 'enabled',
			},
		]
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
			runtimeExternalForms: forms,
		})
		expect(wrapper.vm.externalFormEntry.id).toBe('ef-1')
	})

	it('opens the dialog on Configure click', async () => {
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
		})
		expect(wrapper.vm.externalDialogOpen).toBe(false)
		await wrapper.find('.form-page-editor__external-btn').trigger('click')
		expect(wrapper.vm.externalDialogOpen).toBe(true)
	})

	it('onExternalFormSave appends a new entry when none exists for this page', async () => {
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
			runtimeExternalForms: [],
		})
		const entry = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
		}
		wrapper.vm.onExternalFormSave(entry)
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:runtimeExternalForms')[0][0]
		expect(emitted).toEqual([entry])
	})

	it('onExternalFormSave replaces the existing entry for this page (find-or-append)', async () => {
		const existing = [
			{
				id: 'ef-1',
				pageId: 'page-1',
				register: 'intake',
				schema: 'report',
				status: 'enabled',
			},
		]
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
			runtimeExternalForms: existing,
		})
		const updated = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'disabled',
		}
		wrapper.vm.onExternalFormSave(updated)
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:runtimeExternalForms')[0][0]
		expect(emitted).toEqual([updated])
	})

	it("onExternalFormSave does not disturb another page's entry", async () => {
		const existing = [
			{
				id: 'ef-2',
				pageId: 'page-2',
				register: 'other',
				schema: 'x',
				status: 'enabled',
			},
		]
		const wrapper = mountEditor({
			config: { submitEndpoint: '/api/objects/intake/report' },
			pageId: 'page-1',
			runtimeExternalForms: existing,
		})
		const entry = {
			id: 'ef-1',
			pageId: 'page-1',
			register: 'intake',
			schema: 'report',
			status: 'enabled',
		}
		wrapper.vm.onExternalFormSave(entry)
		await wrapper.vm.$nextTick()
		const emitted = wrapper.emitted('update:runtimeExternalForms')[0][0]
		expect(emitted).toHaveLength(2)
		expect(emitted).toContainEqual(existing[0])
		expect(emitted).toContainEqual(entry)
	})
})
