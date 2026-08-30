/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormFieldBuilder.vue's manifest-form-logic additions
 * (REQ-OBFEL-002/003/004), gated behind the `show-logic` prop.
 *
 * Covers: details-area disclosure, `visibleWhen` delete-on-null, unknown
 * per-field keys survive condition/validation edits, dangling-condition
 * warning appears when a referenced field is removed, and the
 * `show-logic=false` (SettingsSectionBuilder) path is unchanged.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormFieldBuilder from '../../../src/components/page-editor/fields/FormFieldBuilder.vue'

const FIELDS = [
	{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
	{ key: 'email', label: 'Email', type: 'string', extra: 'preserved' },
]

function mountBuilder(modelValue = FIELDS, showLogic = true) {
	return mount(FormFieldBuilder, { propsData: { modelValue, showLogic } })
}

describe('FormFieldBuilder (show-logic=true)', () => {
	it('shows a disclosure button and no details area by default', () => {
		const wrapper = mountBuilder()
		expect(wrapper.find('.form-field-builder__disclosure').exists()).toBe(true)
		expect(wrapper.find('.form-field-builder__details').exists()).toBe(false)
	})

	it('toggling the disclosure reveals the Conditions and Validation sections', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('.form-field-builder__disclosure').trigger('click')
		const details = wrapper.find('.form-field-builder__details')
		expect(details.exists()).toBe(true)
		expect(details.text()).toContain('Conditions')
		expect(details.text()).toContain('Validation')
	})

	it('does not render the old flat required checkbox / pattern input in the collapsed row', () => {
		const wrapper = mountBuilder()
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
	})

	it('writing a condition through VisibleWhenBuilder updates the field and preserves unknown sibling keys', async () => {
		const wrapper = mountBuilder()
		await wrapper.find('.form-field-builder__disclosure').trigger('click')
		const vwb = wrapper.findComponent({ name: 'VisibleWhenBuilder' })
		vwb.vm.$emit('update:modelValue', { field: 'wantsContact', value: true })
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].visibleWhen).toEqual({ field: 'wantsContact', value: true })
		expect(next[1].extra).toBe('preserved')
	})

	it('emitting null from VisibleWhenBuilder deletes the visibleWhen key', async () => {
		const withCondition = [
			{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
			{
				key: 'email',
				label: 'Email',
				type: 'string',
				visibleWhen: { field: 'wantsContact', value: true },
			},
		]
		const wrapper = mountBuilder(withCondition)
		await wrapper
			.findAll('.form-field-builder__disclosure')
			.at(1)
			.trigger('click')
		// Only the expanded row (index 1) mounts a VisibleWhenBuilder.
		const vwb = wrapper.findComponent({ name: 'VisibleWhenBuilder' })
		vwb.vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[1]).not.toHaveProperty('visibleWhen')
	})

	it("writing validation migrates only the edited field's legacy flat keys", async () => {
		const withFlat = [
			{
				key: 'email',
				label: 'Email',
				type: 'string',
				required: true,
				pattern: '^\\d+$',
			},
			{
				key: 'phone',
				label: 'Phone',
				type: 'string',
				required: true,
				pattern: '^\\d+$',
			},
		]
		const wrapper = mountBuilder(withFlat)
		await wrapper
			.findAll('.form-field-builder__disclosure')
			.at(0)
			.trigger('click')
		const fvb = wrapper
			.findAllComponents({ name: 'FieldValidationBuilder' })
			.at(0)
		fvb.vm.$emit('update:modelValue', { required: true, pattern: '^\\d+$' })
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:modelValue')[0][0]
		expect(next[0].validation).toEqual({ required: true, pattern: '^\\d+$' })
		expect(next[0]).not.toHaveProperty('required')
		expect(next[0]).not.toHaveProperty('pattern')
		// Untouched sibling keeps its flat keys byte-for-byte.
		expect(next[1].required).toBe(true)
		expect(next[1].pattern).toBe('^\\d+$')
		expect(next[1]).not.toHaveProperty('validation')
	})

	it('shows a live dangling-condition warning when the referenced field is removed, without deleting the stale visibleWhen', async () => {
		const withDangling = [
			{
				key: 'email',
				label: 'Email',
				type: 'string',
				visibleWhen: { field: 'ghost', value: true },
			},
		]
		const wrapper = mountBuilder(withDangling)
		await wrapper.find('.form-field-builder__disclosure').trigger('click')
		const mark = wrapper.find('.inline-field-mark')
		expect(mark.exists()).toBe(true)
		expect(mark.attributes('role')).toBe('alert')
		// The vars object passed to t() (containing "ghost") is asserted at
		// the danglingConditionMark() unit level below; the t() test stub
		// does not interpolate {key}, so here we assert the mark rendered
		// with the message the stub returns.
		expect(mark.text()).toContain('removed field')
	})

	it('danglingConditionMark reports the actual dangling key (t() interpolation happens at render time)', () => {
		const wrapper = mountBuilder([
			{
				key: 'email',
				label: 'Email',
				type: 'string',
				visibleWhen: { field: 'ghost', value: true },
			},
		])
		const mark = wrapper.vm.danglingConditionMark(wrapper.vm.localFields[0])
		expect(mark.hasError).toBe(true)
	})

	it('keeps an open details panel pinned to its own field when an earlier row is removed', async () => {
		// Regression: `expandedIndices` stores positions, so removing an earlier
		// row used to leave the panel open on what is now a DIFFERENT field —
		// the author edits conditions/validation believing they belong to the
		// row they opened, and a dangling-reference warning on the shifted field
		// disappears with it (REQ-OBFEL-004 warns inside the details area).
		const wrapper = mountBuilder([
			{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
			{ key: 'email', label: 'Email', type: 'string' },
		])
		// Open the SECOND row's details.
		await wrapper
			.findAll('.form-field-builder__disclosure')
			.at(1)
			.trigger('click')
		expect(wrapper.vm.expandedIndices).toEqual([1])

		// Remove the FIRST row — email shifts to index 0 and must stay open.
		wrapper.vm.removeField(0)
		expect(wrapper.vm.expandedIndices).toEqual([0])
	})

	it('closes the details panel of the row that was removed', () => {
		const wrapper = mountBuilder([
			{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
			{ key: 'email', label: 'Email', type: 'string' },
		])
		wrapper.vm.toggleExpanded(0)
		wrapper.vm.toggleExpanded(1)
		wrapper.vm.removeField(0)
		expect(wrapper.vm.expandedIndices).toEqual([0]) // was [0, 1]; row 0 gone, row 1 → 0
	})

	it('shows a compact summary in the collapsed row', () => {
		const withLogic = [
			{
				key: 'email',
				label: 'Email',
				type: 'string',
				validation: { required: true, pattern: '^\\d+$' },
				visibleWhen: { field: 'x', value: 1 },
			},
		]
		const wrapper = mountBuilder(withLogic)
		expect(wrapper.find('.form-field-builder__summary').text()).toBe(
			'required · pattern · 1 condition',
		)
	})
})

describe('FormFieldBuilder (show-logic=false, SettingsSectionBuilder path unchanged)', () => {
	it('keeps the original flat required checkbox and pattern input, no disclosure', () => {
		const wrapper = mountBuilder(FIELDS, false)
		expect(wrapper.find('input[type="checkbox"]').exists()).toBe(true)
		expect(wrapper.find('.form-field-builder__disclosure').exists()).toBe(false)
		expect(wrapper.find('.form-field-builder__details').exists()).toBe(false)
	})
})
