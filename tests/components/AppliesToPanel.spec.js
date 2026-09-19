import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AppliesToPanel.vue.
 *
 * Spec: page-layout-per-type (REQ-OBPL-002), screen-override-layers
 * (REQ-OBSO-002, REQ-OBSO-004).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/pageLayouts.js', () => ({
	savePageLayout: vi.fn(),
}))

import AppliesToPanel from '../../src/components/page-editor/fields/AppliesToPanel.vue'
import { savePageLayout } from '../../src/services/pageLayouts.js'

const flush = () => new Promise((r) => setTimeout(r, 0))

/**
 * Mount the panel over one binding.
 *
 * @param {object} props - props to override.
 * @return {object} The wrapper.
 */
function mountPanel(props = {}) {
	return mount(AppliesToPanel, {
		props: {
			modelValue: {},
			register: 'dossiq',
			schema: 'Zaak',
			...props,
		},
	})
}

describe('AppliesToPanel', () => {
	beforeEach(() => {
		savePageLayout.mockReset()
		savePageLayout.mockResolvedValue({ layout: { id: 'x' }, warnings: [] })
	})

	it('asks for a register and a schema before offering to publish anything', () => {
		const wrapper = mountPanel({ register: '', schema: '' })

		expect(wrapper.find('.applies-to__save').exists()).toBe(false)
		expect(wrapper.text()).toContain('Pick a register and a schema first')
	})

	it('refuses to save a group screen that does not say which group', async () => {
		// The server refuses this too. Saying so here saves a round trip, and an
		// override that matches nobody is published, correct-looking and never
		// applied.
		const wrapper = mountPanel({
			modelValue: { audience: { kind: 'group', ref: '' } },
		})

		expect(
			wrapper.find('.applies-to__save').attributes('disabled'),
		).toBeDefined()
		expect(wrapper.text()).toContain('matches nobody')

		await wrapper.find('.applies-to__save').trigger('click')
		expect(savePageLayout).not.toHaveBeenCalled()
	})

	it('drops the group name when the screen goes back to everyone', async () => {
		const wrapper = mountPanel({
			modelValue: { audience: { kind: 'group', ref: 'behandelaars' } },
		})

		const select = wrapper.findAll('select')[0]
		await select.setValue('everyone')

		const emitted = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(emitted).not.toHaveProperty('audience')
	})

	it('publishes the binding without inventing tabs for it', async () => {
		// A sidebar tab and a pageLayout tab are two vocabularies. Translating
		// one into the other here would be a second page model that nothing
		// else agrees with.
		const wrapper = mountPanel({
			modelValue: { name: 'Behandelaarsscherm', typeValue: 'bouwvergunning' },
			targetApp: 'dossiq',
		})

		await wrapper.find('.applies-to__save').trigger('click')
		await flush()

		const sent = savePageLayout.mock.calls[0][0]
		expect(sent).not.toHaveProperty('tabs')
		expect(sent.register).toBe('dossiq')
		expect(sent.schema).toBe('Zaak')
		expect(sent.targetApp).toBe('dossiq')
	})

	it('derives a stable id so saving twice edits one screen rather than two', async () => {
		const wrapper = mountPanel({
			modelValue: { typeValue: 'bouwvergunning' },
		})

		await wrapper.find('.applies-to__save').trigger('click')
		await flush()

		const id = savePageLayout.mock.calls[0][0].id
		expect(id).toBe('layout-dossiq-zaak-bouwvergunning-everyone-all')

		// And it is written back, so the next save is an edit.
		const emitted = wrapper.emitted('update:modelValue').at(-1)[0]
		expect(emitted.id).toBe(id)
	})

	it('shows the sentence a refusal wrote, not a generic failure', async () => {
		savePageLayout.mockRejectedValue({
			status: 422,
			error: 'refused',
			message: 'There is no published layout for this schema to patch.',
		})

		const wrapper = mountPanel({ modelValue: { name: 'Scherm' } })
		await wrapper.find('.applies-to__save').trigger('click')
		await flush()

		expect(wrapper.text()).toContain(
			'There is no published layout for this schema to patch.',
		)
	})
})
