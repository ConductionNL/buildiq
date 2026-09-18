/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/ExportDialog.vue` — the no-token contract.
 *
 * This dialog used to render a password field for the user's GitHub Personal Access
 * Token, which was POSTed to Buildiq and replayed by Buildiq against api.github.com.
 * It now picks a credential from OpenRegister's broker instead: Buildiq learns a UUID,
 * never the token behind it.
 *
 * Covers:
 *   - no password/token input is rendered for the GitHub target
 *   - the submitted payload carries `githubCredentialId` and never `githubPat`
 *   - a GitHub export cannot be submitted without a credential
 *   - only `github`-provider credentials are offered
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
	NcDialog: {
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
	NcButton: { template: '<button><slot /></button>' },
	NcCheckboxRadioSwitch: { template: '<label><slot /></label>' },
	NcTextField: {
		props: ['label', 'type', 'value'],
		template: '<input :type="type || \'text\'" :aria-label="label" />',
	},
	NcSelect: {
		props: ['options', 'inputLabel', 'loading', 'placeholder'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
}

/**
 * Mount the dialog with the GitHub target already selected.
 *
 * @param {Array} credentials - Credential objects the broker endpoint returns.
 * @return {object} The mounted wrapper.
 */
async function mountWithGithubTarget(credentials = []) {
	axios.get.mockResolvedValue({ data: { results: credentials } })

	const wrapper = mount(ExportDialog, {
		props: { applicationSlug: 'hello-world' },
		global: { stubs, mocks: { t: (app, s) => s } },
	})

	wrapper.vm.form.target = { label: 'Push to GitHub', value: 'github' }
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('ExportDialog — the GitHub target holds no token', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	it('renders no password or token input for the GitHub target', async () => {
		const wrapper = await mountWithGithubTarget([
			{ id: 'cred-1', name: 'My GitHub', provider: 'github' },
		])

		expect(wrapper.findAll('input[type="password"]')).toHaveLength(0)
		expect(wrapper.html().toLowerCase()).not.toContain('access token')
	})

	it('offers only github-provider credentials', async () => {
		const wrapper = await mountWithGithubTarget([
			{ id: 'cred-1', name: 'My GitHub', provider: 'github' },
			{ id: 'cred-2', name: 'My Stripe key', provider: 'stripe' },
		])

		expect(wrapper.vm.githubCredentials).toEqual([
			{ label: 'My GitHub', value: 'cred-1' },
		])
	})

	it('submits a credential reference and never a PAT', async () => {
		axios.post.mockResolvedValue({ data: { uuid: 'job-1' } })

		const wrapper = await mountWithGithubTarget([
			{ id: 'cred-1', name: 'My GitHub', provider: 'github' },
		])

		wrapper.vm.form.githubOrg = 'acme-co'
		wrapper.vm.form.githubRepo = 'hello-world'
		await wrapper.vm.submit()

		// The submit, then the call that starts the export right away.
		expect(axios.post).toHaveBeenCalledTimes(2)
		const payload = axios.post.mock.calls[0][1]

		expect(payload.githubCredentialId).toBe('cred-1')
		expect(payload).not.toHaveProperty('githubPat')
		// Belt and braces: nothing token-shaped anywhere in the body.
		expect(JSON.stringify(payload)).not.toMatch(/gh[pousr]_[A-Za-z0-9]{10,}/)
	})

	it('refuses to queue a GitHub export with no credential', async () => {
		const wrapper = await mountWithGithubTarget([])

		wrapper.vm.form.githubOrg = 'acme-co'
		wrapper.vm.form.githubRepo = 'hello-world'
		await wrapper.vm.submit()

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.errorMessage).toBeTruthy()
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('auto-selects the credential when the user has exactly one', async () => {
		const wrapper = await mountWithGithubTarget([
			{ id: 'cred-1', name: 'My GitHub', provider: 'github' },
		])

		expect(wrapper.vm.form.githubCredential).toEqual({
			label: 'My GitHub',
			value: 'cred-1',
		})
	})
})

describe('ExportDialog — versions and starting the export', () => {
	beforeEach(() => {
		vi.clearAllMocks()
	})

	/**
	 * Mount the dialog with the versions API answering two versions.
	 *
	 * @return {Promise<object>} The mounted wrapper.
	 */
	async function mountWithVersions() {
		axios.get.mockImplementation(async (url) => {
			if (String(url).includes('/versions')) {
				return {
					data: [
						{ name: 'Production', slug: 'production', semver: '0.1.0' },
						{
							name: 'Development',
							slug: 'development',
							semver: '0.1.0',
						},
					],
				}
			}
			return { data: { results: [] } }
		})
		const wrapper = mount(ExportDialog, {
			props: { applicationSlug: 'hello-world' },
			global: { stubs, mocks: { t: (app, s) => s } },
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		return wrapper
	}

	it("offers the application's own versions and starts on the live draft", async () => {
		const wrapper = await mountWithVersions()

		expect(wrapper.vm.versionOptions.map((option) => option.slug)).toEqual([
			'production',
			'development',
		])
		expect(wrapper.vm.form.version.slug).toBe('development')
	})

	it('sends the chosen version slug and then starts the export right away', async () => {
		axios.post.mockResolvedValue({ data: { uuid: 'job-9' } })
		const wrapper = await mountWithVersions()

		await wrapper.vm.submit()

		expect(axios.post.mock.calls[0][1]).toMatchObject({
			applicationVersion: '0.1.0',
			applicationVersionSlug: 'development',
		})
		expect(axios.post.mock.calls[1][0]).toBe(
			'/apps/buildiq/api/exports/job-9/run',
		)
		expect(wrapper.emitted('queued')).toEqual([['job-9']])
	})

	it('still closes when starting right away fails, leaving the job for cron', async () => {
		axios.post
			.mockResolvedValueOnce({ data: { uuid: 'job-10' } })
			.mockRejectedValueOnce(new Error('network'))
		const wrapper = await mountWithVersions()

		await wrapper.vm.submit()
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.errorMessage).toBe('')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('keeps the fallback version when the versions call fails', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = mount(ExportDialog, {
			props: { applicationSlug: 'hello-world' },
			global: { stubs, mocks: { t: (app, s) => s } },
		})
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.versionOptions).toEqual([
			{ label: '0.1.0', value: '0.1.0' },
		])
		expect(wrapper.vm.form.version.value).toBe('0.1.0')
	})
})
