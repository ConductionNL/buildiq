/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/modals/PublishConfirmDialog.vue`.
 *
 * The case these cover is the first publish an app ever has. Before this
 * change the dialog posted only a credential, so the push endpoint answered
 * `not_linked` and the only route to a first repository was to create it on
 * github.com by hand: the endpoint's `repo` parameter, which creates, tags and
 * links in one call, had no caller in the UI at all.
 *
 * Watched failing: with the `body.repo` branch removed, the first two cases
 * report `expected undefined to deeply equal { name: 'openbuild-shop', org: '' }`
 * and the same for the org case.
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

describe('PublishConfirmDialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		axiosMock.post.mockResolvedValue({
			data: { outcome: 'ok', commitSha: 'abc1234', repoUrl: 'https://x/y' },
		})
	})

	it('asks the push endpoint to create the repository when there is none', async () => {
		const wrapper = await mountDialog()

		await wrapper.vm.submit()

		const [, body] = axiosMock.post.mock.calls[0]
		expect(body.repo).toEqual({ name: 'openbuild-shop', org: '' })
		expect(body.credentialId).toBe('cred-1')
	})

	it('creates under the organisation the owner names', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.repoName = 'buildiq-shop'
		wrapper.vm.org = 'ConductionNL'

		await wrapper.vm.submit()

		const [, body] = axiosMock.post.mock.calls[0]
		expect(body.repo).toEqual({ name: 'buildiq-shop', org: 'ConductionNL' })
	})

	it('sends no repository when the app already has one', async () => {
		const wrapper = await mountDialog({
			repo: { owner: 'conduction', name: 'petstore', branch: 'main' },
		})

		await wrapper.vm.submit()

		const [, body] = axiosMock.post.mock.calls[0]
		expect(body.repo).toBeUndefined()
	})

	it('refuses a name GitHub would not accept, and says so', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.repoName = 'not a repo name'

		await wrapper.vm.submit()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(
			'Give the repository a name of letters, numbers and dashes.',
		)
		expect(wrapper.vm.submitting).toBe(false)
	})

	it('names the rate limit instead of saying publishing failed', async () => {
		const wrapper = await mountDialog()

		expect(wrapper.vm.outcomeMessage('github_rate_limited')).toBe(
			'GitHub is rate-limiting this credential right now. Try again shortly.',
		)
	})
})
