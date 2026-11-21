/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the RunAutomationAction runtime registry component
 * (logic-automation-actions-that-run REQ-BQAR-006).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => get(...a), post: (...a) => post(...a) } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
const showSuccess = vi.fn()
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (...a) => showSuccess(...a), showError: (...a) => showError(...a) }))

import RunAutomationAction from '../../../src/components/runtime/RunAutomationAction.vue'

const stubs = {
	NcActions: { name: 'NcActions', props: ['disabled'], template: '<div class="actions"><slot /></div>' },
	NcActionButton: { name: 'NcActionButton', emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
}

const object = { '@self': { id: 'rec-42', register: 'permits', schema: 'vergunning' } }

function factory({ appSlug = 'permits', props = {} } = {}) {
	return mount(RunAutomationAction, {
		propsData: { object, objectId: 'rec-42', ...props },
		provide: { cnAppSlug: () => appSlug, cnObjectContext: { register: 'permits', schema: 'vergunning' } },
		stubs,
	})
}

describe('RunAutomationAction', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
		showSuccess.mockReset()
		showError.mockReset()
	})

	it('asks for the app\'s manual automations of the record\'s schema and lists them', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'aut-1', name: 'Send to inspection' }] } })
		const wrapper = factory()
		await flushPromises()

		expect(get).toHaveBeenCalledWith('/apps/buildiq/api/automations/manual', { params: { app: 'permits', schema: 'vergunning' } })
		expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Send to inspection'])
	})

	it('renders nothing when the caller may not run any', async () => {
		get.mockRejectedValue({ response: { status: 403 } })
		const wrapper = factory()
		await flushPromises()

		expect(wrapper.find('.actions').exists()).toBe(false)
	})

	it('renders nothing and asks nothing without an app', async () => {
		const wrapper = factory({ appSlug: '' })
		await flushPromises()

		expect(get).not.toHaveBeenCalled()
		expect(wrapper.find('.actions').exists()).toBe(false)
	})

	it('runs the chosen automation on the record and confirms it started', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'aut-1', name: 'Send to inspection' }] } })
		post.mockResolvedValue({ data: { started: true } })
		const wrapper = factory()
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/buildiq/api/automations/aut-1/run', { object: 'rec-42' })
		expect(showSuccess).toHaveBeenCalledTimes(1)
		expect(showSuccess.mock.calls[0][0]).toContain('started')
		expect(showError).not.toHaveBeenCalled()
	})

	it('says so when the run is refused', async () => {
		get.mockResolvedValue({ data: { results: [{ uuid: 'aut-1', name: 'Send to inspection' }] } })
		post.mockRejectedValue({ response: { status: 403 } })
		const wrapper = factory()
		await flushPromises()

		await wrapper.find('button').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError.mock.calls[0][0]).toContain('could not be started')
		expect(showSuccess).not.toHaveBeenCalled()
	})
})
