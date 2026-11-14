/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/LinkRepoDialog.vue`.
 *
 * The dialog offered a third field, "Create under organisation", and the link
 * endpoint validated the value and then never used it: linking records a
 * repository, it creates none. Someone filling that field got no repository and
 * no error either. Creating is the publish dialog's job.
 *
 * Watched failing: with the field restored, the first case reports
 * `expected 3 to be 2`.
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
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

import LinkRepoDialog from '../../src/modals/LinkRepoDialog.vue'

const STUBS = {
	NcModal: {
		name: 'NcModal',
		props: ['name', 'size'],
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		template:
			'<button class="nc-button-stub" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		template: '<input class="nc-text-stub" :value="modelValue" />',
	},
}

/**
 * Mount the dialog already open.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog() {
	const wrapper = mount(LinkRepoDialog, {
		props: { open: false, slug: 'shop' },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('LinkRepoDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axiosMock.post.mockResolvedValue({ data: { githubDefaultBranch: 'main' } })
	})

	it('asks for the two things linking needs, and nothing it cannot do', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.findAllComponents({ name: 'NcTextField' })).toHaveLength(2)
	})

	it('posts the owner and name it was given', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.owner = 'ConductionNL'
		wrapper.vm.name = 'buildiq-shop'

		await wrapper.vm.submit()

		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toContain('/apps/buildiq/api/applications/shop/github/link')
		expect(body).toEqual({ owner: 'ConductionNL', name: 'buildiq-shop' })
	})
})
