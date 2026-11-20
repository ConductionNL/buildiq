/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row-level tests for `AccessEditor.vue`, driven through the template.
 *
 * The stubs emit `update:modelValue` and `tag` the way the real Nextcloud
 * components do, so each test covers the template binding and the handler:
 * switching scope kinds, editing groups (including freshly typed tags),
 * editing a condition, and how rows the connected OpenRegister cannot
 * enforce degrade to a read-only preview.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const capabilityMocks = vi.hoisted(() => {
	return { useOrAccessCapabilities: vi.fn(() => ({ scopes: ['group', 'creator', 'condition'] })) }
})

vi.mock('../../../src/composables/useOrAccessCapabilities.js', () => {
	return { useOrAccessCapabilities: capabilityMocks.useOrAccessCapabilities }
})

const { default: AccessEditor } = await import('../../../src/components/schema-editor/AccessEditor.vue')

const stubs = {
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options', 'clearable', 'disabled', 'multiple', 'taggable'],
		template: '<div class="nc-select-stub" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled'],
		template: '<input :value="modelValue" />',
	},
}

/**
 * Rows for all four operations, overriding some.
 *
 * @param {object} overrides Row per op.
 * @return {object} Access model.
 */
function access(overrides = {}) {
	return {
		rows: ['read', 'create', 'update', 'delete'].map((op) => overrides[op] || { op, kind: 'everyone' }),
		extraKeys: {},
	}
}

/**
 * Mount the editor.
 *
 * @param {object} model Access model.
 * @return {object} Wrapper.
 */
function mountEditor(model) {
	return mount(AccessEditor, {
		propsData: { access: model, fieldNames: ['assignee', 'status'], availableGroups: ['vets'] },
		stubs,
		mocks: { t: (app, text) => text },
	})
}

/**
 * The n-th select with the given input label.
 *
 * @param {object} wrapper Wrapper.
 * @param {string} label Input label.
 * @param {number} at Index among matches.
 * @return {object} Component wrapper.
 */
function select(wrapper, label, at = 0) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.props('inputLabel') === label).at(at)
}

/**
 * The row an emitted access model holds for an operation.
 *
 * @param {object} wrapper Wrapper.
 * @param {number} n Emission index.
 * @param {string} op Operation.
 * @return {object} Row.
 */
function emittedRow(wrapper, n, op) {
	return wrapper.emitted('update:access')[n][0].rows.find((r) => r.op === op)
}

