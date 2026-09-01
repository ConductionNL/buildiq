/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the remote-install behaviour of
 * `src/modals/CloneTemplateDialog.vue`.
 *
 * Covers buildiq-remote-template-store:
 *   - when :remote=true with a remoteSlug, submit POSTs to the store install
 *     endpoint and emits `installed` (+ `close`) with the created app
 *   - a failed remote install surfaces a generic error and re-enables submit
 *   - when not remote, submit emits `submit` to the parent's local clone path
 *     and never hits the store install endpoint
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	// Mirror the real generateUrl path-template expansion of `{slug}`.
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

vi.mock('@nextcloud/axios', () => ({
	default: axiosMock,
}))

import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

/**
 * Mount the dialog open, with the given props.
 *
 * @param {object} props Extra props merged over the open + template defaults.
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountDialog(props = {}) {
	const wrapper = mount(CloneTemplateDialog, {
		propsData: {
			open: true,
			template: { slug: 'permit-tracker', title: 'Permit Tracker' },
			...props,
		},
		stubs: {
			NcModal: {
				name: 'NcModal',
				template: '<div class="nc-modal-stub"><slot /></div>',
			},
			NcButton: {
				name: 'NcButton',
				props: ['disabled'],
				template:
					'<button class="nc-button-stub" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
			},
			NcTextField: {
				name: 'NcTextField',
				props: ['value', 'label', 'placeholder'],
				template:
					'<input class="nc-textfield-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
			},
		},
	})
	await wrapper.vm.$nextTick()
	return wrapper
}

describe('CloneTemplateDialog.vue — remote store install', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('POSTs to the store install endpoint and emits installed + close on success (remote)', async () => {
		const wrapper = await mountDialog({
			remote: true,
			remoteSlug: 'permit-tracker',
		})

		// Provide a valid name + slug.
		wrapper.vm.localName = 'My Permits'
		wrapper.vm.localSlug = 'my-permits'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)

		const created = { uuid: 'new-app', slug: 'my-permits' }
		axiosMock.post.mockResolvedValueOnce({ data: created })

		await wrapper.vm.submit()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/buildiq/api/store/templates/permit-tracker/install')
		expect(payload).toEqual({ name: 'My Permits', slug: 'my-permits' })

		expect(wrapper.emitted('installed')).toBeTruthy()
		expect(wrapper.emitted('installed')[0][0]).toEqual(created)
		expect(wrapper.emitted('close')).toBeTruthy()
		// No local clone path emission on the remote install.
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('surfaces a generic error and re-enables submit on a failed remote install', async () => {
		const wrapper = await mountDialog({
			remote: true,
			remoteSlug: 'permit-tracker',
		})

		wrapper.vm.localName = 'My Permits'
		wrapper.vm.localSlug = 'my-permits'
		await wrapper.vm.$nextTick()

		axiosMock.post.mockRejectedValueOnce({
			response: { data: { error: 'template_not_found' } },
		})

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('template_not_found')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('installed')).toBeFalsy()
	})

	it('uses the local clone path (emits submit) and never hits the store endpoint when not remote', async () => {
		const wrapper = await mountDialog({ remote: false })

		wrapper.vm.localName = 'My Permits'
		wrapper.vm.localSlug = 'my-permits'
		await wrapper.vm.$nextTick()

		await wrapper.vm.submit()

		expect(wrapper.emitted('submit')).toBeTruthy()
		expect(wrapper.emitted('submit')[0][0]).toEqual({
			name: 'My Permits',
			slug: 'my-permits',
		})
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('blocks submit on an invalid slug and sets a validation error (remote)', async () => {
		const wrapper = await mountDialog({
			remote: true,
			remoteSlug: 'permit-tracker',
		})

		wrapper.vm.localName = 'My Permits'
		wrapper.vm.localSlug = 'Not Valid Slug'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBeTruthy()
	})
})
