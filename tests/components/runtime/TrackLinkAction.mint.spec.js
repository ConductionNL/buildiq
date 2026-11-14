/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest coverage of TrackLinkAction's mint failure path, its re-entrancy
 * guard and the copy-to-clipboard action (external-form-provisioning
 * REQ-EFP-006).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { mintTrackLink, showSuccess, showError } = vi.hoisted(() => ({
	mintTrackLink: vi.fn(),
	showSuccess: vi.fn(),
	showError: vi.fn(),
}))

vi.mock('../../../src/composables/useTrackLinkAction.js', () => ({
	useTrackLinkAction: () => ({ mintTrackLink }),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess, showError }))

import TrackLinkAction from '../../../src/components/runtime/TrackLinkAction.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

const object = { '@self': { id: 'obj-1', register: 'intake', schema: 'report' } }

const manifest = {
	runtime: {
		externalForms: [
			{ register: 'intake', schema: 'report', trackLinkAction: { enabled: true } },
		],
	},
}

/**
 * Mount the action over an eligible object.
 *
 * @return {import('@vue/test-utils').Wrapper}
 */
function factory() {
	return mount(TrackLinkAction, {
		propsData: { object, objectId: 'obj-1' },
		provide: { cnManifest: manifest },
		stubs,
	})
}

describe('TrackLinkAction mint and copy', () => {
	const originalClipboard = Object.getOwnPropertyDescriptor(navigator, 'clipboard')

	beforeEach(() => {
		mintTrackLink.mockReset()
		showSuccess.mockReset()
		showError.mockReset()
	})

	afterEach(() => {
		if (originalClipboard) {
			Object.defineProperty(navigator, 'clipboard', originalClipboard)
		} else {
			delete navigator.clipboard
		}
	})

	it('reports a mint failure with the error message and keeps no link', async () => {
		mintTrackLink.mockRejectedValue(new Error('quota exceeded'))
		const wrapper = factory()
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(showError).toHaveBeenCalledTimes(1)
		expect(showError).toHaveBeenCalledWith('Could not mint a track-link: {error}')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.vm.link).toBeFalsy()
		expect(wrapper.vm.minting).toBe(false)
		expect(wrapper.find('.ob-track-link-action__result').exists()).toBe(false)
	})

	it('announces a successful mint', async () => {
		mintTrackLink.mockResolvedValue({ url: '/t/abc' })
		const wrapper = factory()
		await wrapper.vm.mint()
		expect(showSuccess).toHaveBeenCalledWith('Track-link minted.')
		expect(wrapper.vm.minting).toBe(false)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.ob-track-link-action__result code').text()).toBe('/t/abc')
	})

	it('ignores a second mint while one is in flight and shows the busy label', async () => {
		let resolve
		mintTrackLink.mockReturnValue(new Promise((r) => { resolve = r }))
		const wrapper = factory()
		const first = wrapper.vm.mint()
		await wrapper.vm.$nextTick()
		const button = wrapper.find('button')
		expect(button.text()).toBe('Minting…')
		expect(button.attributes('disabled')).toBeDefined()
		await wrapper.vm.mint()
		expect(mintTrackLink).toHaveBeenCalledTimes(1)
		resolve({ url: '/t/1' })
		await first
		expect(wrapper.vm.link).toBe('/t/1')
	})

	it('copies the minted link to the clipboard', async () => {
		const writeText = vi.fn()
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
		mintTrackLink.mockResolvedValue({ url: '/t/copy-me' })
		const wrapper = factory()
		await wrapper.vm.mint()
		await wrapper.vm.$nextTick()
		showSuccess.mockReset()
		const copyButton = wrapper.findAll('button').at(1)
		expect(copyButton.text()).toBe('Copy')
		await copyButton.trigger('click')
		expect(writeText).toHaveBeenCalledWith('/t/copy-me')
		expect(showSuccess).toHaveBeenCalledWith('Link copied.')
	})

	it('does nothing on copy before a link was minted', () => {
		const writeText = vi.fn()
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
		const wrapper = factory()
		wrapper.vm.copy()
		expect(writeText).not.toHaveBeenCalled()
		expect(showSuccess).not.toHaveBeenCalled()
	})

	it('does not claim a copy when the clipboard API is missing', async () => {
		Object.defineProperty(navigator, 'clipboard', {
			value: undefined,
			configurable: true,
		})
		mintTrackLink.mockResolvedValue({ url: '/t/x' })
		const wrapper = factory()
		await wrapper.vm.mint()
		showSuccess.mockReset()
		wrapper.vm.copy()
		expect(showSuccess).not.toHaveBeenCalled()
	})
})
