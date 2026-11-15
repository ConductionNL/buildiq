/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ApplicationManifestTab.vue's save: the role gate
 * (editor / owner only), the parse-and-validate step that stops a bad
 * manifest before any PUT, the wrapped `{ manifest }` body on the slug
 * route, and the saved / failed outcomes. Also the read-only textarea and
 * hidden Save button for a viewer.
 */
import { shallowMount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, validateManifest, loadState } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), put: vi.fn() },
	validateManifest: vi.fn(),
	loadState: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState }))
vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	validateManifest,
}))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

import ApplicationManifestTab from '../../../src/components/tabs/ApplicationManifestTab.vue'

const APP = {
	'@self': { id: 'uuid-1' },
	slug: 'my app',
	permissions: { owners: ['o'], editors: ['e'], viewers: ['v'] },
}

async function mountAs(group) {
	loadState.mockReturnValue([group])
	const wrapper = shallowMount(ApplicationManifestTab, { props: { object: APP } })
	await new Promise((r) => setTimeout(r, 0))
	return wrapper
}

describe('ApplicationManifestTab save', () => {
	beforeEach(() => {
		axiosMock.get.mockReset().mockResolvedValue({ data: { pages: [] } })
		axiosMock.put.mockReset()
		validateManifest.mockReset().mockReturnValue({ valid: true, errors: [] })
	})

	it.each(['o', 'e'])('PUTs the parsed manifest wrapped for group %s', async (group) => {
		axiosMock.put.mockResolvedValue({ data: {} })
		const vm = (await mountAs(group)).vm
		vm.manifestText = '{"pages":["home"]}'
		await vm.save()
		expect(axiosMock.put).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/my%20app/manifest',
			{ manifest: { pages: ['home'] } },
		)
		expect(vm.savedToast).toBe('Saved')
		expect(vm.saving).toBe(false)
		expect(vm.error).toBe('')
	})

	it.each(['v', 'nobody'])('does nothing for group %s', async (group) => {
		const vm = (await mountAs(group)).vm
		vm.manifestText = '{"pages":[]}'
		await vm.save()
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(vm.savedToast).toBe('')
	})

	it('stops on unparsable JSON without a PUT', async () => {
		const vm = (await mountAs('o')).vm
		vm.manifestText = '{broken'
		await vm.save()
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(vm.error).toMatch(/^JSON parse error: /)
		expect(vm.saving).toBe(false)
	})

	it('reports a failed PUT and resets saving', async () => {
		axiosMock.put.mockRejectedValue(new Error('conflict'))
		const vm = (await mountAs('o')).vm
		vm.manifestText = '{}'
		await vm.save()
		expect(vm.error).toBe('Save failed: conflict')
		expect(vm.savedToast).toBe('')
		expect(vm.saving).toBe(false)
	})

	it('reports a thrown non-Error value as is', async () => {
		axiosMock.put.mockImplementation(async () => {
			throw 'offline'
		})
		const vm = (await mountAs('e')).vm
		vm.manifestText = '{}'
		await vm.save()
		expect(vm.error).toBe('Save failed: offline')
	})
})

describe('ApplicationManifestTab role in the template', () => {
	beforeEach(() => {
		axiosMock.get.mockReset().mockResolvedValue({ data: {} })
	})

	it('makes the textarea read-only and hides Save for a viewer', async () => {
		const wrapper = await mountAs('v')
		expect(wrapper.find('textarea').attributes('readonly')).toBeDefined()
		expect(wrapper.find('[data-testid="buildiq-editor-save"]').exists()).toBe(false)
	})

	it('makes the textarea editable and shows Save for an owner', async () => {
		const wrapper = await mountAs('o')
		expect(wrapper.find('textarea').attributes('readonly')).toBeUndefined()
		expect(wrapper.find('[data-testid="buildiq-editor-save"]').exists()).toBe(true)
	})

	it('shows the invalid-manifest message when there is an error', async () => {
		const wrapper = await mountAs('o')
		wrapper.vm.error = 'bad'
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-manifest-tab__error').text()).toBe('Invalid manifest: bad')
	})
})
