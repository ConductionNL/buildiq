import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: CopilotGenerateDialog starts clean each time it opens, sends no
 * empty brief, and keeps the dialog open when the approved plan did not
 * finish (spec ai-copilot REQ-OBAIC-001/006).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

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

describe('CopilotGenerateDialog guards', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		clearCopilotHealthCache()
	})

	it('opening again clears the brief, the old plan and checks health', async () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: false }, stubs })
		wrapper.vm.brief = 'old brief'
		const discard = vi.spyOn(wrapper.vm.copilot, 'discard')
		const checkHealth = vi.spyOn(wrapper.vm.copilot, 'checkHealth').mockResolvedValue()

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.brief).toBe('')
		expect(discard).toHaveBeenCalledTimes(1)
		expect(checkHealth).toHaveBeenCalledTimes(1)
	})

	it('a blank brief asks the copilot for nothing; a brief is sent trimmed', async () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		await flush()
		const generatePlan = vi.spyOn(wrapper.vm.copilot, 'generatePlan').mockResolvedValue()

		wrapper.vm.brief = '   '
		await wrapper.vm.onGenerate()
		expect(generatePlan).not.toHaveBeenCalled()

		wrapper.vm.brief = '  A permit app  '
		await wrapper.vm.onGenerate()
		expect(generatePlan).toHaveBeenCalledWith('A permit app')
	})

	it('a plan that did not finish creates nothing and keeps the dialog open', async () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true }, stubs })
		await flush()
		vi.spyOn(wrapper.vm.copilot, 'approve').mockResolvedValue()
		wrapper.vm.copilot.state.value = 'failed'

		await wrapper.vm.onConfirm()

		expect(wrapper.emitted('created')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})
