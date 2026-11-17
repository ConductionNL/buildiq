/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * JourneyWritesEditor: a write's destination, field mappings and fixed
 * properties are emitted as new arrays; a fixed property can reference the
 * id of a write made before it, and only such writes are offered; the
 * server's findings show on the write they name.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import JourneyWritesEditor from '../../../src/components/journey-editor/JourneyWritesEditor.vue'

const org = { as: 'org', register: 'crm', schema: 'organisation', mapping: { fields: [{ field: 'name', property: 'name' }], fixed: {} } }
const contact = { as: 'contact', register: 'crm', schema: 'contact', mapping: { fields: [], fixed: { organisation: { $write: 'org' }, source: 'portal' } } }

function mountWith(props = {}) {
	return mount(JourneyWritesEditor, { props: { modelValue: [org, contact], fields: ['name', 'email'], earlierWrites: ['person'], ...props } })
}

function lastEmit(wrapper) {
	const emits = wrapper.emitted('update:modelValue')
	return emits[emits.length - 1][0]
}

describe('JourneyWritesEditor', () => {
	it('shows each write with its destination and mappings', () => {
		const wrapper = mountWith()
		const writes = wrapper.findAll('.journey-writes__write')

		expect(writes).toHaveLength(2)
		expect(writes[0].find('[data-testid="journey-write-register"]').element.value).toBe('crm')
		expect(writes[0].find('[data-testid="journey-write-schema"]').element.value).toBe('organisation')
		expect(writes[0].find('[data-testid="journey-write-field"] select').element.value).toBe('name')
		expect(writes[1].findAll('[data-testid="journey-write-fixed"]')).toHaveLength(2)
		expect(writes[1].find('[data-testid="journey-write-ref"]').element.value).toBe('org')
	})

	it('offers earlier steps\' writes and the writes before it in this step, not later ones', () => {
		const wrapper = mountWith()
		const writes = wrapper.findAll('.journey-writes__write')
		const offered = (w) => w.find('[data-testid="journey-write-ref"]').findAll('option').map((o) => o.element.value)

		expect(offered(writes[1])).toEqual(['', 'person', 'org'])
		expect(wrapper.vm.writesBefore(0)).toEqual(['person'])
	})

	it('emits a changed destination without touching the other write', async () => {
		const wrapper = mountWith()

		await wrapper.findAll('[data-testid="journey-write-schema"]')[0].setValue('company')
		expect(lastEmit(wrapper)).toEqual([{ ...org, schema: 'company' }, contact])
		expect(org.schema).toBe('organisation')
	})

	it('maps an answered field onto a destination property', async () => {
		const wrapper = mountWith({ modelValue: [{ as: 'p', register: 'r', schema: 's' }] })

		await wrapper.find('[data-testid="journey-write-add-field"]').trigger('click')
		expect(lastEmit(wrapper)[0].mapping).toEqual({ fields: [{ field: '', property: '' }], fixed: {} })

		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="journey-write-field"] select').setValue('email')
		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await wrapper.find('[data-testid="journey-write-field"] input').setValue('mail')
		expect(lastEmit(wrapper)[0].mapping.fields).toEqual([{ field: 'email', property: 'mail' }])
	})

	it('sets a fixed property to the id of an earlier write and back to a value', async () => {
		const wrapper = mountWith({ modelValue: [org, { ...contact, mapping: { fields: [], fixed: { organisation: '' } } }] })
		const second = () => wrapper.findAll('.journey-writes__write')[1]

		await second().find('[data-testid="journey-write-ref"]').setValue('org')
		expect(lastEmit(wrapper)[1].mapping.fixed).toEqual({ organisation: { $write: 'org' } })

		await wrapper.setProps({ modelValue: lastEmit(wrapper) })
		await second().find('[data-testid="journey-write-ref"]').setValue('')
		expect(lastEmit(wrapper)[1].mapping.fixed).toEqual({ organisation: '' })
	})

	it('adds and renames a fixed property in place', async () => {
		const wrapper = mountWith({ modelValue: [{ as: 'p', mapping: { fields: [], fixed: { a: 1, property2: 2 } } }] })

		await wrapper.find('[data-testid="journey-write-add-fixed"]').trigger('click')
		expect(Object.keys(lastEmit(wrapper)[0].mapping.fixed)).toEqual(['a', 'property2', 'property3'])

		await wrapper.findAll('[data-testid="journey-write-fixed"] input')[0].setValue('b')
		expect(lastEmit(wrapper)[0].mapping.fixed).toEqual({ b: 1, property2: 2 })
	})

	it('shows the server findings on the write they name only', () => {
		const findings = [{ step: 'a', write: 'contact', property: 'nope', code: 'property-unknown', message: 'Property nope is not on contact.' }]
		const wrapper = mountWith({ findings })
		const writes = wrapper.findAll('.journey-writes__write')

		expect(writes[0].find('[data-testid="journey-write-finding"]').exists()).toBe(false)
		expect(writes[1].find('[data-testid="journey-write-finding"]').text()).toBe('Property nope is not on contact.')
	})

	it('adds and removes writes', async () => {
		const wrapper = mountWith({ modelValue: [] })

		expect(wrapper.find('.journey-writes__empty').exists()).toBe(true)
		await wrapper.find('[data-testid="journey-write-add"]').trigger('click')
		expect(lastEmit(wrapper)).toEqual([{ as: '', register: '', schema: '', mapping: { fields: [], fixed: {} } }])

		await wrapper.setProps({ modelValue: [org, contact] })
		await wrapper.findAll('[data-testid="journey-write-remove"]')[0].trigger('click')
		expect(lastEmit(wrapper)).toEqual([contact])
	})

	describe('a write repeated per product (forms-multi-product-request)', () => {
		const bundle = { as: 'bundel', register: 'zaken', schema: 'aanvraagbundel', mapping: { fields: [], fixed: {} } }
		const request = {
			as: 'aanvraag',
			register: 'zaken',
			schema: 'zaak',
			forEach: 'producten',
			targetBy: 'product',
			targets: {
				parkeervergunning: { register: 'zaken', schema: 'zaak', typeValue: 'PV' },
				afvalcontainer: { register: 'zaken', schema: 'zaak-afval', typeValue: 'AC' },
			},
			mapping: { fields: [], fixed: {} },
		}
		const listFields = [{ key: 'producten', columns: ['product', 'toelichting'] }]
		const flows = [{ name: 'Parkeervergunning behandelen', trigger: { type: 'object.created', register: 'zaken', schema: 'zaak' } }]

		function repeating(props = {}) {
			return mount(JourneyWritesEditor, { props: { modelValue: [bundle, request], fields: ['naam'], listFields, flows, ...props } })
		}

		it('repeats a write over a list answer and maps the item\'s own fields', async () => {
			const wrapper = mount(JourneyWritesEditor, { props: { modelValue: [{ ...bundle }], fields: ['naam'], listFields } })
			const options = wrapper.find('[data-testid="journey-write-foreach"]').findAll('option').map((o) => o.attributes('value'))
			expect(options).toEqual(['', 'producten'])

			await wrapper.find('[data-testid="journey-write-foreach"]').setValue('producten')
			expect(lastEmit(wrapper)[0]).toMatchObject({ forEach: 'producten', bundle: false })

			await wrapper.setProps({ modelValue: [{ ...bundle, forEach: 'producten', mapping: { fields: [{ field: '', property: 'omschrijving' }], fixed: {} } }] })
			const fieldOptions = wrapper.find('[data-testid="journey-write-field"] select').findAll('option').map((o) => o.attributes('value'))
			expect(fieldOptions).toEqual(['', 'naam', 'item.product', 'item.toelichting'])

			await wrapper.find('[data-testid="journey-write-field"] select').setValue('item.toelichting')
			expect(lastEmit(wrapper)[0].mapping.fields).toEqual([{ field: 'item.toelichting', property: 'omschrijving' }])
		})

		it('writes once again without its targets', async () => {
			const wrapper = repeating()
			await wrapper.findAll('[data-testid="journey-write-foreach"]')[1].setValue('')
			const once = lastEmit(wrapper)[1]
			expect(once).not.toHaveProperty('forEach')
			expect(once).not.toHaveProperty('targetBy')
			expect(once).not.toHaveProperty('targets')
		})

		it('edits the target of each product', async () => {
			const wrapper = repeating()
			const rows = wrapper.findAll('[data-testid="journey-write-target"]')
			expect(rows).toHaveLength(2)

			await rows[1].find('[data-testid="journey-write-target-typeValue"]').setValue('AFV')
			expect(lastEmit(wrapper)[1].targets.afvalcontainer).toEqual({ register: 'zaken', schema: 'zaak-afval', typeValue: 'AFV' })

			await wrapper.find('[data-testid="journey-write-add-target"]').trigger('click')
			expect(Object.keys(lastEmit(wrapper)[1].targets)).toEqual(['parkeervergunning', 'afvalcontainer', 'product3'])

			await rows[0].find('button').trigger('click')
			expect(Object.keys(lastEmit(wrapper)[1].targets)).toEqual(['afvalcontainer'])
		})

		it('keeps one bundle: marking a write unmarks the other', async () => {
			const wrapper = mount(JourneyWritesEditor, { props: { modelValue: [{ ...bundle, bundle: true }, { ...org }] } })
			await wrapper.findAll('[data-testid="journey-write-bundle"]')[1].setValue(true)
			expect(lastEmit(wrapper).map((w) => w.bundle)).toEqual([false, true])
		})

		it('says which product starts no process', () => {
			const wrapper = repeating()
			const rows = wrapper.findAll('[data-testid="journey-write-target"]')
			expect(rows[0].find('[data-testid="journey-write-flows"]').exists()).toBe(true)
			expect(wrapper.vm.creationFlowsOf(wrapper.vm.targetRows(request)[0]).map(wrapper.vm.flowName)).toEqual(['Parkeervergunning behandelen'])
			expect(rows[0].find('[data-testid="journey-write-no-process"]').exists()).toBe(false)
			expect(rows[1].find('[data-testid="journey-write-no-process"]').text()).toBe('This product starts no process.')
		})

		it('warns about nothing while the flows are not read', () => {
			const wrapper = repeating({ flows: null })
			expect(wrapper.find('[data-testid="journey-write-no-process"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="journey-write-flows"]').exists()).toBe(false)
		})
	})
})
