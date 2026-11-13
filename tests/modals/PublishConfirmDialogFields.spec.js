/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PublishConfirmDialog's fields for an app without a repository: the name
 * and organisation typed in are what is published to, a name that is not
 * letters, numbers and dashes is refused before any request, the summary
 * falls back to "the linked repository" when the link is incomplete, the
 * credential line says when none is selected, and the dialog cannot be
 * closed while it publishes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars ? String(key).replace(/\{(\w+)\}/g, (_, k) => vars[k] ?? `{${k}}`) : key
})
afterAll(() => {
	globalThis.t = realT
})

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { post: vi.fn() } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import PublishConfirmDialog from '../../src/modals/PublishConfirmDialog.vue'

function field (name) {
  return {
	name,
	props: ['modelValue', 'label', 'placeholder', 'options'],
	emits: ['update:modelValue'],
	render: () => h('div'),
}
}
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
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
}

async function openDialog(props = {}) {
	const wrapper = mount(PublishConfirmDialog, {
		props: { open: false, slug: 'petstore', credentialId: 'cred-1', ...props },
		global: { stubs },
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

const submit = (wrapper) => wrapper.find('[data-testid="publish-confirm-submit"]')

describe('PublishConfirmDialog fields', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('publishes to the repository name and organisation typed in', async () => {
		axiosMock.post.mockResolvedValue({
			data: { outcome: 'ok', commitSha: 'abc' },
		})
		const wrapper = await openDialog()
		const [name, org] = wrapper.findAllComponents({ name: 'NcTextField' })
		expect(name.props('modelValue')).toBe('openbuild-petstore')
		await name.vm.$emit('update:modelValue', 'pet-shop')
		await org.vm.$emit('update:modelValue', ' acme ')
		await submit(wrapper).trigger('click')
		await flushPromises()
		expect(axiosMock.post.mock.calls[0][0]).toBe(
			'/apps/buildiq/api/applications/petstore/github/push',
		)
		expect(axiosMock.post.mock.calls[0][1]).toEqual({
			credentialId: 'cred-1',
			repo: { name: 'pet-shop', org: 'acme' },
		})
		expect(wrapper.emitted('published')).toEqual([
			[{ outcome: 'ok', commitSha: 'abc' }],
		])
	})

	it('refuses a repository name that is not letters, numbers and dashes', async () => {
		const wrapper = await openDialog()
		await wrapper
			.findAllComponents({ name: 'NcTextField' })[0]
			.vm.$emit('update:modelValue', '-bad name')
		await submit(wrapper).trigger('click')
		await flushPromises()
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.find('[role="alert"]').text()).toBe(
			'Give the repository a name of letters, numbers and dashes.',
		)
		expect(submit(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('names the repository generically when the link is incomplete, and needs a credential', async () => {
		const wrapper = await openDialog({
			credentialId: '',
			repo: { owner: 'acme', name: 'pets' },
		})
		expect(wrapper.findAllComponents({ name: 'NcTextField' })).toHaveLength(0)
		expect(submit(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.vm.repoLabel).toBe('acme/pets')
		await wrapper.setProps({ repo: { owner: 'acme' } })
		expect(wrapper.vm.repoLabel).toBe('the linked repository')
	})

	it('cannot be closed while publishing, and closes otherwise', async () => {
		let finish
		axiosMock.post.mockReturnValue(
			new Promise((r) => {
				finish = r
			}),
		)
		const wrapper = await openDialog({
			repo: { owner: 'acme', name: 'pets', branch: 'main' },
		})
		await submit(wrapper).trigger('click')
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		await wrapper.findAll('button')[0].trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()

		finish({ data: { outcome: 'push_conflict' } })
		await flushPromises()
		await wrapper.findComponent({ name: 'NcModal' }).vm.$emit('close')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
