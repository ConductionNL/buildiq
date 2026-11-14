/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests pinning `src/dialogs/ExportDialog.vue`'s submit: the full
 * payload per target (ZIP, own server, GitHub), the refusal of a GitHub
 * export without a credential, the events on success, and the failure
 * message.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ExportDialog from '../../../src/dialogs/ExportDialog.vue'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button><slot /></button>' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcTextField: { template: '<input />' },
	NcSelect: { template: '<div />' },
}

async function mountDialog(props = {}) {
	axios.get.mockResolvedValue({ data: { results: [] } })
	const wrapper = mount(ExportDialog, {
		props: {
			applicationSlug: 'hello world',
			availableVersions: [{ label: '1.0.0', value: '1.0.0', slug: 'production' }],
			dataRegisters: [{ register: 'reg-a', label: 'A' }],
			...props,
		},
		global: { stubs, mocks: { t: (app, s) => s } },
	})
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('ExportDialog submit', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axios.post.mockResolvedValue({ data: { uuid: 'job-1' } })
	})

	it('posts the full ZIP payload, starts the job, emits queued and close', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.form.version = { label: '1.0.0', value: '1.0.0', slug: 'production' }
		wrapper.vm.dataRegisterChoices[0].includeData = true
		wrapper.vm.flowChoices = [
			{ flow: 'f1', label: 'One', include: true },
			{ flow: 'f2', label: 'Two', include: false },
		]
		await wrapper.vm.submit()
		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/applications/hello%20world/exports')
		expect(payload).toEqual({
			applicationVersion: '1.0.0',
			applicationVersionSlug: 'production',
			target: 'zip',
			license: 'EUPL-1.2',
			includeSeedData: false,
			dataRegisters: [{ register: 'reg-a', includeData: true }],
			flows: [{ flow: 'f1' }],
		})
		expect(Object.keys(payload)).toEqual([
			'applicationVersion',
			'applicationVersionSlug',
			'target',
			'license',
			'includeSeedData',
			'dataRegisters',
			'flows',
		])
		expect(axios.post.mock.calls[1][0]).toBe('/apps/buildiq/api/exports/job-1/run')
		expect(wrapper.emitted('queued')[0]).toEqual(['job-1'])
		expect(wrapper.emitted('close')).toBeTruthy()
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('leaves the version slug out when the version has none', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.form.version = { label: '0.1.0', value: '0.1.0' }
		await wrapper.vm.submit()
		expect(axios.post.mock.calls[0][1]).not.toHaveProperty('applicationVersionSlug')
	})

	it('adds the server bundle flag for the own-server target only', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.form.target = { label: 'Own server', value: 'server' }
		wrapper.vm.form.includeServerBundle = false
		await wrapper.vm.submit()
		const payload = axios.post.mock.calls[0][1]
		expect(payload.includeServerBundle).toBe(false)
		expect(Object.keys(payload).at(-1)).toBe('includeServerBundle')
	})

	it('adds the GitHub fields after the base payload', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.form.target = { label: 'Push to GitHub', value: 'github' }
		wrapper.vm.form.githubOrg = 'acme'
		wrapper.vm.form.githubRepo = 'repo'
		wrapper.vm.form.githubVisibility = { label: 'Public', value: 'public' }
		wrapper.vm.form.githubCredential = { label: 'Mine', value: 'cred-1' }
		await wrapper.vm.submit()
		const payload = axios.post.mock.calls[0][1]
		expect(payload).toMatchObject({
			target: 'github',
			githubOrg: 'acme',
			githubRepo: 'repo',
			githubVisibility: 'public',
			githubCredentialId: 'cred-1',
		})
		expect(Object.keys(payload).slice(-4)).toEqual([
			'githubOrg',
			'githubRepo',
			'githubVisibility',
			'githubCredentialId',
		])
		expect(payload).not.toHaveProperty('includeServerBundle')
	})

	it('refuses a GitHub export without a credential, with its message', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.form.target = { label: 'Push to GitHub', value: 'github' }
		wrapper.vm.errorMessage = 'old'
		await wrapper.vm.submit()
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.errorMessage).toBe('Pick a GitHub credential to push with.')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('queued')).toBeUndefined()
	})

	it.each([
		[{ response: { data: { error: 'Quota reached' } } }, 'Quota reached'],
		[{ response: { data: {} } }, 'GitHub authentication failed. Please check the token scope and try again.'],
		[new Error('down'), 'GitHub authentication failed. Please check the token scope and try again.'],
	])('shows the failure of %j as %j', async (thrown, expected) => {
		axios.post.mockImplementation(async () => {
			throw thrown
		})
		const wrapper = await mountDialog()
		await wrapper.vm.submit()
		expect(wrapper.vm.errorMessage).toBe(expected)
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
