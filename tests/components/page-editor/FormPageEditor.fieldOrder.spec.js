/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for building a form from the bound schema's properties
 * (capability-requirements-backfill T24, REQ-BQCV-024): the form page editor
 * offers the properties of the schema the form saves into, adds a picked one
 * as a field, removes and reorders fields, and the page config it saves holds
 * the fields in that order. The running form page hands CnFormPage the fields
 * as saved; drawing them is nextcloud-vue's.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-picks-and-orders-the-fields-of-a-form-page-req-bqcv-024
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const SCHEMA = {
	id: 12,
	slug: 'contact',
	title: 'Contact',
	properties: {
		name: { type: 'string', title: 'Name' },
		email: { type: 'string', title: 'Email' },
		phone: { type: 'string' },
		note: { type: 'string', title: 'Note' },
	},
}

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [{ slug: 'crm', title: 'CRM' }],
		fetchSchemas: async () => [SCHEMA],
		fetchSchemaProperties: async () => SCHEMA.properties,
		fetchDataSources: async () => ({ registers: [] }),
		resolveAppRegister: () => 'crm',
	}),
}))

vi.mock('../../../src/dialogs/ExternalFormAccessDialog.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'ExternalFormAccessDialog',
			props: ['open', 'register', 'schema', 'pageId', 'entry', 'formConfig', 'appSlug'],
			render() {
				return h('div')
			},
		},
	}
})

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => {
	const { h } = await import('vue')
	return {
		...(await importOriginal()),
		CnFormPage: {
			name: 'CnFormPage',
			props: { fields: { type: Array, default: () => [] } },
			render() {
				return h('form', this.fields.map((field) => h('label', { 'data-key': field.key }, field.label)))
			},
		},
	}
})

const FormPageEditor = (await import('../../../src/components/page-editor/FormPageEditor.vue')).default
const BuildiqFormPage = (await import('../../../src/components/runtime/BuildiqFormPage.vue')).default

const BOUND = { submitEndpoint: '/api/objects/crm/contact', mode: 'create' }

/**
 * Mount the editor on a form page bound to the contact schema, playing the
 * page designer: each emitted config goes back in as the new prop.
 *
 * @param {object} config - the page config.
 * @return {Promise<object>} the wrapper.
 */
async function editor(config = BOUND) {
	const wrapper = mount(FormPageEditor, {
		props: {
			config,
			'onUpdate:config': (next) => wrapper.setProps({ config: next }),
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The add-a-property control.
 *
 * @param {object} wrapper - the editor.
 * @return {object} the select.
 */
function addControl(wrapper) {
	return wrapper.find('[data-testid="form-field-add-property"]')
}

/**
 * Pick a property in the add control.
 *
 * @param {object} wrapper - the editor.
 * @param {string} key - the property.
 * @return {Promise<void>}
 */
async function addProperty(wrapper, key) {
	await addControl(wrapper).setValue(key)
	await flushPromises()
}

/**
 * The saved field keys.
 *
 * @param {object} wrapper - the editor.
 * @return {string[]}
 */
function savedKeys(wrapper) {
	return (wrapper.props('config').fields || []).map((field) => field.key)
}

describe('FormPageEditor builds a form from chosen fields (REQ-BQCV-024)', () => {
	it('offers every property of the bound schema', async () => {
		const wrapper = await editor()
		const options = addControl(wrapper).findAll('option').map((option) => option.element.value)
		expect(options).toEqual(['', 'name', 'email', 'phone', 'note'])
	})

	it('adds name, email and note, moves note above email, and saves them in that order without phone', async () => {
		const wrapper = await editor()
		await addProperty(wrapper, 'name')
		await addProperty(wrapper, 'email')
		await addProperty(wrapper, 'note')
		expect(savedKeys(wrapper)).toEqual(['name', 'email', 'note'])

		const upButtons = wrapper.findAll('button[aria-label="Move field up"]')
		await upButtons[2].trigger('click')
		await flushPromises()

		const config = wrapper.props('config')
		expect(config.fields).toEqual([
			{ key: 'name', label: 'Name', type: 'string' },
			{ key: 'note', label: 'Note', type: 'string' },
			{ key: 'email', label: 'Email', type: 'string' },
		])
		expect(savedKeys(wrapper)).not.toContain('phone')
		expect(config.submitEndpoint).toBe('/api/objects/crm/contact')
		// A property on the form is no longer offered; phone still is.
		const left = addControl(wrapper).findAll('option').map((option) => option.element.value)
		expect(left).toEqual(['', 'phone'])
	})

	it('removes a field, which offers its property again', async () => {
		const wrapper = await editor({
			...BOUND,
			fields: [
				{ key: 'name', label: 'Name', type: 'string' },
				{ key: 'phone', label: 'phone', type: 'string' },
			],
		})
		await wrapper.findAll('.form-field-builder__remove')[1].trigger('click')
		await flushPromises()
		expect(savedKeys(wrapper)).toEqual(['name'])
		expect(addControl(wrapper).findAll('option').map((option) => option.element.value)).toContain('phone')
	})

	it('gives a field a type that fits its property', async () => {
		const typed = {
			...SCHEMA,
			properties: {
				age: { type: 'integer' },
				active: { type: 'boolean' },
				born: { type: 'string', format: 'date' },
			},
		}
		const wrapper = await editor()
		await wrapper.setData({ schemas: [typed] })
		await addProperty(wrapper, 'age')
		await addProperty(wrapper, 'active')
		await addProperty(wrapper, 'born')
		expect(wrapper.props('config').fields).toEqual([
			{ key: 'age', label: 'age', type: 'number' },
			{ key: 'active', label: 'active', type: 'boolean' },
			{ key: 'born', label: 'born', type: 'date' },
		])
	})

	it('offers no properties while the form saves into no schema', async () => {
		const wrapper = await editor({ submitHandler: 'myHandler' })
		expect(addControl(wrapper).exists()).toBe(false)
	})

	it('the running form shows the saved fields in their order', () => {
		const fields = [
			{ key: 'name', label: 'Name', type: 'string' },
			{ key: 'note', label: 'Note', type: 'string' },
			{ key: 'email', label: 'Email', type: 'string' },
		]
		const page = mount(BuildiqFormPage, { props: { fields } })
		expect(page.findAll('label').map((label) => label.attributes('data-key'))).toEqual(['name', 'note', 'email'])
	})
})
