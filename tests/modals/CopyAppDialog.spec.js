/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for "Copy app" (change apps-copy-app-and-page, T03,
 * REQ-BQCP-001): the dialog and its entry in the app detail actions.
 */
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * A t() that fills its placeholders, so the suggested name can be read.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string}
 */
globalThis.t = (app, text, vars) =>
	Object.entries(vars || {}).reduce(
		(out, [k, v]) => out.replace(`{${k}}`, v),
		String(text),
	)

let groups = ['admin']
const postMock = vi.fn()
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn().mockResolvedValue({ data: { results: [] } }),
		post: (...a) => postMock(...a),
		put: vi.fn(),
		patch: vi.fn(),
	},
}))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => groups,
}))

const { default: CopyAppDialog } = await import('../../src/modals/CopyAppDialog.vue')
const { default: ApplicationDetailActions } =
	await import('../../src/components/ApplicationDetailActions.vue')

const stubs = {
	NcModal: {
		name: 'NcModal',
		template: '<div class="nc-modal-stub"><slot /></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		template:
			'<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		template:
			'<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

/**
 * Mount the dialog over "Permit tracker".
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(CopyAppDialog, {
		props: {
			open: true,
			application: { slug: 'permit-tracker', name: 'Permit tracker' },
		},
		global: { stubs },
	})
}

beforeEach(() => {
	postMock.mockReset()
	groups = ['admin']
})

describe('CopyAppDialog (REQ-BQCP-001)', () => {
	it('suggests a name and a slug for the copy', () => {
		const wrapper = mountDialog()
		const inputs = wrapper.findAll('input')
		expect(inputs[0].element.value).toBe('Copy of Permit tracker')
		expect(inputs[1].element.value).toBe('copy-of-permit-tracker')
	})

	it('copies the app under the chosen name and slug and reports the new app', async () => {
		postMock.mockResolvedValue({
			data: { uuid: 'new-uuid', slug: 'event-permits' },
		})
		const wrapper = mountDialog()
		const inputs = wrapper.findAll('input')
		await inputs[0].setValue('Event permits')
		await inputs[1].setValue('event-permits')

		await wrapper.find('button[data-variant="primary"]').trigger('click')
		await flushPromises()

		expect(postMock).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/permit-tracker/copy',
			{
				name: 'Event permits',
				slug: 'event-permits',
			},
		)
		expect(wrapper.emitted('copied')[0][0]).toEqual({
			uuid: 'new-uuid',
			slug: 'event-permits',
		})
	})

	it('says so when the slug is taken, and keeps the dialog open', async () => {
		postMock.mockRejectedValue({
			response: { status: 409, data: { error: 'slug_taken' } },
		})
		const wrapper = mountDialog()

		await wrapper.find('button[data-variant="primary"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe(
			'That slug is taken. Pick another one.',
		)
		expect(wrapper.emitted('copied')).toBeUndefined()
	})

	it('refuses a slug that is not kebab-case before sending', async () => {
		const wrapper = mountDialog()
		await wrapper.findAll('input')[1].setValue('Not A Slug')
		expect(wrapper.find('button[data-variant="primary"]').element.disabled).toBe(
			true,
		)
	})
})

describe('Copy app in the app detail actions', () => {
	const application = {
		'@self': { id: 'app-uuid' },
		id: 'app-uuid',
		slug: 'permit-tracker',
		name: 'Permit tracker',
		permissions: { owners: ['admin', 'makers'], editors: [], viewers: [] },
	}
	const router = { push: vi.fn().mockResolvedValue(undefined) }

	/**
	 * Mount the actions shallowly.
	 *
	 * @return {object} The wrapper.
	 */
	function mountActions() {
		return shallowMount(ApplicationDetailActions, {
			props: { object: application, objectId: 'app-uuid' },
			global: {
				mocks: {
					$router: router,
					$route: { name: 'VirtualAppDetail', params: {}, query: {} },
				},
				stubs: {
					CnActionButtons: {
						name: 'CnActionButtons',
						props: ['actions', 'inline', 'overflowLabel'],
						template: '<div />',
					},
				},
			},
		})
	}

	it('offers Copy app to an owner who is an administrator', () => {
		const wrapper = mountActions()
		const copy = wrapper.vm.actionDescriptors.find((a) => a.id === 'app-copy')
		expect(copy).toBeTruthy()
		copy.onSelect()
		expect(wrapper.vm.copyOpen).toBe(true)
	})

	it('does not offer it to an owner who is not an administrator, since the server would refuse', () => {
		groups = ['makers']
		const wrapper = mountActions()
		expect(wrapper.vm.actionDescriptors.map((a) => a.id)).not.toContain(
			'app-copy',
		)
	})

	it('opens the copy once it exists', () => {
		const wrapper = mountActions()
		wrapper.vm.onCopied({ uuid: 'new-uuid', slug: 'event-permits' })
		expect(router.push).toHaveBeenCalledWith({
			name: 'VirtualAppDetail',
			params: { objectId: 'new-uuid' },
		})
		expect(wrapper.vm.copyOpen).toBe(false)
	})
})