describe('AccessEditor rows', () => {
	it('switching the scope kind resets the row to that kind\'s defaults', () => {
		const wrapper = mountEditor(access())

		select(wrapper, 'Scope', 0).vm.$emit('update:modelValue', { value: 'group' })
		select(wrapper, 'Scope', 1).vm.$emit('update:modelValue', { value: 'own' })
		select(wrapper, 'Scope', 2).vm.$emit('update:modelValue', { value: 'condition' })
		select(wrapper, 'Scope', 3).vm.$emit('update:modelValue', null)

		expect(emittedRow(wrapper, 0, 'read')).toEqual({ op: 'read', kind: 'group', groups: [] })
		expect(emittedRow(wrapper, 1, 'create')).toEqual({ op: 'create', kind: 'own' })
		expect(emittedRow(wrapper, 2, 'update')).toEqual({
			op: 'update',
			kind: 'condition',
			condition: { field: '', operator: 'equals', value: '' },
		})
		expect(emittedRow(wrapper, 3, 'delete')).toEqual({ op: 'delete', kind: 'everyone' })
		// Every emission replaces only its own row.
		expect(emittedRow(wrapper, 0, 'create')).toEqual({ op: 'create', kind: 'everyone' })
	})

	it('the scope picker offers own-records and condition only when advertised', () => {
		const wrapper = mountEditor(access())
		expect(select(wrapper, 'Scope').props('options').map((o) => o.value)).toEqual(['everyone', 'group', 'own', 'condition'])

		capabilityMocks.useOrAccessCapabilities.mockReturnValueOnce({ scopes: ['group'] })
		const limited = mountEditor(access())
		expect(select(limited, 'Scope').props('options').map((o) => o.value)).toEqual(['everyone', 'group'])
	})

	it('a group selection keeps option values and freshly typed strings, and drops empties', () => {
		const wrapper = mountEditor(access({ read: { op: 'read', kind: 'group', groups: ['vets'] } }))

		select(wrapper, 'Groups').vm.$emit('update:modelValue', [{ value: 'vets' }, 'nurses', '', { value: null }, {}])
		select(wrapper, 'Groups').vm.$emit('update:modelValue', null)

		expect(emittedRow(wrapper, 0, 'read')).toEqual({ op: 'read', kind: 'group', groups: ['vets', 'nurses'] })
		expect(emittedRow(wrapper, 1, 'read')).toEqual({ op: 'read', kind: 'group', groups: [] })
	})

	it('a typed tag is appended to the row\'s groups; an empty tag is ignored', () => {
		const wrapper = mountEditor(access({ read: { op: 'read', kind: 'group', groups: ['vets'] } }))

		select(wrapper, 'Groups').vm.$emit('tag', '')
		expect(wrapper.emitted('update:access')).toBeUndefined()

		select(wrapper, 'Groups').vm.$emit('tag', 'nurses')
		expect(emittedRow(wrapper, 0, 'read')).toEqual({ op: 'read', kind: 'group', groups: ['vets', 'nurses'] })
	})

	it('editing a condition keeps the other half and forces equals', () => {
		const condition = { field: 'assignee', operator: 'equals', value: '@user.uid' }
		const wrapper = mountEditor(access({ update: { op: 'update', kind: 'condition', condition } }))

		expect(select(wrapper, 'Field').props('modelValue')).toEqual({ value: 'assignee', label: 'assignee' })

		select(wrapper, 'Field').vm.$emit('update:modelValue', { value: 'status' })
		select(wrapper, 'Field').vm.$emit('update:modelValue', null)
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'open')

		expect(emittedRow(wrapper, 0, 'update').condition).toEqual({ field: 'status', operator: 'equals', value: '@user.uid' })
		expect(emittedRow(wrapper, 1, 'update').condition).toEqual({ field: '', operator: 'equals', value: '@user.uid' })
		expect(emittedRow(wrapper, 2, 'update').condition).toEqual({ field: 'assignee', operator: 'equals', value: 'open' })
	})

	it('an unknown condition field selects nothing', () => {
		const wrapper = mountEditor(
			access({ update: { op: 'update', kind: 'condition', condition: { field: 'gone', operator: 'equals', value: '' } } }),
		)

		expect(select(wrapper, 'Field').props('modelValue')).toBeNull()
	})

	it('rows the instance cannot enforce render as a read-only preview', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({ scopes: ['group'] })
		const wrapper = mountEditor(
			access({
				read: { op: 'read', kind: 'own' },
				update: { op: 'update', kind: 'condition', condition: { field: 'a', operator: 'equals', value: 'b' } },
				delete: { op: 'delete', kind: 'condition' },
			}),
		)
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({ scopes: ['group', 'creator', 'condition'] })

		const previews = wrapper.findAll('pre').map((w) => JSON.parse(w.text()))
		expect(previews).toEqual([['@creator'], { field: 'a', operator: 'equals', value: 'b' }, {}])
		// Only the create row still has a scope picker.
		expect(wrapper.findAllComponents({ name: 'NcSelect' }).filter((c) => c.props('inputLabel') === 'Scope')).toHaveLength(1)
	})

	it('operation titles fall back to the raw key for an unknown operation', () => {
		const wrapper = mountEditor({ rows: [{ op: 'purge', kind: 'everyone' }], extraKeys: {} })

		expect(wrapper.find('h4').text()).toBe('purge')
	})
})
