/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Value source" section of the field editor: a string or object field
 * can take its value from a registry integriq publishes, written as
 * `x-openregister-property-source` (data-registry-backed-field-option).
 *
 * @spec openspec/changes/data-registry-backed-field-option/specs/schema-designer-ui/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FieldEditor, {
	fieldsToSchema,
	schemaToFields,
} from '../../../src/components/schema-editor/FieldEditor.vue'

const stubs = {
	NcButton: { name: 'NcButton', template: '<button><slot name="icon" /><slot /></button>' },
	NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], template: '<input />' },
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options', 'clearable'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' },
	DeleteFieldDialog: { name: 'DeleteFieldDialog', template: '<div />' },
}

/** A `t` that fills placeholders, so the keyed-on line can be read. */
const mocks = {
	t: (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (all, name) => (name in vars ? vars[name] : all)),
}

const AVAILABLE = {
	loaded: true,
	available: true,
	results: [{ id: 'kvk', label: 'KvK organisation', identifier: 'kvkNummer' }],
}
const UNAVAILABLE = { loaded: true, available: false, results: [] }

/**
 * Mount the editor over the given properties.
 *
 * @param {object} properties The schema properties.
 * @param {object} propertySources The provider list.
 * @return {object} The wrapper.
 */
function mountWith(properties, propertySources = AVAILABLE) {
	return mount(FieldEditor, {
		props: { fields: schemaToFields({ properties }), propertySources },
		global: { stubs, mocks },
	})
}

/**
 * The fields the editor emitted last.
 *
 * @param {object} wrapper The wrapper.
 * @return {Array} The rows.
 */
function lastFields(wrapper) {
	const emitted = wrapper.emitted('update:fields')
	return emitted[emitted.length - 1][0]
}

describe('FieldEditor value source', () => {
	it('declares a KvK field, live, with an empty config', async () => {
		const wrapper = mountWith({ kvkNummer: { type: 'string', title: 'KvK-nummer' } })

		await wrapper.find('input[value="registry"]').trigger('change')
		await wrapper.setProps({ fields: lastFields(wrapper) })
		const registry = wrapper.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === 'Registry')
		expect(registry.props('options')).toEqual([{ value: 'kvk', label: 'KvK organisation', subname: 'keyed on kvkNummer' }])
		registry.vm.$emit('update:modelValue', { value: 'kvk' })
		await wrapper.setProps({ fields: lastFields(wrapper) })

		expect(fieldsToSchema(lastFields(wrapper)).properties.kvkNummer).toEqual({
			title: 'KvK-nummer',
			type: 'string',
			'x-openregister-property-source': { provider: 'kvk', mode: 'live', config: {} },
		})
		expect(wrapper.find('[data-testid="field-source-summary-kvkNummer"]').text()).toBe('Format: KvK organisation, live')
	})

	it('writes the starting-value mode and carries an existing config through', async () => {
		const wrapper = mountWith({
			adres: { type: 'object', 'x-openregister-property-source': { provider: 'bag', mode: 'live', config: { x: 1 } } },
		})

		await wrapper.find('input[value="default"]').trigger('change')

		expect(fieldsToSchema(lastFields(wrapper)).properties.adres['x-openregister-property-source']).toEqual({
			provider: 'bag',
			mode: 'default',
			config: { x: 1 },
		})
	})

	it('going back to typed in removes the declaration', async () => {
		const wrapper = mountWith({
			kvkNummer: { type: 'string', 'x-openregister-property-source': { provider: 'kvk', mode: 'live', config: {} } },
		})

		await wrapper.find('input[value="typed"]').trigger('change')

		expect(fieldsToSchema(lastFields(wrapper)).properties.kvkNummer).toEqual({ type: 'string' })
	})

	it('offers no section for a number, and changing to integer drops the declaration', async () => {
		const wrapper = mountWith({ aantal: { type: 'integer' } })
		expect(wrapper.find('[data-testid="field-source-aantal"]').exists()).toBe(false)

		const declared = mountWith({
			kvkNummer: { type: 'string', 'x-openregister-property-source': { provider: 'kvk', mode: 'live', config: {} } },
		})
		declared.vm.updateField(0, 'type', 'integer')
		expect(fieldsToSchema(lastFields(declared)).properties.kvkNummer).toEqual({ type: 'integer' })
	})

	it('without integriq shows the note and the source read-only, and a save keeps it', () => {
		const declaration = { provider: 'bag', mode: 'default', config: { x: 1 } }
		const properties = {
			naam: { type: 'string', description: 'old' },
			adres: { type: 'string', 'x-openregister-property-source': declaration },
		}
		const wrapper = mountWith(properties, UNAVAILABLE)

		expect(wrapper.find('[data-testid="field-source-adres"]').text()).toContain('Registry lookups need the integriq app.')
		expect(wrapper.find('input[value="registry"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="field-source-summary-adres"]').text()).toBe('Format: bag, starting value')

		wrapper.vm.updateField(0, 'description', 'new')
		expect(fieldsToSchema(lastFields(wrapper)).properties.adres['x-openregister-property-source']).toEqual(declaration)
	})

	it('carries a declaration that is not an object through untouched', () => {
		const fields = schemaToFields({ properties: { odd: { type: 'string', 'x-openregister-property-source': 'kvk' } } })

		expect(fieldsToSchema(fields).properties.odd['x-openregister-property-source']).toBe('kvk')
	})

	it('shows a refusal on the field it names', () => {
		const wrapper = mount(FieldEditor, {
			props: {
				fields: schemaToFields({ properties: { kvkNummer: { type: 'string' }, naam: { type: 'string' } } }),
				propertySources: AVAILABLE,
				sourceRefusals: { kvkNummer: ["'x-openregister-property-source' at 'kvkNummer' must name a provider."] },
			},
			global: { stubs, mocks },
		})

		expect(wrapper.find('[data-testid="field-source-kvkNummer"] [role="alert"]').text()).toContain('must name a provider')
		expect(wrapper.find('[data-testid="field-source-naam"] [role="alert"]').exists()).toBe(false)
	})
})
