/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPageEditor's Steps fieldset wiring (REQ-OBFEL-001).
 *
 * Covers: Steps fieldset mounts, `update('steps', …)` round-trip preserves
 * unknown config keys, `validatedConfigKeys` includes `steps`, and
 * `markFor('steps')` renders an inline mark when the injected validator
 * reports a steps error.
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// `vi.mock` factories are hoisted above the imports, so `h` is pulled in with
// a lazy dynamic import inside the (async) factory. Vue 3 does not pass `h`
// into render(), and vnode classes use `class`, not Vue 2's `staticClass`.
vi.mock(
	'../../../src/components/page-editor/fields/FormFieldBuilder.vue',
	async () => {
		const { h } = await import('vue')
		return {
			default: {
				name: 'FormFieldBuilder',
				props: ['modelValue', 'showLogic'],
				render() {
					return h('div', { class: 'form-field-builder-stub' })
				},
			},
		}
	},
)

// See FormPageEditor.spec.js — stubbed to keep these Steps-focused tests
// isolated from the real NcDialog tree pulled in by external access (REQ-EFP-002).
vi.mock('../../../src/dialogs/ExternalFormAccessDialog.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'ExternalFormAccessDialog',
			props: ['open', 'register', 'schema', 'pageId', 'entry'],
			render() {
				return h('div', { class: 'external-form-access-dialog-stub' })
			},
		},
	}
})

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

function mountEditor(config = {}, provide) {
	return mount(FormPageEditor, { propsData: { config }, provide })
}

describe('FormPageEditor Steps fieldset', () => {
	it('mounts the Steps fieldset with a FormStepsManager', () => {
		const wrapper = mountEditor()
		expect(wrapper.text()).toContain('Steps')
		expect(wrapper.findComponent({ name: 'FormStepsManager' }).exists()).toBe(
			true,
		)
	})

	it('validatedConfigKeys includes steps', () => {
		const wrapper = mountEditor()
		expect(wrapper.vm.validatedConfigKeys).toContain('steps')
	})

	it('update("steps", …) round-trip preserves unknown config keys', async () => {
		const wrapper = mountEditor({ unknownKey: 'preserved', fields: [] })
		const stepsManager = wrapper.findComponent({ name: 'FormStepsManager' })
		const nextSteps = [{ id: 'contact', title: 'Contact', fields: [] }]
		stepsManager.vm.$emit('update:steps', nextSteps)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.steps).toEqual(nextSteps)
		expect(next.unknownKey).toBe('preserved')
	})

	it('emitting null from FormStepsManager removes the steps key', async () => {
		const wrapper = mountEditor({
			steps: [{ id: 'a', title: 'A', fields: ['x'] }],
		})
		const stepsManager = wrapper.findComponent({ name: 'FormStepsManager' })
		stepsManager.vm.$emit('update:steps', null)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('steps')
	})

	it('markFor("steps") renders an inline mark when the injected validator reports a steps error', () => {
		const errorFor = (key) =>
			key === 'steps'
				? { hasError: true, message: 'steps: incomplete partition' }
				: { hasError: false, message: '' }
		const wrapper = mountEditor(
			{},
			{
				pageEditorValidator: {
					register: () => {},
					unregister: () => {},
					errorFor,
				},
			},
		)
		const marks = wrapper.findAllComponents({ name: 'InlineFieldMark' })
		const stepsMark = marks.filter(
			(m) =>
				m.props('error')
				&& m.props('error').hasError
				&& m.props('error').message.includes('steps'),
		)
		expect(stepsMark.length).toBeGreaterThan(0)
	})
})
