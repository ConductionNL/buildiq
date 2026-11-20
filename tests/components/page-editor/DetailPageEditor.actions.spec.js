/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the detail page's Buttons and Status buttons sections
 * (pages-action-buttons T03, T04): `config.headerActions` written through the
 * typed action editor, `config.lifecycleActions` live by default with an
 * optional list of transitions, and a header button refused when it patches
 * the status field.
 *
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn().mockResolvedValue([]),
		fetchSchemas: vi.fn().mockResolvedValue([{ slug: 'request', properties: { title: {}, status: {} } }]),
	}),
}))

import DetailPageEditor from '../../../src/components/page-editor/DetailPageEditor.vue'

const stubs = {
	SidebarTabBuilder: true,
	AppliesToPanel: true,
	ScreenOverrideList: true,
	RegistrationFormList: true,
}

function mountEditor(config = {}) {
	return mount(DetailPageEditor, {
		props: {
			config,
			parentRoute: '/requests/:id',
			appSlug: 'requests-app',
			flows: [{ flow: 'flow-intake', label: 'Intake check' }],
			pages: [{ id: 'requests' }],
		},
		global: { stubs },
	})
}

function lastConfig(wrapper) {
	const emits = wrapper.emitted('update:config')
	return emits[emits.length - 1][0]
}

describe('DetailPageEditor buttons', () => {
	it('writes headerActions through the typed action editor, with the app\'s flows', async () => {
		const wrapper = mountEditor({ register: 'reg', schema: 'request' })
		const builder = wrapper.findComponent({ name: 'ActionBuilder' })

		expect(builder.props('flows')).toEqual([{ flow: 'flow-intake', label: 'Intake check' }])
		builder.vm.$emit('update:modelValue', [{ id: 'check', label: 'Run intake check', type: 'run-node', flowId: 'flow-intake', nodeId: 'start' }])

		expect(lastConfig(wrapper)).toEqual({
			register: 'reg',
			schema: 'request',
			headerActions: [{ id: 'check', label: 'Run intake check', type: 'run-node', flowId: 'flow-intake', nodeId: 'start' }],
		})

		builder.vm.$emit('update:modelValue', [])
		expect(lastConfig(wrapper)).toEqual({ register: 'reg', schema: 'request' })
	})

	it('offers the bound schema\'s fields to a button\'s condition', async () => {
		const wrapper = mountEditor({ register: 'reg', schema: 'request' })
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.findComponent({ name: 'ActionBuilder' }).props('fieldOptions')).toEqual(['title', 'status'])
	})

	it('switches status buttons on as the live lifecycle list, and off again', async () => {
		const wrapper = mountEditor({})

		await wrapper.find('.detail-page-editor__status-toggle').setValue(true)
		expect(lastConfig(wrapper)).toEqual({ lifecycleActions: { field: 'status' } })

		await wrapper.setProps({ config: lastConfig(wrapper) })
		await wrapper.find('.detail-page-editor__status-toggle').setValue(false)
		expect(lastConfig(wrapper)).toEqual({})
	})

	it('lists status buttons with their own labels and confirm texts', async () => {
		const wrapper = mountEditor({ lifecycleActions: { field: 'status' } })

		await wrapper.find('.detail-page-editor__add-transition').trigger('click')
		await wrapper.setProps({ config: lastConfig(wrapper) })
		const inputs = wrapper.find('.detail-page-editor__transition').findAll('input')
		await inputs[0].setValue('approve')
		await wrapper.setProps({ config: lastConfig(wrapper) })
		await wrapper.find('.detail-page-editor__transition').findAll('input')[2].setValue('Approve this request?')

		expect(lastConfig(wrapper)).toEqual({
			lifecycleActions: { field: 'status', transitions: [{ action: 'approve', label: '', confirm: 'Approve this request?' }] },
		})

		await wrapper.setProps({ config: lastConfig(wrapper) })
		await wrapper.find('.detail-page-editor__remove').trigger('click')
		expect(lastConfig(wrapper)).toEqual({ lifecycleActions: { field: 'status' } })
	})

	it('refuses a header button that patches the status field', () => {
		const wrapper = mountEditor({ lifecycleActions: { field: 'state' } })

		expect(wrapper.findComponent({ name: 'ActionBuilder' }).props('lifecycleFields')).toEqual(['state'])
	})
})
