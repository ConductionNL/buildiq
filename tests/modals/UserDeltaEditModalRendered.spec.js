/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * UserDeltaEditModal as opened and used: opening seeds the editor with the
 * current delta and clears an old error, the editor's edits become the
 * draft, Save shows its busy label and then closes, a refused save shows its
 * reason, and Cancel or the modal's own close ask the parent to close it.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ put: vi.fn() }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, vars) => p.replace('{appId}', vars.appId),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import UserDeltaEditModal from '../../src/modals/UserDeltaEditModal.vue'

const stubs = {
	NcModal: {
		name: 'NcModal',
		emits: ['close'],
		template: '<div class="modal"><slot /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	CnJsonViewer: {
		name: 'CnJsonViewer',
		props: ['value'],
		emits: ['update:value'],
		render: () => h('pre'),
	},
}

async function openModal(delta = { pages: [] }) {
	const wrapper = mount(UserDeltaEditModal, {
		props: { open: false, appSlug: 'petstore', delta },
		global: { stubs },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

describe('UserDeltaEditModal rendered', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it('renders nothing while closed and seeds the editor when opened', async () => {
		const wrapper = mount(UserDeltaEditModal, {
			props: { open: false, appSlug: 'petstore', delta: { a: 1 } },
			global: { stubs },
		})
		expect(wrapper.find('.modal').exists()).toBe(false)
		await wrapper.setProps({ open: true })
		expect(wrapper.findComponent({ name: 'CnJsonViewer' }).props('value')).toBe(
			'{\n  "a": 1\n}',
		)
	})

	it('saves the edited draft, showing it is busy, then closes', async () => {
		let finish
		axiosMock.put.mockReturnValue(
			new Promise((r) => {
				finish = r
			}),
		)
		const wrapper = await openModal()
		await wrapper
			.findComponent({ name: 'CnJsonViewer' })
			.vm.$emit('update:value', '{"pages": {"home": {"title": "Hi"}}}')
		const save = wrapper.findAll('button')[1]
		await save.trigger('click')
		expect(save.text()).toBe('Saving…')
		expect(save.attributes('disabled')).toBeDefined()

		finish({})
		await flushPromises()
		expect(axiosMock.put).toHaveBeenCalledWith(
			'/apps/buildiq/api/app-overrides/petstore/user',
			{ pages: { home: { title: 'Hi' } } },
		)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('shows why a save was refused, and clears it when reopened', async () => {
		axiosMock.put.mockRejectedValueOnce({
			response: { data: { detail: 'widget w1 unknown' } },
		})
		const wrapper = await openModal()
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()
		expect(wrapper.find('.ob-user-delta-modal__error').text()).toBe(
			'Could not save your override: widget w1 unknown',
		)

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })
		expect(wrapper.find('.ob-user-delta-modal__error').exists()).toBe(false)
	})

	it('asks the parent to close from Cancel and from the modal', async () => {
		const wrapper = await openModal()
		await wrapper.findAll('button')[0].trigger('click')
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('update:open')).toEqual([[false], [false]])
	})
})
