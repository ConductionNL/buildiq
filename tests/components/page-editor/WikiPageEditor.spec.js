/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for WikiPageEditor (REQ-PEC-006).
 *
 * Covers:
 *  - Empty register/schema render error marks with aria-invalid
 *    (REQ-PEC-007 "Wiki register and schema are marked invalid when empty").
 *  - With schema properties body/title/children returned by the picker
 *    mock, the four field-mapping dropdowns list them and an untouched
 *    mount emits no field-mapping keys (REQ-PEC-007 "Wiki field mappings
 *    offer schema properties once bound").
 *  - An unsurfaced key survives a titleField edit.
 *  - validatedConfigKeys matches the thirteen pinned keys.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const fetchRegisters = vi.fn(async () => [
	{ slug: 'openbuild-hello-world', title: 'Hello World' },
])
const fetchSchemas = vi.fn(async () => [{ slug: 'articles', title: 'Articles' }])
const fetchSchemaProperties = vi.fn(async () => ({
	body: { type: 'string' },
	title: { type: 'string' },
	children: { type: 'array' },
}))

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters,
		fetchSchemas,
		fetchSchemaProperties,
		resolveAppRegister: () => '',
	}),
}))

const WikiPageEditor = (
	await import('../../../src/components/page-editor/WikiPageEditor.vue')
).default

function mountEditor(config = {}) {
	return mount(WikiPageEditor, { propsData: { config, appSlug: 'hello-world' } })
}

describe('WikiPageEditor', () => {
	beforeEach(() => {
		fetchRegisters.mockClear()
		fetchSchemas.mockClear()
		fetchSchemaProperties.mockClear()
	})

	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Wiki page')
	})

	it('calls fetchRegisters on mount', async () => {
		mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchRegisters).toHaveBeenCalled()
	})

	it('empty register and schema render invalid marks with aria-invalid', () => {
		const wrapper = mountEditor({})
		expect(wrapper.vm.registerMark.hasError).toBe(true)
		expect(wrapper.vm.schemaMark.hasError).toBe(true)
		const selects = wrapper.findAll('select')
		expect(selects.at(0).attributes('aria-invalid')).toBe('true')
		expect(selects.at(1).attributes('aria-invalid')).toBe('true')
	})

	it('bound register + schema clear the invalid marks', () => {
		const wrapper = mountEditor({
			register: 'openbuild-hello-world',
			schema: 'articles',
		})
		expect(wrapper.vm.registerMark.hasError).toBe(false)
		expect(wrapper.vm.schemaMark.hasError).toBe(false)
	})

	it('binding a register + schema whose schema declares body/title/children lists them in the field-mapping dropdowns', async () => {
		const wrapper = mountEditor({
			register: 'openbuild-hello-world',
			schema: 'articles',
		})
		await new Promise((r) => setTimeout(r, 0))
		await wrapper.vm.$nextTick()
		expect(fetchSchemaProperties).toHaveBeenCalledWith(
			'openbuild-hello-world',
			'articles',
		)
		expect(wrapper.vm.schemaPropertyKeys).toEqual(['body', 'title', 'children'])
		expect(wrapper.vm.hasBoundSchema).toBe(true)
	})

	it('an untouched mount emits no field-mapping keys', async () => {
		const wrapper = mountEditor({
			register: 'openbuild-hello-world',
			schema: 'articles',
		})
		await new Promise((r) => setTimeout(r, 0))
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')).toBeUndefined()
	})

	it('an unsurfaced key survives a titleField edit', async () => {
		const wrapper = mountEditor({
			register: 'r',
			schema: 's',
			extraThing: { keep: true },
		})
		wrapper.vm.update('titleField', 'headline')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.titleField).toBe('headline')
		expect(next.extraThing).toEqual({ keep: true })
	})

	it('updateRegister resets schema (partner-clear)', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.updateRegister('r2')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.register).toBe('r2')
		expect(next).not.toHaveProperty('schema')
	})

	it('updateSidebarRegister resets sidebarSchema (partner-clear)', async () => {
		const wrapper = mountEditor({ sidebarRegister: 'r', sidebarSchema: 's' })
		wrapper.vm.updateSidebarRegister('r2')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.sidebarRegister).toBe('r2')
		expect(next).not.toHaveProperty('sidebarSchema')
	})

	it('validatedConfigKeys matches the pinned thirteen keys', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual([
			'register',
			'schema',
			'contentField',
			'titleField',
			'idParam',
			'treeField',
			'sidebarTitleField',
			'sidebarRegister',
			'sidebarSchema',
			'emptyText',
			'emptyDescription',
			'emptyBodyText',
			'emptyBodyDescription',
		])
		expect(mountEditor().vm.validatedConfigKeys).toHaveLength(13)
	})
})
