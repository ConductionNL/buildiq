/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the TrackLinkAction runtime registry component
 * (external-form-provisioning REQ-EFP-006).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const mintTrackLink = vi.fn()
vi.mock('../../../src/composables/useTrackLinkAction.js', () => ({
	useTrackLinkAction: () => ({ mintTrackLink }),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showError: vi.fn() }))

import TrackLinkAction from '../../../src/components/runtime/TrackLinkAction.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

const object = { '@self': { id: 'obj-1', register: 'intake', schema: 'report' } }

const manifestWithEnabled = {
	runtime: {
		externalForms: [
			{
				id: 'ef-1',
				register: 'intake',
				schema: 'report',
				trackLinkAction: { enabled: true },
			},
		],
	},
}

function factory({ manifest = null, props = {} } = {}) {
	return mount(TrackLinkAction, {
		propsData: { object, objectId: 'obj-1', ...props },
		provide: { cnManifest: manifest },
		stubs,
	})
}

describe('TrackLinkAction', () => {
	beforeEach(() => {
		mintTrackLink.mockReset()
	})

	it('renders nothing when no externalForms entry enables trackLinkAction for this schema', () => {
		const wrapper = factory({ manifest: { runtime: { externalForms: [] } } })
		expect(wrapper.find('.ob-track-link-action').exists()).toBe(false)
	})

	it('renders nothing when cnManifest is not injected (outside CnAppRoot)', () => {
		const wrapper = factory({ manifest: null })
		expect(wrapper.find('.ob-track-link-action').exists()).toBe(false)
	})

	it('renders the Mint button when the schema is eligible', () => {
		const wrapper = factory({ manifest: manifestWithEnabled })
		expect(wrapper.find('.ob-track-link-action').exists()).toBe(true)
		expect(wrapper.text()).toContain('Mint track-link')
	})

	it('mints a track-link and shows the resulting URL', async () => {
		mintTrackLink.mockResolvedValue({
			token: 'tok-1',
			url: '/apps/openregister/api/public/case-tokens/tok-1',
		})
		const wrapper = factory({ manifest: manifestWithEnabled })
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(mintTrackLink).toHaveBeenCalledWith('intake', 'report', 'obj-1')
		expect(wrapper.vm.link).toBe(
			'/apps/openregister/api/public/case-tokens/tok-1',
		)
	})

	it('is offered only for the schema whose entry has trackLinkAction.enabled', () => {
		const manifest = {
			runtime: {
				externalForms: [
					{
						id: 'ef-1',
						register: 'other',
						schema: 'x',
						trackLinkAction: { enabled: true },
					},
				],
			},
		}
		const wrapper = factory({ manifest })
		expect(wrapper.find('.ob-track-link-action').exists()).toBe(false)
	})
})

function flushPromises() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}
