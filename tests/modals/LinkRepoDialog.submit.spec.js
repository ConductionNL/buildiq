/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning `src/modals/LinkRepoDialog.vue`'s submit: the
 * guards, the trimmed body, the events on success and the failure message
 * precedence.
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
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, k) => params[k]),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import LinkRepoDialog from '../../src/modals/LinkRepoDialog.vue'

const STUBS = {
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
}

async function filledDialog() {
	const wrapper = mount(LinkRepoDialog, {
		props: { open: false, slug: 'shop' },
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	wrapper.vm.owner = ' ConductionNL '
	wrapper.vm.name = ' shop-repo '
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('LinkRepoDialog submit', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('does nothing while incomplete or already submitting', async () => {
		const wrapper = await filledDialog()
		wrapper.vm.submitting = true
		await wrapper.vm.submit()
		wrapper.vm.submitting = false
		wrapper.vm.owner = ''
		await wrapper.vm.$nextTick()
		await wrapper.vm.submit()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('posts the trimmed repository and emits linked then close', async () => {
		axiosMock.post.mockResolvedValue({ data: { githubDefaultBranch: 'main' } })
		const wrapper = await filledDialog()
		wrapper.vm.error = 'old'
		await wrapper.vm.submit()
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/applications/shop/github/link', {
			owner: 'ConductionNL',
			name: 'shop-repo',
		})
		expect(wrapper.emitted('linked')[0][0]).toEqual({ githubDefaultBranch: 'main' })
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.submitting).toBe(true)
	})

	it.each([
		[{ response: { data: { detail: 'D', error: 'E' } }, message: 'M' }, 'D'],
		[{ response: { data: { error: 'E' } }, message: 'M' }, 'E'],
		[{ response: {}, message: 'M' }, 'M'],
		[{}, 'Could not link the repository.'],
		[null, 'Could not link the repository.'],
	])('shows the failure of %j as %j', async (thrown, expected) => {
		axiosMock.post.mockImplementation(async () => {
			throw thrown
		})
		const wrapper = await filledDialog()
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe(expected)
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('linked')).toBeFalsy()
	})
})
