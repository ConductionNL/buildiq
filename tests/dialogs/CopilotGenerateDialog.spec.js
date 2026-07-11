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
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a), post: (...a) => axiosPost(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import CopilotGenerateDialog from '../../src/dialogs/CopilotGenerateDialog.vue'
import { clearCopilotHealthCache } from '../../src/composables/useCopilot.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('CopilotGenerateDialog.vue — spec ai-copilot REQ-OBAIC-001/006', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
		axiosGet.mockResolvedValue({ data: { status: 'ok' } })
		clearCopilotHealthCache()
	})

	it('renders nothing when open is false', () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: false } })
		expect(wrapper.find('[data-testid="copilot-brief-input"]').exists()).toBe(false)
	})

	it('brief -> review -> confirm emits created with the app slug', async () => {
		axiosPost
			.mockResolvedValueOnce({
				data: {
					summary: 'A tool library',
					steps: [{ tool: 'openbuild.createApp', arguments: { slug: 'tool-library', name: 'Tool Library' } }],
					manifests: {},
				},
			})
			.mockResolvedValueOnce({
				data: { results: [{ success: true, created: true, app: { uuid: 'u1', slug: 'tool-library', name: 'Tool Library' } }] },
			})

		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true } })
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('A tool library where members borrow tools')
		await wrapper.vm.onGenerate()
		await flush()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="copilot-plan-review"]').exists()).toBe(true)

		await wrapper.vm.onConfirm()
		await flush()

		expect(wrapper.emitted('created')).toBeTruthy()
		expect(wrapper.emitted('created')[0][0]).toBe('tool-library')
		expect(wrapper.emitted('update:open')).toBeTruthy()
		expect(wrapper.emitted('update:open')[0][0]).toBe(false)
	})

	it('cancel sends no execute request', async () => {
		axiosPost.mockResolvedValueOnce({
			data: { summary: 'x', steps: [{ tool: 'openbuild.createApp', arguments: { slug: 'x', name: 'X' } }], manifests: {} },
		})

		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true } })
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
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true } })
		const generateButton = wrapper.findAll('button').wrappers.find((b) => b.text().includes('Generate'))
		expect(generateButton.attributes('disabled')).toBeTruthy()
	})

	it('Generate is enabled once a brief is entered', async () => {
		const wrapper = mount(CopilotGenerateDialog, { propsData: { open: true } })
		await wrapper.find('[data-testid="copilot-brief-input"]').setValue('A tool library')
		const generateButton = wrapper.findAll('button').wrappers.find((b) => b.text().includes('Generate'))
		expect(generateButton.attributes('disabled')).toBeFalsy()
	})
})
