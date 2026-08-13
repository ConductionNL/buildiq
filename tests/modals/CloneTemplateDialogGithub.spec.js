/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the GitHub-shop install behaviour of
 * `src/modals/CloneTemplateDialog.vue` (github-shop-catalogue).
 *
 * Covers template-catalogue-ui:
 *   - when :github=true with a githubRepo, a valid submit POSTs to the GitHub
 *     shop install endpoint and emits `installed` (+ `close`)
 *   - a strict-parse failure returned by the endpoint is surfaced in the dialog
 *     naming the offending file, creating nothing (no installed emission)
 *   - submission stays gated on a valid target (canSubmit)
 */

import {
	describe,
	it,
	expect,
	beforeAll,
	afterAll,
	beforeEach,
	afterEach,
	vi,
} from 'vitest'
import { mount } from '@vue/test-utils'

// The global setup stub returns the bare key; give script-level t() real
// {placeholder} interpolation so the "naming the offending file" assertion
// can see the file name substituted into the error string.
const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars) =>
		vars
			? String(key).replace(/\{(\w+)\}/g, (_, k) =>
					vars[k] != null ? vars[k] : `{${k}}`,
				)
			: key
})
afterAll(() => {
	globalThis.t = realT
})

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? `{${k}}`),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

import CloneTemplateDialog from '../../src/modals/CloneTemplateDialog.vue'

/**
 * Mount the dialog open in GitHub-install mode.
 *
 * @param {object} props Extra props merged over the defaults.
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountDialog(props = {}) {
	const wrapper = mount(CloneTemplateDialog, {
		propsData: {
			open: true,
			template: { slug: 'petstore', title: 'Pet Store' },
			github: true,
			githubRepo: { owner: 'conduction', repo: 'petstore' },
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

describe('CloneTemplateDialog.vue — GitHub shop install', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('POSTs to the GitHub install endpoint and emits installed + close on success', async () => {
		const wrapper = await mountDialog()

		wrapper.vm.localName = 'Pet Store'
		wrapper.vm.localSlug = 'pet-store'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(true)

		const created = { uuid: 'new-app', slug: 'pet-store', register: 'pet-store' }
		axiosMock.post.mockResolvedValueOnce({ data: created })

		await wrapper.vm.submit()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/openbuild/api/shop/github/install')
		expect(body).toEqual({
			owner: 'conduction',
			repo: 'petstore',
			name: 'Pet Store',
			slug: 'pet-store',
		})

		expect(wrapper.emitted('installed')).toBeTruthy()
		expect(wrapper.emitted('installed')[0][0]).toEqual(created)
		expect(wrapper.emitted('close')).toBeTruthy()
		// GitHub install never uses the local clone (submit) path.
		expect(wrapper.emitted('submit')).toBeFalsy()
	})

	it('surfaces a strict-parse failure naming the offending file, creating nothing', async () => {
		const wrapper = await mountDialog()

		wrapper.vm.localName = 'Pet Store'
		wrapper.vm.localSlug = 'pet-store'
		await wrapper.vm.$nextTick()

		axiosMock.post.mockRejectedValueOnce({
			response: {
				data: { error: 'schema_invalid', file: 'schemas/pet.json' },
			},
		})

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toContain('schemas/pet.json')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.emitted('installed')).toBeFalsy()
	})

	it('blocks submit on an invalid slug and never hits the endpoint', async () => {
		const wrapper = await mountDialog()

		wrapper.vm.localName = 'Pet Store'
		wrapper.vm.localSlug = 'Not Valid'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)

		await wrapper.vm.submit()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBeTruthy()
	})
})
