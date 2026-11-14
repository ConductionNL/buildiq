/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPageEditor's "Fill from pasted text" fieldset
 * (REQ-BQSP-001, REQ-BQSP-002, change ai-smart-paste-into-forms).
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
				props: ['modelValue', 'showLogic'],
				render() {
					return h('div', { class: 'form-field-builder-stub' })
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

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [],
		fetchSchemas: async () => [],
	}),
}))

vi.mock('../../../src/services/formLiveValues.js', () => ({
	listRuleSets: async () => [],
	ruleSetColumns: async () => ({ inputs: [], outputs: [] }),
}))

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

const FIELDS = [
	{ key: 'naam', label: 'Naam', type: 'string' },
	{ key: 'adres', label: 'Adres', type: 'textarea' },
	{ key: 'telefoon', label: 'Telefoon', type: 'string' },
	{ key: 'bijlage', label: 'Bijlage', type: 'file' },
]

function mountEditor(config, provide) {
	return mount(FormPageEditor, { props: { config }, provide })
}

/**
 * The editor re-rendered with the config it last emitted, as the designer does.
 *
 * @param {object} wrapper - the mounted editor.
 * @return {Promise<object>} the emitted config.
 */
async function applyLastEmit(wrapper) {
	const emitted = wrapper.emitted('update:config')
	const next = emitted[emitted.length - 1][0]
	await wrapper.setProps({ config: next })
	return next
}

// @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-a-maker-turns-on-smart-paste-for-chosen-fields-req-bqsp-001
describe('FormPageEditor: fill from pasted text', () => {
	it('lets a maker switch it on for naam, adres and telefoon on a create form', async () => {
		const wrapper = mountEditor({ title: 'Nieuwe klant', mode: 'create', fields: FIELDS, keep: 1 })
		expect(wrapper.find('[data-testid="smart-paste-field-naam"]').exists()).toBe(false)

		await wrapper.find('[data-testid="smart-paste-enabled"]').setValue(true)
		expect(await applyLastEmit(wrapper)).toMatchObject({ smartPaste: { enabled: true, fields: [] }, keep: 1 })

		// Ticked out of order: the setting keeps the form's own field order.
		await wrapper.find('[data-testid="smart-paste-field-telefoon"]').setValue(true)
		await applyLastEmit(wrapper)
		await wrapper.find('[data-testid="smart-paste-field-naam"]').setValue(true)
		await applyLastEmit(wrapper)
		await wrapper.find('[data-testid="smart-paste-field-adres"]').setValue(true)
		const saved = await applyLastEmit(wrapper)

		expect(saved.smartPaste).toEqual({ enabled: true, fields: ['naam', 'adres', 'telefoon'] })
		expect(saved.title).toBe('Nieuwe klant')
	})

	it('offers only fields the fill can set', async () => {
		const wrapper = mountEditor({ mode: 'edit', fields: FIELDS, smartPaste: { enabled: true, fields: [] } })
		expect(wrapper.find('[data-testid="smart-paste-field-adres"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="smart-paste-field-bijlage"]').exists()).toBe(false)
	})

	it('writes the hint, and drops it again when cleared', async () => {
		const wrapper = mountEditor({ mode: 'create', fields: FIELDS, smartPaste: { enabled: true, fields: ['naam'] } })
		await wrapper.find('[data-testid="smart-paste-hint"]').setValue('Paste an email or letter')
		expect((await applyLastEmit(wrapper)).smartPaste).toEqual({ enabled: true, fields: ['naam'], hint: 'Paste an email or letter' })

		await wrapper.find('[data-testid="smart-paste-hint"]').setValue('')
		expect((await applyLastEmit(wrapper)).smartPaste).toEqual({ enabled: true, fields: ['naam'] })
	})

	it('unticking a field and switching off keep the rest of the setting', async () => {
		const wrapper = mountEditor({ mode: 'create', fields: FIELDS, smartPaste: { enabled: true, fields: ['naam', 'adres'] } })
		await wrapper.find('[data-testid="smart-paste-field-naam"]').setValue(false)
		expect((await applyLastEmit(wrapper)).smartPaste.fields).toEqual(['adres'])

		await wrapper.find('[data-testid="smart-paste-enabled"]').setValue(false)
		expect((await applyLastEmit(wrapper)).smartPaste).toEqual({ enabled: false, fields: ['adres'] })
	})

	// @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-smart-paste-is-validated-req-bqsp-002
	it('keeps the switch off on a public form and says why', async () => {
		for (const config of [{ mode: 'public', fields: FIELDS }, { fields: FIELDS }]) {
			const wrapper = mountEditor(config)
			const toggle = wrapper.find('[data-testid="smart-paste-enabled"]')
			expect(toggle.attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-testid="smart-paste-public"]').text()).toBe('Only signed-in forms can use AI.')

			wrapper.vm.setSmartPasteEnabled(true)
			expect(wrapper.emitted('update:config')).toBeUndefined()
			expect(toggle.element.checked).toBe(false)
		}
	})

	it('lets a maker switch it off on a form that became public', async () => {
		const wrapper = mountEditor({ mode: 'public', fields: FIELDS, smartPaste: { enabled: true, fields: ['naam'] } })
		const toggle = wrapper.find('[data-testid="smart-paste-enabled"]')
		expect(toggle.attributes('disabled')).toBeUndefined()
		await toggle.setValue(false)
		expect((await applyLastEmit(wrapper)).smartPaste.enabled).toBe(false)
	})

	it('validates the smartPaste key and shows its mark on the fieldset', () => {
		const validator = {
			register: vi.fn(),
			unregister: vi.fn(),
			errorFor: (key) =>
				key === 'smartPaste'
					? { hasError: true, message: 'buildiq.formLogic.error.smart-paste-unknown-field(telefoon)' }
					: { hasError: false, message: '' },
		}
		const wrapper = mountEditor({ mode: 'create', fields: FIELDS }, { pageEditorValidator: validator })
		expect(validator.register).toHaveBeenCalledWith('smartPaste')
		expect(wrapper.find('[data-testid="form-smart-paste"]').text()).toContain('smart-paste-unknown-field(telefoon)')
	})
})
