/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: pins UseLibraryFormDialog's `canConfirm` gate and `confirm()` —
 * the payload it hands `useLibraryForm`, the events on success, and the
 * error message it picks on every failure shape.
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
 */
import { shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { useLibraryForm, planLibraryFormUse, saveRegistrationForm } = vi.hoisted(() => ({
	useLibraryForm: vi.fn(),
	planLibraryFormUse: vi.fn(() => ({ missing: {}, missingNames: [] })),
	saveRegistrationForm: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }))
vi.mock('../../src/services/formLibrary.js', () => ({
	fetchRegisterSchemas: vi.fn(async () => []),
	planLibraryFormUse,
	useLibraryForm,
}))
vi.mock('../../src/services/formCapture.js', () => ({ boundProperties: () => ['naam'] }))
vi.mock('../../src/services/registrationForms.js', () => ({ saveRegistrationForm }))

import UseLibraryFormDialog from '../../src/dialogs/UseLibraryFormDialog.vue'

const template = { slug: 'tpl', kind: 'registration-form', form: { fields: [] } }

/**
 * Mount the dialog closed with a version and a schema chosen.
 *
 * @param {object} state Data overrides.
 * @return {object} The wrapper.
 */
function ready(state = {}) {
	const wrapper = shallowMount(UseLibraryFormDialog, {
		propsData: { open: false, template },
		mocks: { t: (app, s) => s },
	})
	wrapper.setData({
		appSlug: 'subsidies',
		version: { slug: 'v1', register: 'reg-1' },
		schemas: [{ slug: 'aanvraag', title: 'Aanvraag' }],
		schemaSlug: 'aanvraag',
		typeProperty: ' soort ',
		typeValue: ' energie ',
		...state,
	})
	return wrapper
}

describe('UseLibraryFormDialog canConfirm', () => {
	beforeEach(() => {
		planLibraryFormUse.mockReturnValue({ missing: {}, missingNames: [] })
	})

	it.each([
		[{}, true],
		[{ working: true }, false],
		[{ version: null }, false],
		[{ schemaSlug: '' }, false],
		[{ target: 'registration-form' }, true],
		[{ target: 'registration-form', typeProperty: '  ' }, false],
		[{ target: 'registration-form', typeValue: '' }, false],
		[{ target: 'form-page', typeValue: '' }, true],
	])('with %j is %s', async (state, expected) => {
		const wrapper = ready(state)
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(expected)
	})

	it('needs the missing properties to be added', async () => {
		planLibraryFormUse.mockReturnValue({ missing: { a: {} }, missingNames: ['a'] })
		const wrapper = ready()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canConfirm).toBe(false)
		await wrapper.setData({ addProperties: true })
		expect(wrapper.vm.canConfirm).toBe(true)
	})

	it('is false without a template', async () => {
		const wrapper = ready()
		await wrapper.setProps({ template: null })
		expect(wrapper.vm.canConfirm).toBe(false)
	})
})

describe('UseLibraryFormDialog confirm', () => {
	beforeEach(() => {
		useLibraryForm.mockReset()
		planLibraryFormUse.mockReturnValue({ missing: {}, missingNames: [] })
	})

	it('does nothing when it cannot confirm', async () => {
		const wrapper = ready({ version: null })
		await wrapper.vm.confirm()
		expect(useLibraryForm).not.toHaveBeenCalled()
	})

	it('hands the trimmed choice to useLibraryForm and emits used + close', async () => {
		useLibraryForm.mockResolvedValue({ added: ['x'], page: { id: 'p' } })
		const wrapper = ready({ addProperties: true, error: 'old' })
		await wrapper.vm.$nextTick()
		await wrapper.vm.confirm()
		expect(useLibraryForm).toHaveBeenCalledWith({
			template,
			target: 'form-page',
			schema: { slug: 'aanvraag', title: 'Aanvraag' },
			register: 'reg-1',
			addProperties: true,
			version: { slug: 'v1', register: 'reg-1' },
			registration: { typeProperty: 'soort', typeValue: 'energie', targetApp: 'subsidies' },
			saveRegistrationForm,
		})
		expect(wrapper.emitted('used')[0][0]).toEqual({
			added: ['x'],
			page: { id: 'p' },
			appSlug: 'subsidies',
			bound: ['naam'],
		})
		expect(wrapper.emitted('update:open')[0]).toEqual([false])
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.working).toBe(false)
	})

	const failure = (message, response) => {
		const e = new Error(message)
		if (response !== undefined) e.response = response
		return e
	}

	it.each([
		['a local error message', failure('Schema save failed'), 'Schema save failed'],
		['missing-properties falls through to the default', failure('missing-properties'), 'Adding the form to the app failed.'],
		['an empty message falls through to the default', failure(''), 'Adding the form to the app failed.'],
		['response detail', failure('Request failed', { data: { detail: 'D', message: 'M', error: 'E' } }), 'D'],
		['response message', failure('Request failed', { data: { message: 'M', error: 'E' } }), 'M'],
		['response error', failure('Request failed', { data: { error: 'E' } }), 'E'],
		['an empty response body', failure('Request failed', { data: {} }), 'Adding the form to the app failed.'],
		['a response without data', failure('Request failed', {}), 'Adding the form to the app failed.'],
		['a null response keeps the message', failure('Offline', null), 'Offline'],
		['a thrown null', null, 'Adding the form to the app failed.'],
		['a thrown string', 'boom', 'Adding the form to the app failed.'],
	])('shows %s', async (label, thrown, expected) => {
		useLibraryForm.mockImplementation(async () => {
			throw thrown
		})
		const wrapper = ready()
		await wrapper.vm.$nextTick()
		await wrapper.vm.confirm()
		expect(wrapper.vm.error).toBe(expected)
		expect(wrapper.vm.working).toBe(false)
		expect(wrapper.emitted('used')).toBeUndefined()
	})
})

describe('UseLibraryFormDialog previewFields', () => {
	it('lists each field by its first truthy label, title, name or key', async () => {
		const wrapper = ready()
		await wrapper.setProps({
			template: {
				...template,
				form: {
					fields: [
						{ label: 'L', title: 'T', name: 'n', key: 'k' },
						{ label: '', title: 'T2', name: 'n2' },
						{ name: 'n3', key: 'k3' },
						{ key: 'k4' },
						{ label: 0, key: '' },
						null,
						{},
					],
				},
			},
		})
		expect(wrapper.vm.previewFields).toEqual(['L', 'T2', 'n3', 'k4'])
	})

	it('is empty without form fields', async () => {
		const wrapper = ready()
		await wrapper.setProps({ template: { ...template, form: null } })
		expect(wrapper.vm.previewFields).toEqual([])
	})
})
