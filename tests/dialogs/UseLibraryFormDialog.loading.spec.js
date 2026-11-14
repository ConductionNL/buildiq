/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: UseLibraryFormDialog reloads its apps each time it opens, clears
 * the dependent pickers when an app or version is cleared, says which read
 * failed, and does not close while it is adding the form (REQ-BQGL-004).
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, saveRegistrationForm } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() },
	saveRegistrationForm: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('../../src/services/registrationForms.js', () => ({ saveRegistrationForm }))

import UseLibraryFormDialog from '../../src/dialogs/UseLibraryFormDialog.vue'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: [
			'modelValue',
			'options',
			'inputLabel',
			'disabled',
			'loading',
			'clearable',
		],
		emits: ['update:modelValue'],
		template: '<div class="select" :data-label="inputLabel" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'value', 'name'],
		emits: ['update:modelValue'],
		template: '<label><slot /></label>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled || false" @click="$emit(\'click\')"><slot /></button>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const template = {
	slug: 'aanvraag-energiesubsidie',
	name: 'Aanvraag energiesubsidie',
	kind: 'registration-form',
	category: 'citizen-engagement',
	sourceSchema: 'aanvraag',
	form: {
		fields: [
			{ name: 'naam', label: 'Naam' },
			{ name: 'verbruikKwh', label: 'Verbruik' },
		],
		confirmationText: 'Bedankt.',
	},
	schemaFragment: { naam: { type: 'string' }, verbruikKwh: { type: 'number' } },
}

/**
 * Mount the dialog closed or open.
 *
 * @param {boolean} open Whether it starts open.
 * @return {Promise<object>}
 */
async function mountDialog(open = true) {
	const wrapper = mount(UseLibraryFormDialog, {
		props: { open, template },
		global: { stubs },
	})
	await flush()
	return wrapper
}

describe('UseLibraryFormDialog loading', () => {
	beforeEach(() => {
		axiosMock.get.mockReset().mockImplementation((url) => {
			if (url.includes('built-app')) {
				return Promise.resolve({ data: { results: [{ slug: 'subsidies', name: 'Subsidies' }] } })
			}
			if (url.endsWith('/versions')) {
				return Promise.resolve({ data: [{ slug: 'v1', name: 'v1' }] })
			}
			return Promise.reject(new Error('unexpected ' + url))
		})
	})

	it('reads the apps again each time it opens, with the earlier picks cleared', async () => {
		const wrapper = await mountDialog(false)
		const before = axiosMock.get.mock.calls.length
		wrapper.vm.appSlug = 'stale'
		wrapper.vm.error = 'old error'

		await wrapper.setProps({ open: true })
		await flush()

		const appCalls = axiosMock.get.mock.calls.slice(before).filter(([url]) => url.includes('built-app'))
		expect(appCalls).toHaveLength(1)
		expect(appCalls[0][1]).toEqual({ params: { _limit: 200 } })
		expect(wrapper.vm.appSlug).toBe('')
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.apps).toEqual([{ slug: 'subsidies', name: 'Subsidies' }])
	})

	it('says the apps could not be read when that read fails', async () => {
		axiosMock.get.mockImplementation(() => Promise.reject(new Error('down')))
		const wrapper = await mountDialog()

		expect(wrapper.vm.apps).toEqual([])
		expect(wrapper.vm.error).toBe('The apps could not be read.')
		expect(wrapper.vm.loadingApps).toBe(false)
		expect(wrapper.find('[role="alert"]').text()).toBe('The apps could not be read.')
	})

	it('clearing the app clears the versions and reads nothing', async () => {
		const wrapper = await mountDialog()
		await wrapper.vm.onApp({ id: 'subsidies' })
		expect(wrapper.vm.versions).toEqual([{ slug: 'v1', name: 'v1' }])
		const before = axiosMock.get.mock.calls.length

		await wrapper.vm.onApp(null)

		expect(wrapper.vm.appSlug).toBe('')
		expect(wrapper.vm.versions).toEqual([])
		expect(axiosMock.get.mock.calls.length).toBe(before)
	})

	it('says the versions could not be read when that read fails', async () => {
		const wrapper = await mountDialog()
		axiosMock.get.mockImplementation(() => Promise.reject(new Error('down')))

		await wrapper.vm.onApp({ id: 'subsidies' })

		expect(wrapper.vm.versions).toEqual([])
		expect(wrapper.vm.error).toBe('The versions of this app could not be read.')
	})

	it('clearing the version reads nothing; a failed version read says so', async () => {
		const wrapper = await mountDialog()
		await wrapper.vm.onApp({ id: 'subsidies' })
		const before = axiosMock.get.mock.calls.length

		await wrapper.vm.onVersion(null)
		expect(wrapper.vm.version).toBeNull()
		expect(axiosMock.get.mock.calls.length).toBe(before)

		await wrapper.vm.onVersion({ id: 'v9' })
		expect(axiosMock.get).toHaveBeenLastCalledWith('/apps/buildiq/api/applications/subsidies/versions/v9')
		expect(wrapper.vm.version).toBeNull()
		expect(wrapper.vm.error).toBe('This version could not be read.')
	})

	it('closes on request, but not while the form is being added', async () => {
		const wrapper = await mountDialog()

		wrapper.vm.working = true
		wrapper.vm.onClose()
		expect(wrapper.emitted('update:open')).toBeUndefined()

		wrapper.vm.working = false
		wrapper.vm.onClose()
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
