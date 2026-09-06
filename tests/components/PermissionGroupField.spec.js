import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for PermissionGroupField.vue — the group-scoped `permission`
 * picker used by MenuTreeEditor / PageListEditor.
 *
 * Spec: runtime-group-scoped-access (REQ-1 / REQ-2).
 */
import { describe, expect, it } from 'vitest'
import PermissionGroupField from '../../src/components/page-editor/fields/PermissionGroupField.vue'

// Vue 3 model API: NcSelect takes `modelValue` and emits `update:modelValue`.
// The Vue 2 `value` / `input` pair no longer exists, so a stub declaring
// `value` receives nothing and `props('value')` reads `undefined`.
const NcSelectStub = {
	name: 'NcSelect',
	props: [
		'modelValue',
		'options',
		'taggable',
		'clearable',
		'placeholder',
		'inputLabel',
		'label',
		'trackBy',
	],
	emits: ['update:modelValue', 'tag'],
	template: '<div class="ncselect-stub" :data-input-label="inputLabel" />',
}

const stubs = { NcSelect: NcSelectStub }

const factory = (propsData = {}) => mount(PermissionGroupField, { propsData, stubs })

describe('PermissionGroupField', () => {
	it('carries an inputLabel on the NcSelect (gate-12 — no bare label)', () => {
		const wrapper = factory()
		const select = wrapper.findComponent(NcSelectStub)
		expect(select.attributes('data-input-label')).toBeTruthy()
	})

	it('renders no selection when permission is unset', () => {
		const wrapper = factory({ permission: '' })
		const select = wrapper.findComponent(NcSelectStub)
		expect(select.props('modelValue')).toBeNull()
	})

	it('derives the selected option from a group:<gid> permission', () => {
		const wrapper = factory({ permission: 'group:vets' })
		const select = wrapper.findComponent(NcSelectStub)
		expect(select.props('modelValue')).toEqual({ value: 'vets', label: 'vets' })
	})

	it('emits update:permission with group:<gid> when an option is picked', async () => {
		const wrapper = factory()
		const select = wrapper.findComponent(NcSelectStub)
		select.vm.$emit('update:modelValue', { value: 'vets', label: 'vets' })
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted()['update:permission'][0]).toEqual(['group:vets'])
	})

	it('emits update:permission with null when cleared', async () => {
		const wrapper = factory({ permission: 'group:vets' })
		const select = wrapper.findComponent(NcSelectStub)
		select.vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted()['update:permission'][0]).toEqual([null])
	})

	it('emits update:permission with group:<tag> for a free-typed group id', async () => {
		const wrapper = factory()
		const select = wrapper.findComponent(NcSelectStub)
		select.vm.$emit('tag', 'finance')
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted()['update:permission'][0]).toEqual(['group:finance'])
	})

	it('deduplicates knownGroups into quick-pick options', () => {
		const wrapper = factory({ knownGroups: ['vets', 'vets', 'finance'] })
		const select = wrapper.findComponent(NcSelectStub)
		expect(select.props('options')).toEqual([
			{ value: 'vets', label: 'vets' },
			{ value: 'finance', label: 'finance' },
		])
	})
})
