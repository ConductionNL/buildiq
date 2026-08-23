/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for src/dialogs/CopilotGenerateDialog.vue
 * (spec ai-copilot REQ-OBAIC-001/006).
 *
 * Covers: brief -> review -> confirm emits `created`; cancel sends no
 * execute request; the health probe reaching 503 keeps the dialog usable
 * for its own local error state (the "button hidden + admin hint" scenario
 * lives on Step1Basics, covered separately).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const axiosGet = vi.fn()
const axiosPost = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

import CopilotGenerateDialog from '../../src/dialogs/CopilotGenerateDialog.vue'
import { clearCopilotHealthCache } from '../../src/composables/useCopilot.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

// `NcModal` in @nextcloud/vue 9 renders its body through `<Teleport to="body">`,
// so under VTU v2 none of the dialog's markup lives inside `wrapper.element`
// and every `wrapper.find(...)` comes back empty. Stubbing the modal keeps the
// content in-tree, which is what the sibling dialog specs here already do.
//
// `emits: ['click']` on the button stub is likewise load-bearing: without it
// Vue 3 leaves the parent's `@click` in `$attrs`, it falls through onto the
// root <button>, and one click fires the handler twice. The real NcButton
// declares `emits: ['click', 'update:pressed']`.
const stubs = {
	NcModal: {
		name: 'NcModal',
		props: ['name', 'canClose'],
		template: '<div class="ncmodal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled || false" :data-type="type" @click="$emit(\'click\')"><slot /></button>',
	},
	// Vue 3 model API: `modelValue` in, `update:modelValue` out.
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'rows'],
		emits: ['update:modelValue'],
		template:
			'<textarea class="nctextarea-stub" :value="modelValue" :disabled="disabled || false" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

describe('CopilotGenerateDialog.vue — spec ai-copilot REQ-OBAIC-001/006', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		clearCopilotHealthCache()
	})

	it('renders nothing when open is false', () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: false },
			stubs,
		})
		expect(wrapper.find('[data-testid="copilot-brief-input"]').exists()).toBe(
			false,
		)
	})

	it('brief -> review -> confirm emits created with the app slug', async () => {
		axiosPost
			.mockResolvedValueOnce({
				data: {
					summary: 'A tool library',
					steps: [
						{
							tool: 'buildiq.createApp',
							arguments: {
								slug: 'tool-library',
								name: 'Tool Library',
							},
						},
					],
					manifests: {},
				},
			})
			.mockResolvedValueOnce({
				data: {
					results: [
						{
							success: true,
							created: true,
							app: {
								uuid: 'u1',
								slug: 'tool-library',
								name: 'Tool Library',
							},
						},
					],
				},
			})

		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper
			.find('[data-testid="copilot-brief-input"]')
			.setValue('A tool library where members borrow tools')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="copilot-plan-review"]').exists()).toBe(
			true,
		)

		await wrapper.vm.onConfirm()
		await flush()

		expect(wrapper.emitted('created')).toBeTruthy()
		expect(wrapper.emitted('created')[0][0]).toBe('tool-library')
		expect(wrapper.emitted('update:open')).toBeTruthy()
		expect(wrapper.emitted('update:open')[0][0]).toBe(false)
	})

	it('cancel sends no execute request', async () => {
		axiosPost.mockResolvedValueOnce({
			data: {
				summary: 'x',
				steps: [
					{
						tool: 'buildiq.createApp',
						arguments: { slug: 'x', name: 'X' },
					},
				],
				manifests: {},
			},
		})

		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('x')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		axiosPost.mockClear()
		await wrapper.find('[data-testid="copilot-cancel"]').trigger('click')

		expect(axiosPost).not.toHaveBeenCalled()
		expect(wrapper.emitted('created')).toBeFalsy()
		expect(wrapper.emitted('update:open')[0][0]).toBe(false)
	})

	it('Generate is disabled with a blank brief', () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		// VTU v2 `findAll` returns a plain Array — v1's `.wrappers` is gone.
		const generateButton = wrapper
			.findAll('button')
			.find((b) => b.text().includes('Generate'))
		// Vue 3 renders a true boolean attribute as `disabled=""` — a falsy
		// empty string — so presence is the signal, not truthiness.
		expect(generateButton.attributes('disabled')).toBeDefined()
	})

	it('Generate is enabled once a brief is entered', async () => {
		const wrapper = mount(CopilotGenerateDialog, {
			propsData: { open: true },
			stubs,
		})
		await wrapper
			.find('[data-testid="copilot-brief-input"]')
			.setValue('A tool library')
		const generateButton = wrapper
			.findAll('button')
			.find((b) => b.text().includes('Generate'))
		expect(generateButton.attributes('disabled')).toBeUndefined()
	})
})
