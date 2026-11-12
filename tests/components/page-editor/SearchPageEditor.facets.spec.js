/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for SearchPageEditor's facet option editing (REQ-PEC-005).
 *
 * Covers:
 *  - a facet field is set, and cleared on '' or null.
 *  - adding an option to a facet without options starts the list.
 *  - removing an option keeps the others; editing an option sets and
 *    clears its fields.
 *  - removing the last facet drops the facets key entirely.
 *  - the stored config is never mutated in place.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn(async () => []),
		fetchSchemas: vi.fn(async () => []),
		fetchSchemaProperties: vi.fn(async () => ({})),
		resolveAppRegister: () => '',
	}),
}))

const SearchPageEditor = (
	await import('../../../src/components/page-editor/SearchPageEditor.vue')
).default

const FACETS = [
	{ key: 'status', label: 'Status', options: [{ value: 'open', label: 'Open' }, { value: 'closed' }] },
	{ key: 'type' },
]

function mountEditor(config = { title: 'Find', facets: FACETS }) {
	return mount(SearchPageEditor, { props: { config, appSlug: 'hello-world' } })
}

function lastConfig(wrapper) {
	const events = wrapper.emitted('update:config')
	return events[events.length - 1][0]
}

describe('SearchPageEditor facets', () => {
	it('sets a facet field and clears it on empty or null', () => {
		const wrapper = mountEditor()
		wrapper.vm.updateFacetField(1, 'label', 'Type')
		expect(lastConfig(wrapper).facets[1]).toEqual({ key: 'type', label: 'Type' })

		wrapper.vm.updateFacetField(0, 'label', '')
		expect(lastConfig(wrapper).facets[0]).not.toHaveProperty('label')

		wrapper.vm.updateFacetField(0, 'key', null)
		expect(lastConfig(wrapper).facets[0]).not.toHaveProperty('key')
		expect(lastConfig(wrapper).title).toBe('Find')
	})

	it('adding an option to a facet without options starts its list', () => {
		const wrapper = mountEditor()
		wrapper.vm.addFacetOption(1)
		expect(lastConfig(wrapper).facets[1]).toEqual({ key: 'type', options: [{ value: '' }] })
	})

	it('adding an option appends after the existing ones', () => {
		const wrapper = mountEditor()
		wrapper.vm.addFacetOption(0)
		expect(lastConfig(wrapper).facets[0].options.map((o) => o.value)).toEqual(['open', 'closed', ''])
	})

	it('removing an option keeps the others', () => {
		const wrapper = mountEditor()
		wrapper.vm.removeFacetOption(0, 0)
		expect(lastConfig(wrapper).facets[0].options).toEqual([{ value: 'closed' }])
		expect(lastConfig(wrapper).facets[1]).toEqual({ key: 'type' })
	})

	it('sets an option field and clears it on empty or null', () => {
		const wrapper = mountEditor()
		wrapper.vm.updateFacetOptionField(0, 1, 'label', 'Closed')
		expect(lastConfig(wrapper).facets[0].options[1]).toEqual({ value: 'closed', label: 'Closed' })

		wrapper.vm.updateFacetOptionField(0, 0, 'label', '')
		expect(lastConfig(wrapper).facets[0].options[0]).toEqual({ value: 'open' })

		wrapper.vm.updateFacetOptionField(0, 0, 'value', null)
		expect(lastConfig(wrapper).facets[0].options[0]).toEqual({ label: 'Open' })
	})

	it('removing the only facet drops the facets key', () => {
		const wrapper = mountEditor({ title: 'Find', facets: [{ key: 'status' }] })
		wrapper.vm.removeFacet(0)
		expect(lastConfig(wrapper)).toEqual({ title: 'Find' })
	})

	it('never edits the stored config in place', () => {
		const wrapper = mountEditor()
		wrapper.vm.updateFacetOptionField(0, 0, 'value', 'pending')
		wrapper.vm.removeFacetOption(0, 1)
		wrapper.vm.updateFacetField(1, 'key', 'kind')
		expect(FACETS[0].options).toEqual([{ value: 'open', label: 'Open' }, { value: 'closed' }])
		expect(FACETS[1]).toEqual({ key: 'type' })
	})

	it('treats a facet without an options array as having none', () => {
		const wrapper = mountEditor()
		expect(wrapper.vm.facetOptions({ options: 'open' })).toEqual([])
		expect(wrapper.vm.facetOptions(null)).toEqual([])
		expect(wrapper.vm.facetOptions(FACETS[0])).toHaveLength(2)
	})
})
