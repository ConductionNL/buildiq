/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RegisterSchemaFields: the register and schema selects list
 * what the editor loaded, the schema select stays disabled until a register
 * is chosen, a pick is emitted as `register` / `schema`, and the injected
 * validator marks each select.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import RegisterSchemaFields from '../../../src/components/page-editor/fields/RegisterSchemaFields.vue'

const registers = [{ slug: 'cases', title: 'Cases' }, { slug: 'people' }]
const schemas = [{ slug: 'case', title: 'Case' }, { slug: 'note', id: 7 }]

function selects(wrapper) {
	const [register, schema] = wrapper.findAll('select')
	return { register, schema }
}

describe('RegisterSchemaFields', () => {
	it('lists the loaded registers and schemas after an empty option, by title or slug', () => {
		const { register, schema } = selects(mount(RegisterSchemaFields, { props: { registers, schemas } }))

		expect(register.findAll('option').map((o) => [o.attributes('value'), o.text()])).toEqual([
			['', '— select register —'],
			['cases', 'Cases'],
			['people', 'people'],
		])
		expect(schema.findAll('option').map((o) => [o.attributes('value'), o.text()])).toEqual([
			['', '— select schema —'],
			['case', 'Case'],
			['note', 'note'],
		])
	})

	it('keeps the schema select disabled until a register is chosen', () => {
		const empty = selects(mount(RegisterSchemaFields, { props: { registers, schemas } }))
		expect(empty.schema.attributes('disabled')).toBeDefined()

		const chosen = selects(mount(RegisterSchemaFields, {
			props: { registers, schemas, config: { register: 'cases', schema: 'case' } },
		}))
		expect(chosen.schema.attributes('disabled')).toBeUndefined()
		expect(chosen.register.element.value).toBe('cases')
		expect(chosen.schema.element.value).toBe('case')
	})

	it('emits the picked register and schema slugs', async () => {
		const wrapper = mount(RegisterSchemaFields, { props: { registers, schemas, config: { register: 'cases' } } })
		const { register, schema } = selects(wrapper)

		await register.setValue('people')
		await schema.setValue('note')

		expect(wrapper.emitted('register')).toEqual([['people']])
		expect(wrapper.emitted('schema')).toEqual([['note']])
	})

	it('marks each select from the injected validator', () => {
		const wrapper = mount(RegisterSchemaFields, {
			props: { registers, schemas, config: { register: 'cases' } },
			global: {
				provide: {
					pageEditorValidator: {
						register: () => {},
						unregister: () => {},
						errorFor: (key) => (key === 'schema'
							? { hasError: true, message: 'Pick a schema' }
							: { hasError: false, message: '' }),
					},
				},
			},
		})
		const { register, schema } = selects(wrapper)

		expect(register.attributes('aria-invalid')).toBe('false')
		expect(schema.attributes('aria-invalid')).toBe('true')
		expect(wrapper.text()).toContain('Pick a schema')
	})

	it('marks nothing without a validator', () => {
		const { register, schema } = selects(mount(RegisterSchemaFields))

		expect(register.attributes('aria-invalid')).toBe('false')
		expect(schema.attributes('aria-invalid')).toBe('false')
	})
})
