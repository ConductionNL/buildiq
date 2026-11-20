/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the confirmation a form shows after a submit
 * (capability-requirements-backfill T12, REQ-BQCV-012), scenario "A visitor
 * sees the confirmation after submitting", as far as buildiq builds it:
 *
 * - a new form page is public by default, and FormPageEditor writes the
 *   maker's text into `config.successMessage`;
 * - the running app's form page, BuildiqFormPage, hands `successMessage` and
 *   `mode` on to nextcloud-vue's CnFormPage unchanged.
 *
 * Showing the banner after a successful submit and hiding the form in public
 * mode is CnFormPage's own (`v-if="submitted"` / `v-if="!submitted || mode
 * !== 'public'"`) and is not tested again here. The registration-form
 * scenario is asserted in PHPUnit, RegistrationFormConfirmationTextTest.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import PageListEditor from '../../src/components/page-editor/PageListEditor.vue'
import stubDraggable from './stubs/vuedraggable.js'

const THANKS = 'Thank you, we received your request.'

vi.mock('../../src/components/page-editor/fields/FormFieldBuilder.vue', async () => {
	const { h: render } = await import('vue')
	return {
		default: {
			name: 'FormFieldBuilder',
			props: ['modelValue'],
			render() {
				return render('div', { class: 'form-field-builder-stub' })
			},
		},
	}
})

vi.mock('../../src/dialogs/ExternalFormAccessDialog.vue', async () => {
	const { h: render } = await import('vue')
	return {
		default: {
			name: 'ExternalFormAccessDialog',
			props: ['open', 'register', 'schema', 'pageId', 'entry'],
			render() {
				return render('div')
			},
		},
	}
})

vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [],
		fetchSchemas: async () => [],
	}),
}))

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	CnFormPage: {
		name: 'CnFormPage',
		props: {
			fields: { type: Array, default: () => [] },
			successMessage: { type: String, default: '' },
			mode: { type: String, default: 'public' },
		},
		render() {
			return h('form', { class: 'form-stand-in' })
		},
	},
}))

const FormPageEditor = (await import('../../src/components/page-editor/FormPageEditor.vue')).default
const BuildiqFormPage = (await import('../../src/components/runtime/BuildiqFormPage.vue')).default

/**
 * The text input inside the editor label that reads `label`.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label text.
 * @return {object} The input wrapper.
 */
function inputLabelled(wrapper, label) {
	const row = wrapper.findAll('label').find((l) => l.text().startsWith(label))
	return row.find('input')
}

describe('a visitor sees the confirmation after submitting (REQ-BQCV-012)', () => {
	it('adds a form page that is public by default', async () => {
		const wrapper = mount(PageListEditor, {
			props: { pages: [], selectedIndex: -1 },
			global: { stubs: { Draggable: stubDraggable } },
		})
		wrapper.vm.startAdd()
		wrapper.vm.addingType = 'form'
		wrapper.vm.confirmAdd()
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('update:pages')[0][0][0].config.mode).toBe('public')
	})

	it('writes the success message the maker types into config.successMessage', async () => {
		const wrapper = mount(FormPageEditor, { props: { config: { mode: 'public', fields: [] } } })
		await inputLabelled(wrapper, 'Success message').setValue(THANKS)

		expect(wrapper.emitted('update:config').at(-1)[0]).toMatchObject({ mode: 'public', successMessage: THANKS })
	})

	it('shows the saved success message when the page is opened again', () => {
		const wrapper = mount(FormPageEditor, { props: { config: { successMessage: THANKS } } })

		expect(inputLabelled(wrapper, 'Success message').element.value).toBe(THANKS)
	})

	it('hands the success message and the public mode on to CnFormPage', () => {
		const wrapper = mount(BuildiqFormPage, {
			props: { fields: [{ key: 'name', type: 'string', required: true }] },
			attrs: { successMessage: THANKS, mode: 'public' },
		})
		const form = wrapper.findComponent({ name: 'CnFormPage' })

		expect(form.props('successMessage')).toBe(THANKS)
		expect(form.props('mode')).toBe('public')
	})
})
