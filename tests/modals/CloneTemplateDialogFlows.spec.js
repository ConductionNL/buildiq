/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning every branch of `src/modals/CloneTemplateDialog.vue`'s
 * submit flows: the opening seed, the local clone failure, the remote and
 * GitHub install failure messages, the GitHub ref and the missing repository
 * identity.
 */

import { mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars ? String(key).replace(/\{(\w+)\}/g, (_, k) => vars[k]) : key
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

import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

const stubs = {
	NcModal: { name: 'NcModal', template: '<div><slot /></div>' },
	NcButton: { name: 'NcButton', template: '<button><slot /></button>' },
	NcTextField: { name: 'NcTextField', template: '<input />' },
	NcTextArea: { name: 'NcTextArea', template: '<textarea />' },
}

/**
 * Mount the dialog closed, open it, and fill a valid name and slug.
 *
 * @param {object} props Props merged over a closed dialog.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(props = {}) {
	const wrapper = mount(CloneTemplateDialog, {
		propsData: { open: false, template: { slug: 'petstore', title: 'Pet Store' }, ...props },
		stubs,
	})
	await wrapper.setProps({ open: true })
	return wrapper
}

/**
 * Fill a valid name and slug.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<void>}
 */
async function fillValid(wrapper) {
	wrapper.vm.localName = ' Pet Store '
	wrapper.vm.localSlug = 'pet-store'
	await wrapper.vm.$nextTick()
}

describe('CloneTemplateDialog opening seed', () => {
	it.each([
		[{ slug: 'my-app', title: 'My App' }, 'My App', 'my-app'],
		[{ slug: 'Only_Slug' }, 'Only_Slug', 'only-slug'],
		[{ title: 'Only Title' }, 'Only Title', 'only-title'],
		[{}, '', ''],
		[null, '', ''],
	])('seeds %j as name %j and slug %j and clears the rest', async (template, name, slug) => {
		const wrapper = mount(CloneTemplateDialog, { propsData: { open: false, template }, stubs })
		wrapper.vm.localDescription = 'old'
		wrapper.vm.slugEdited = true
		wrapper.vm.error = 'old'
		wrapper.vm.submitting = true
		await wrapper.setProps({ open: true })
		expect(wrapper.vm.localName).toBe(name)
		expect(wrapper.vm.localSlug).toBe(slug)
		expect(wrapper.vm.localDescription).toBe('')
		expect(wrapper.vm.slugEdited).toBe(false)
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('leaves the fields alone on close', async () => {
		const wrapper = await openDialog()
		wrapper.vm.localName = 'kept'
		await wrapper.setProps({ open: false })
		expect(wrapper.vm.localName).toBe('kept')
	})
})

describe('CloneTemplateDialog submit', () => {
	beforeEach(() => {
		axiosMock.post.mockReset()
	})

	it('refuses an incomplete form with the validation message', async () => {
		const wrapper = await openDialog()
		wrapper.vm.localName = '  '
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe('Provide a name and a kebab-case slug (max 32 chars).')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('emits the local clone with the trimmed description and stays submitting', async () => {
		const wrapper = await openDialog()
		await fillValid(wrapper)
		wrapper.vm.localDescription = ' about '
		await wrapper.vm.submit()
		expect(wrapper.emitted('submit')[0][0]).toEqual({ name: 'Pet Store', slug: 'pet-store', description: 'about' })
		expect(wrapper.vm.submitting).toBe(true)
		expect(wrapper.vm.error).toBe('')
	})

	it('sends no description on a remote install', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: { uuid: 'x' } })
		const wrapper = await openDialog({ remote: true, remoteSlug: 'petstore' })
		await fillValid(wrapper)
		await wrapper.vm.submit()
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/store/templates/petstore/install', {
			name: 'Pet Store',
			slug: 'pet-store',
		})
		expect(wrapper.emitted('installed')[0][0]).toEqual({ uuid: 'x' })
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	const failures = [
		['detail', { response: { data: { detail: 'D', error: 'E' } }, message: 'M' }, 'D'],
		['error', { response: { data: { error: 'E' } }, message: 'M' }, 'E'],
		['message', { response: { data: {} }, message: 'M' }, 'M'],
		['default', {}, 'Install failed.'],
		['null', null, 'Install failed.'],
	]

	it.each(failures)('a failed remote install shows the %s', async (label, thrown, expected) => {
		axiosMock.post.mockRejectedValueOnce(thrown)
		const wrapper = await openDialog({ remote: true, remoteSlug: 'petstore' })
		await fillValid(wrapper)
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe(expected)
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('installed')).toBeFalsy()
	})

	it.each(failures)('a failed GitHub install shows the %s', async (label, thrown, expected) => {
		axiosMock.post.mockRejectedValueOnce(thrown)
		const wrapper = await openDialog({ github: true, githubRepo: { owner: 'o', repo: 'r' } })
		await fillValid(wrapper)
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe(expected)
		expect(wrapper.vm.submitting).toBe(false)
	})

	it.each([
		[{ file: 'a.json', path: 'b.json', error: 'E' }, 'E (in a.json)'],
		[{ path: 'b.json', detail: 'D' }, 'D (in b.json)'],
	])('a failed GitHub install names the file of %j', async (data, expected) => {
		axiosMock.post.mockRejectedValueOnce({ response: { data } })
		const wrapper = await openDialog({ github: true, githubRepo: { owner: 'o', repo: 'r' } })
		await fillValid(wrapper)
		await wrapper.vm.submit()
		expect(wrapper.vm.error).toBe(expected)
	})

	it('passes the GitHub ref when the repository names one', async () => {
		axiosMock.post.mockResolvedValueOnce({ data: {} })
		const wrapper = await openDialog({ github: true, githubRepo: { owner: 'o', repo: 'r', ref: 'v1' } })
		await fillValid(wrapper)
		await wrapper.vm.submit()
		expect(axiosMock.post).toHaveBeenCalledWith('/apps/buildiq/api/shop/github/install', {
			owner: 'o',
			repo: 'r',
			name: 'Pet Store',
			slug: 'pet-store',
			ref: 'v1',
		})
	})

	it.each([[null], [{ owner: 'o' }], [{ repo: 'r' }]])(
		'refuses a GitHub install without a repository identity (%j)',
		async (githubRepo) => {
			const wrapper = await openDialog({ github: true, githubRepo })
			await fillValid(wrapper)
			await wrapper.vm.submit()
			expect(wrapper.vm.error).toBe('This GitHub app is missing its repository identity.')
			expect(wrapper.vm.submitting).toBe(false)
			expect(axiosMock.post).not.toHaveBeenCalled()
		},
	)
})
