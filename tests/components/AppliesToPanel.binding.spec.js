/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for AppliesToPanel.vue: how typing into the panel rewrites the
 * binding — name, type property and value, state, the audience and who in it —
 * and the warnings a successful save hands back.
 *
 * Spec: page-layout-per-type (REQ-OBPL-002), screen-override-layers
 * (REQ-OBSO-002, REQ-OBSO-004).
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/services/pageLayouts.js', () => ({
	savePageLayout: vi.fn(),
}))

import AppliesToPanel from '../../src/components/page-editor/fields/AppliesToPanel.vue'
import { savePageLayout } from '../../src/services/pageLayouts.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the panel over one binding.
 *
 * @param {object} modelValue - the binding.
 * @return {object} The wrapper.
 */
function mountPanel(modelValue) {
	return mount(AppliesToPanel, {
		props: { modelValue, register: 'dossiq', schema: 'Zaak' },
	})
}

/**
 * The last binding the panel emitted.
 *
 * @param {object} wrapper - the wrapper.
 * @return {object} The binding.
 */
function lastBinding(wrapper) {
	const emitted = wrapper.emitted('update:modelValue')
	return emitted[emitted.length - 1][0]
}

/**
 * The panel's own text inputs, in order: name, type property, type value.
 *
 * @param {object} wrapper - the wrapper.
 * @return {Array<object>} The inputs.
 */
function ownInputs(wrapper) {
	return ['Handler screen', 'caseType', 'bouwvergunning'].map(
		(placeholder) => wrapper.find(`input[placeholder="${placeholder}"]`),
	)
}

describe('AppliesToPanel binding', () => {
	beforeEach(() => {
		savePageLayout.mockReset()
	})

	it('writes the name, type property and type value, and drops one cleared', async () => {
		const wrapper = mountPanel({ name: 'Oud', typeProperty: 'caseType' })
		const [name, typeProperty, typeValue] = ownInputs(wrapper)

		await name.setValue('Behandelaar')
		expect(lastBinding(wrapper)).toEqual({ name: 'Behandelaar', typeProperty: 'caseType' })

		await typeValue.setValue('bouwvergunning')
		expect(lastBinding(wrapper)).toEqual({ name: 'Oud', typeProperty: 'caseType', typeValue: 'bouwvergunning' })

		await typeProperty.setValue('')
		expect(lastBinding(wrapper)).toEqual({ name: 'Oud' })
	})

	it('writes the state', async () => {
		const wrapper = mountPanel({ name: 'Scherm' })
		const state = wrapper.findAll('select').at(1)

		expect(state.element.value).toBe('draft')
		await state.setValue('published')

		expect(lastBinding(wrapper)).toEqual({ name: 'Scherm', status: 'published' })
	})

	it('labels every audience kind and asks "which" for the ones naming somebody', () => {
		const wrapper = mountPanel({})
		const options = wrapper.findAll('select').at(0).findAll('option')

		expect(options.map((o) => o.text())).toEqual([
			'Everyone',
			'One group',
			'One team',
			'Portal visitors',
			'One person',
			'One organisation',
		])
		expect(wrapper.vm.audienceLabel('robots')).toBe('robots')

		expect(mountPanel({ audience: { kind: 'team', ref: 't1' } }).text()).toContain('Which team')
		expect(mountPanel({ audience: { kind: 'user', ref: 'u1' } }).text()).toContain('Which user')
		expect(mountPanel({ audience: { kind: 'group', ref: 'g1' } }).text()).toContain('Which group')
		expect(mountPanel({ audience: { kind: 'organisation', ref: 'o1' } }).text()).toContain('Which organisation')
		expect(mountPanel({ audience: { kind: 'portal', ref: '' } }).find('input[aria-invalid]').exists()).toBe(false)
	})

	it('switching between named audiences keeps who; portal starts without anybody', async () => {
		const wrapper = mountPanel({ name: 'S', audience: { kind: 'group', ref: 'vergunningen' } })
		const audience = wrapper.findAll('select').at(0)

		await audience.setValue('team')
		expect(lastBinding(wrapper)).toEqual({ name: 'S', audience: { kind: 'team', ref: 'vergunningen' } })

		await audience.setValue('portal')
		expect(lastBinding(wrapper)).toEqual({ name: 'S', audience: { kind: 'portal', ref: '' } })
	})

	it('typing who keeps the kind', async () => {
		const wrapper = mountPanel({ audience: { kind: 'user', ref: '' } })
		const who = wrapper.find('input[aria-invalid]')

		expect(who.attributes('aria-invalid')).toBe('true')
		await who.setValue('jan')

		expect(lastBinding(wrapper)).toEqual({ audience: { kind: 'user', ref: 'jan' } })
	})

	it('without a binding the panel reads as an empty draft for everyone', () => {
		const wrapper = mount(AppliesToPanel, { props: { register: 'dossiq', schema: 'Zaak' } })

		expect(wrapper.vm.binding).toEqual({})
		expect(wrapper.vm.audienceKind).toBe('everyone')
		expect(wrapper.vm.derivedId()).toBe('layout-dossiq-zaak-all-everyone-all')
	})

	it('shows the warnings a save hands back, and keeps a stored id', async () => {
		savePageLayout.mockResolvedValue({
			layout: { id: 'layout-1' },
			warnings: ['No tab shows the documents.', 'The header is empty.'],
		})
		const wrapper = mountPanel({ id: 'layout-1', name: 'Scherm' })

		await wrapper.find('.applies-to__save').trigger('click')
		await flush()

		expect(savePageLayout).toHaveBeenCalledWith(
			expect.objectContaining({ id: 'layout-1', register: 'dossiq', schema: 'Zaak' }),
		)
		const hints = wrapper.findAll('.applies-to__hint').map((p) => p.text())
		expect(hints).toContain('No tab shows the documents.')
		expect(hints).toContain('The header is empty.')
		expect(wrapper.text()).toContain('Saved.')
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()
		expect(wrapper.emitted('saved')[0][0]).toEqual({ id: 'layout-1' })
	})
})
