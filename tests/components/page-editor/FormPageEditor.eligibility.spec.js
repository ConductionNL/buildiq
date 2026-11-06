import { mount } from '@vue/test-utils'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormPageEditor's eligibility check wiring (REQ-BQLV-003,
 * change forms-live-values-and-checks).
 */
import { describe, expect, it, vi } from 'vitest'

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

// See FormPageEditor.spec.js: stubbed to keep these tests
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

vi.mock('../../../src/services/formLiveValues.js', () => ({
	listRuleSets: async () => [],
	ruleSetColumns: async () => ({ inputs: [], outputs: [] }),
}))

const FormPageEditor = (
	await import('../../../src/components/page-editor/FormPageEditor.vue')
).default

function mountEditor(config = {}, provide) {
	return mount(FormPageEditor, { propsData: { config }, provide })
}

describe('FormPageEditor eligibility check', () => {
	it('mounts the eligibility check and validates its config key', () => {
		const wrapper = mountEditor()
		expect(
			wrapper.findComponent({ name: 'EligibilityCheckBuilder' }).exists(),
		).toBe(true)
		expect(wrapper.vm.validatedConfigKeys).toContain('eligibility')
	})

	it('writes the check on the page config and keeps the other keys', async () => {
		const wrapper = mountEditor({ unknownKey: 'preserved', fields: [] })
		const check = {
			ruleSet: 'loan-eligibility',
			passWhen: { output: 'decision', equals: 'approved' },
			explainWith: 'reason',
			blockSubmit: true,
		}
		wrapper
			.findComponent({ name: 'EligibilityCheckBuilder' })
			.vm.$emit('update:modelValue', check)
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.eligibility).toEqual(check)
		expect(next.unknownKey).toBe('preserved')
	})

	it('removes the check', async () => {
		const wrapper = mountEditor({ eligibility: { ruleSet: 'x' } })
		wrapper
			.findComponent({ name: 'EligibilityCheckBuilder' })
			.vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(wrapper.emitted('update:config')[0][0]).not.toHaveProperty(
			'eligibility',
		)
	})
})
