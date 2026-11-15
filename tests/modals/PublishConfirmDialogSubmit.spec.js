/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for `PublishConfirmDialog.submit()`: every branch of
 * the push call — the guards, the version it names, an outcome the endpoint
 * refuses with, the success events, and each source the failure sentence is
 * taken from, in the order they are tried.
 */
import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars
			? String(key).replace(/\{(\w+)\}/g, (_, k) =>
					vars[k] !== null && vars[k] !== undefined ? vars[k] : `{${k}}`,
				)
			: key
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
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		template: '<select class="nc-select-stub" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder'],
		template: '<input class="nc-text-stub" :value="modelValue" />',
	},
}

/**
 * Mount the dialog already open, so its `open` watcher has seeded the fields.
 *
 * @param {object} props Props to override.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDialog(props = {}) {
	const wrapper = mount(PublishConfirmDialog, {
		props: {
			open: false,
			slug: 'shop',
			credentialId: 'cred-1',
			credentialName: 'My GitHub',
			versions: [{ slug: 'production', name: 'Production', semver: '1.0.0' }],
			repo: null,
			...props,
		},
		global: { stubs: STUBS },
	})
	await wrapper.setProps({ open: true })
	await wrapper.vm.$nextTick()
	return wrapper
}


const LINKED = { repo: { owner: 'conduction', name: 'petstore', branch: 'main' } }

describe('PublishConfirmDialog submit()', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axiosMock.post.mockResolvedValue({
			data: { outcome: 'ok', commitSha: 'abc1234' },
		})
	})

	it('does nothing without a credential', async () => {
		const wrapper = await mountDialog({ credentialId: '' })
		await wrapper.vm.submit()
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('does nothing while a push is in flight', async () => {
		const wrapper = await mountDialog(LINKED)
		wrapper.vm.submitting = true
		await wrapper.vm.submit()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('posts to the slug push URL and names a selected version object by its id', async () => {
		const wrapper = await mountDialog(LINKED)
		wrapper.vm.selectedVersion = { id: 'production', label: 'Production' }
		await wrapper.vm.submit()
		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/applications/shop/github/push')
		expect(body).toEqual({ credentialId: 'cred-1', versionSlug: 'production' })
	})

	it('names a selected version string as it is', async () => {
		const wrapper = await mountDialog(LINKED)
		wrapper.vm.selectedVersion = 'beta'
		await wrapper.vm.submit()
		expect(axiosMock.post.mock.calls[0][1]).toEqual({ credentialId: 'cred-1', versionSlug: 'beta' })
	})

	it('sends no version without a selection', async () => {
		const wrapper = await mountDialog(LINKED)
		wrapper.vm.selectedVersion = null
		await wrapper.vm.submit()
		expect(axiosMock.post.mock.calls[0][1]).toEqual({ credentialId: 'cred-1' })
	})

	it('emits published with the answer, then close, on success', async () => {
		const wrapper = await mountDialog(LINKED)
		await wrapper.vm.submit()
		expect(wrapper.emitted('published')[0][0]).toEqual({ outcome: 'ok', commitSha: 'abc1234' })
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.error).toBe('')
	})

	it('treats an answer without an outcome as success', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const wrapper = await mountDialog(LINKED)
		await wrapper.vm.submit()
		expect(wrapper.emitted('published')).toHaveLength(1)
	})

	it('says what a refused outcome means and stays open', async () => {
		axiosMock.post.mockResolvedValue({ data: { outcome: 'github_rate_limited' } })
		const wrapper = await mountDialog(LINKED)
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe(wrapper.vm.outcomeMessage('github_rate_limited'))
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('published')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('still counts a non-ok outcome that carries a commit as published', async () => {
		axiosMock.post.mockResolvedValue({ data: { outcome: 'push_conflict', commitSha: 'def5678' } })
		const wrapper = await mountDialog(LINKED)
		await wrapper.vm.submit()
		expect(wrapper.emitted('published')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it.each([
		['the outcome', { response: { data: { outcome: 'github_rate_limited', detail: 'd', error: 'e' } }, message: 'm' }, 'OUTCOME'],
		['the detail', { response: { data: { detail: 'Detail sentence.', error: 'e' } }, message: 'm' }, 'Detail sentence.'],
		['the error', { response: { data: { error: 'error_code' } }, message: 'm' }, 'error_code'],
		['the exception message', { message: 'Network Error' }, 'Network Error'],
		['the fallback', {}, 'Publishing failed.'],
	])('takes a failure sentence from %s', async (_label, failure, expected) => {
		axiosMock.post.mockRejectedValue(failure)
		const wrapper = await mountDialog(LINKED)
		await wrapper.vm.submit()
		const sentence = expected === 'OUTCOME' ? wrapper.vm.outcomeMessage('github_rate_limited') : expected
		expect(wrapper.vm.error).toBe(sentence)
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('published')).toBeUndefined()
	})
})
