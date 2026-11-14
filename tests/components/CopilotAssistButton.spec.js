/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CopilotAssistButton (ai-copilot-documents-and-code-help REQ-BQCH-001): no
 * button while the health probe says unavailable; an "Ask AI" button that
 * opens the dialog when available; an accepted value is re-emitted.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const fetchCopilotHealth = vi.fn()
vi.mock('../../src/services/copilot.js', () => ({
	fetchCopilotHealth: (...args) => fetchCopilotHealth(...args),
	assist: vi.fn(),
	requestPlan: vi.fn(),
	executePlan: vi.fn(),
	discardRun: vi.fn(),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ validateManifest: () => ({ valid: true, errors: [] }) }))

const { clearCopilotHealthCache } = await import('../../src/composables/useCopilot.js')
const { default: CopilotAssistButton } = await import('../../src/components/copilot/CopilotAssistButton.vue')

const DialogStub = { name: 'CopilotAssistDialog', props: ['open', 'target', 'appSlug', 'current', 'context'], emits: ['accept', 'update:open'], template: '<div class="assist-dialog" :data-open="String(open)" />' }

function mountButton (props = {}) {
  return mount(CopilotAssistButton, {
	props: { target: 'feel-condition', appSlug: 'permits', ...props },
	global: {
		stubs: {
			NcButton: { props: ['ariaLabel'], emits: ['click'], template: '<button :aria-label="ariaLabel" @click="$emit(\'click\')"><slot name="icon" /></button>' },
			CreationIcon: true,
			CopilotAssistDialog: DialogStub,
		},
	},
})
}

describe('CopilotAssistButton', () => {
	beforeEach(() => {
		clearCopilotHealthCache()
		fetchCopilotHealth.mockReset()
	})

	it('renders nothing when the copilot is unavailable', async () => {
		fetchCopilotHealth.mockResolvedValue({ available: false, reason: 'no_provider' })
		const wrapper = mountButton()
		await flushPromises()

		expect(fetchCopilotHealth).toHaveBeenCalledTimes(1)
		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.html()).not.toContain('assist-dialog')
	})

	it('renders nothing without an app to scope the help to', async () => {
		fetchCopilotHealth.mockResolvedValue({ available: true })
		const wrapper = mountButton({ appSlug: '' })
		await flushPromises()

		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('shows an Ask AI button that opens the dialog with the field props', async () => {
		fetchCopilotHealth.mockResolvedValue({ available: true })
		const wrapper = mountButton({ target: 'field-mapping', current: '{}', context: { targetSchema: 'permit' } })
		await flushPromises()

		const button = wrapper.find('[aria-label="Ask AI"]')
		expect(button.exists()).toBe(true)
		const dialog = wrapper.findComponent(DialogStub)
		expect(dialog.props()).toMatchObject({ open: false, target: 'field-mapping', appSlug: 'permits', current: '{}', context: { targetSchema: 'permit' } })

		await button.trigger('click')

		expect(wrapper.findComponent(DialogStub).props('open')).toBe(true)
	})

	it('re-emits an accepted value', async () => {
		fetchCopilotHealth.mockResolvedValue({ available: true })
		const wrapper = mountButton()
		await flushPromises()

		wrapper.findComponent(DialogStub).vm.$emit('accept', 'applicant.age < 18')

		expect(wrapper.emitted('accept')).toEqual([['applicant.age < 18']])
	})
})
