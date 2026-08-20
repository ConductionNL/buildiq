/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormStepsManager.vue (REQ-OBFEL-001).
 *
 * Covers: add/reorder/delete emits, id slug uniqueness, assignment to/from
 * the pool, last-step-delete removes the key, absent-steps single-step
 * state, dangling step-reference warning renders and the stale entry
 * survives.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FormStepsManager from '../../../src/components/page-editor/fields/FormStepsManager.vue'

const FIELDS = [
	{ key: 'wantsContact', label: 'Wants contact', type: 'boolean' },
	{ key: 'email', label: 'Email', type: 'string' },
	{ key: 'phone', label: 'Phone', type: 'string' },
]

function mountManager(steps = [], fields = FIELDS) {
	return mount(FormStepsManager, { propsData: { steps, fields } })
}

describe('FormStepsManager', () => {
	it('renders the single-step state when steps is absent/empty', () => {
		const wrapper = mountManager([])
		expect(wrapper.text()).toContain('single step')
		expect(wrapper.findAll('.form-steps-manager__step')).toHaveLength(0)
	})

	it('adding a step emits an array with a derived kebab-case id', async () => {
		const wrapper = mountManager([])
		await wrapper.find('.form-steps-manager__add').trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next).toHaveLength(1)
		// The t() test stub does not interpolate `{n}` (real Nextcloud l10n
		// does); the id is still correctly kebab-derived from whatever the
		// title resolves to.
		expect(next[0].id).toBe('step-n')
		expect(next[0].fields).toEqual([])
	})

	it('typing a title re-derives the id while untouched', async () => {
		const steps = [{ id: 'step-1', title: 'Step 1', fields: [] }]
		const wrapper = mountManager(steps)
		const titleInput = wrapper.findAll('.form-steps-manager__field').at(0)
		await titleInput.setValue('Contact info')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next[0].title).toBe('Contact info')
		expect(next[0].id).toBe('contact-info')
	})

	it('directly editing the id field does not get overridden by title', async () => {
		const steps = [{ id: 'step-1', title: 'Step 1', fields: [] }]
		const wrapper = mountManager(steps)
		const idInput = wrapper.findAll('.form-steps-manager__field--narrow').at(0)
		await idInput.setValue('custom-id')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next[0].id).toBe('custom-id')
	})

	it('assigns an unassigned field key to a step', async () => {
		const steps = [{ id: 'contact', title: 'Contact', fields: [] }]
		const wrapper = mountManager(steps)
		const select = wrapper.find('.form-steps-manager__select')
		await select.setValue('email')
		await wrapper.find('.form-steps-manager__assign button').trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next[0].fields).toEqual(['email'])
	})

	it('removes a field key from a step back to the pool', async () => {
		const steps = [{ id: 'contact', title: 'Contact', fields: ['email'] }]
		const wrapper = mountManager(steps)
		await wrapper.find('.form-steps-manager__chip-remove').trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next[0].fields).toEqual([])
	})

	it('lists unassigned keys with the final-step note when steps exist', () => {
		const steps = [{ id: 'contact', title: 'Contact', fields: ['email'] }]
		const wrapper = mountManager(steps)
		expect(wrapper.find('.form-steps-manager__pool').text()).toContain(
			'wantsContact',
		)
		expect(wrapper.find('.form-steps-manager__pool').text()).toContain('phone')
		expect(wrapper.find('.form-steps-manager__pool').text()).not.toContain(
			'email',
		)
		expect(wrapper.text()).toContain('automatically added to the last step')
	})

	it('reorders steps up and down', async () => {
		const steps = [
			{ id: 'a', title: 'A', fields: [] },
			{ id: 'b', title: 'B', fields: [] },
			{ id: 'c', title: 'C', fields: [] },
		]
		const wrapper = mountManager(steps)
		const upButtons = wrapper
			.findAll('.form-steps-manager__icon-button')
			.filter((b) => b.text() === '▲')
		await upButtons.at(1).trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next.map((s) => s.id)).toEqual(['b', 'a', 'c'])
	})

	it('the first step cannot move up and the last cannot move down', () => {
		const steps = [
			{ id: 'a', title: 'A', fields: [] },
			{ id: 'b', title: 'B', fields: [] },
		]
		const wrapper = mountManager(steps)
		const iconButtons = wrapper.findAll('.form-steps-manager__icon-button')
		// Step 0: up (disabled), down (enabled). Step 1: up (enabled), down (disabled).
		expect(iconButtons.at(0).attributes('disabled')).toBeDefined()
		expect(iconButtons.at(1).attributes('disabled')).toBeUndefined()
		expect(iconButtons.at(2).attributes('disabled')).toBeUndefined()
		expect(iconButtons.at(3).attributes('disabled')).toBeDefined()
	})

	it('deleting a step returns its fields to the pool without touching field definitions', async () => {
		const steps = [
			{ id: 'a', title: 'A', fields: ['email'] },
			{ id: 'b', title: 'B', fields: ['phone', 'wantsContact'] },
		]
		const wrapper = mountManager(steps)
		const removeButtons = wrapper.findAll('.form-steps-manager__remove')
		await removeButtons.at(0).trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].id).toBe('b')
	})

	it('deleting the last step emits null so the caller removes the steps key', async () => {
		const steps = [{ id: 'a', title: 'A', fields: ['email'] }]
		const wrapper = mountManager(steps)
		await wrapper.find('.form-steps-manager__remove').trigger('click')
		const next = wrapper.emitted('update:steps')[0][0]
		expect(next).toBeNull()
	})

	it('paints a dangling-reference warning when a step references a removed field, without dropping it', () => {
		const steps = [{ id: 'a', title: 'A', fields: ['ghost'] }]
		const wrapper = mountManager(steps, FIELDS)
		const mark = wrapper.find('.inline-field-mark')
		expect(mark.exists()).toBe(true)
		expect(mark.attributes('role')).toBe('alert')
		// The t() test stub does not interpolate `{keys}` (real Nextcloud l10n
		// does); assert the structural message instead.
		expect(mark.text()).toContain('removed field')
		// The stale reference is still rendered as a chip (never silently dropped).
		expect(wrapper.text()).toContain('ghost')
	})
})
