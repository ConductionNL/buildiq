/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SearchPageEditor (REQ-PEC-005).
 *
 * Covers:
 *  - Adding a facet row with key + two options emits the facets[] shape
 *    { key, options: [{ value }, { value }] }.
 *  - An unsurfaced key (e.g. facetsTitle) survives a placeholder edit.
 *  - Register change resets schema (same partner-clear as LogsPageEditor).
 *  - The consumer-wiring (@search) hint renders.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchRegisters = vi.fn(async () => [
	{ slug: 'openbuild-hello-world', title: 'Hello World' },
])
const fetchSchemas = vi.fn(async () => [{ slug: 'articles', title: 'Articles' }])

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters,
		fetchSchemas,
		fetchSchemaProperties: vi.fn(async () => ({})),
		resolveAppRegister: () => '',
	}),
}))

const SearchPageEditor = (
	await import('../../../src/components/page-editor/SearchPageEditor.vue')
).default

function mountEditor(config = {}) {
	return mount(SearchPageEditor, { propsData: { config, appSlug: 'hello-world' } })
}

describe('SearchPageEditor', () => {
	beforeEach(() => {
		fetchRegisters.mockClear()
		fetchSchemas.mockClear()
	})

	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Search page')
	})

	it('renders the consumer-wiring (@search) hint', () => {
		expect(mountEditor().text()).toContain('@search')
	})

	it('calls fetchRegisters on mount', async () => {
		mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchRegisters).toHaveBeenCalled()
	})

	it('adding a facet row with key + two options emits the facets[] shape', async () => {
		const wrapper = mountEditor({ facets: [] })
		wrapper.vm.addFacet()
		await wrapper.vm.$nextTick()
		let next = wrapper.emitted('update:config')[0][0]
		await wrapper.setProps({ config: next })

		wrapper.vm.updateFacetField(0, 'key', 'category')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		await wrapper.setProps({ config: next })

		wrapper.vm.addFacetOption(0)
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		await wrapper.setProps({ config: next })

		wrapper.vm.updateFacetOptionField(0, 0, 'value', 'books')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		await wrapper.setProps({ config: next })

		wrapper.vm.addFacetOption(0)
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]
		await wrapper.setProps({ config: next })

		wrapper.vm.updateFacetOptionField(0, 1, 'value', 'films')
		await wrapper.vm.$nextTick()
		next = wrapper.emitted('update:config').slice(-1)[0][0]

		expect(next.facets).toEqual([
			{ key: 'category', options: [{ value: 'books' }, { value: 'films' }] },
		])
	})

	it('an unsurfaced key (facetsTitle) survives a placeholder edit', async () => {
		const wrapper = mountEditor({ facetsTitle: 'Filters' })
		wrapper.vm.update('placeholder', 'Search everything…')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.placeholder).toBe('Search everything…')
		expect(next.facetsTitle).toBe('Filters')
	})

	it('register change resets schema (partner-clear)', async () => {
		const wrapper = mountEditor({
			register: 'openbuild-hello-world',
			schema: 'old-schema',
		})
		wrapper.vm.updateRegister('another-register')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.register).toBe('another-register')
		expect(next).not.toHaveProperty('schema')
	})

	it('clearing register deletes both register and schema', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.updateRegister('')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('register')
		expect(next).not.toHaveProperty('schema')
	})

	it('removeFacet removes a row', async () => {
		const wrapper = mountEditor({ facets: [{ key: 'a' }, { key: 'b' }] })
		wrapper.vm.removeFacet(0)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.facets).toEqual([{ key: 'b' }])
	})

	it('multiple checkbox writes an explicit boolean on the facet row', async () => {
		const wrapper = mountEditor({ facets: [{ key: 'a' }] })
		wrapper.vm.updateFacetField(0, 'multiple', true)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.facets[0].multiple).toBe(true)
	})

	it('validatedConfigKeys matches the surfaced keys', () => {
		expect(mountEditor().vm.validatedConfigKeys).toEqual([
			'register',
			'schema',
			'title',
			'placeholder',
			'searchLabel',
			'idleLabel',
			'emptyLabel',
			'facets',
		])
	})
})
