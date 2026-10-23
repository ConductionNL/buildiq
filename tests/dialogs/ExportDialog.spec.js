/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/ExportDialog.vue`'s per-binding
 * `includeData` toggle (data-registers-runtime task 4.4).
 *
 * Covers:
 *   - no Data registers section when the Application has no bindings
 *   - one toggle rendered per binding, labelled `binding.label ?? binding.register`
 *   - every toggle defaults unchecked (schema-defs-only)
 *   - submit payload's dataRegisters mirrors the bindings 1:1, each
 *     carrying the resolved includeData flag
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosPostMock = vi.fn()
const axiosGetMock = vi.fn(() => Promise.reject(new Error('not mocked')))
vi.mock('@nextcloud/axios', () => ({
	default: {
		post: (...args) => axiosPostMock(...args),
		get: (...args) => axiosGetMock(...args),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const baseStubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'canClose', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><div class="nc-dialog-actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template:
			'<button :disabled="disabled" :data-type="type" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'options', 'disabled'],
		template: '<div class="nc-select-stub" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'disabled'],
		template:
			'<label class="nc-checkbox-stub"><input type="checkbox" :checked="checked" :disabled="disabled" @change="$emit(\'update:checked\', $event.target.checked)"><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'disabled', 'type', 'autocomplete'],
		template:
			'<input class="nc-textfield-stub" :data-label="label" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
	},
}

const ExportDialog = (await import('../../src/dialogs/ExportDialog.vue')).default

function mountDialog(propsData = {}) {
	return mount(ExportDialog, {
		propsData: { applicationSlug: 'spectr-app', ...propsData },
		stubs: baseStubs,
	})
}

describe('ExportDialog — Data registers includeData toggle (data-registers-runtime task 4.4)', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
		axiosPostMock.mockResolvedValue({ data: { uuid: 'job-uuid' } })
	})

	it('renders no Data registers section when the Application has no bindings', () => {
		const wrapper = mountDialog({ dataRegisters: [] })
		expect(wrapper.find('.export-dialog__section-title').exists()).toBe(false)
		expect(wrapper.findAll('.nc-checkbox-stub')).toHaveLength(1) // only "Include seed data"
	})

	it('renders one toggle per binding, choice carries binding.label ?? binding.register for the template to display', () => {
		const wrapper = mountDialog({
			dataRegisters: [
				{ register: 'spectr', label: 'Spectr market intelligence data' },
				{ register: 'bag-adressen' },
			],
		})
		expect(wrapper.find('.export-dialog__section-title').exists()).toBe(true)
		// Template renders `choice.label || choice.register` — assert the
		// underlying data each toggle is bound to (DOM text assertion is not
		// meaningful here: the test-harness t() stub does not interpolate
		// {placeholder} tokens, see tests/vitest/setup.js).
		expect(wrapper.vm.dataRegisterChoices).toEqual([
			{
				register: 'spectr',
				label: 'Spectr market intelligence data',
				includeData: false,
			},
			{ register: 'bag-adressen', label: undefined, includeData: false },
		])
		// One toggle per binding, plus the pre-existing "Include seed data" switch.
		expect(wrapper.findAll('.nc-checkbox-stub')).toHaveLength(3)
	})

	it('every per-binding toggle defaults to unchecked (schema-defs-only)', () => {
		const wrapper = mountDialog({
			dataRegisters: [{ register: 'spectr' }, { register: 'bag-adressen' }],
		})
		expect(
			wrapper.vm.dataRegisterChoices.every((c) => c.includeData === false),
		).toBe(true)
	})

	it('submit payload mirrors the bindings 1:1 with the resolved includeData flags', async () => {
		const wrapper = mountDialog({
			dataRegisters: [
				{ register: 'spectr', label: 'Spectr market intelligence data' },
				{ register: 'bag-adressen' },
			],
		})
		// Toggle only the first binding's includeData on.
		wrapper.vm.dataRegisterChoices[0].includeData = true

		await wrapper.vm.submit()

		// The submit, then the call that starts the export right away.
		expect(axiosPostMock).toHaveBeenCalledTimes(2)
		const [, payload] = axiosPostMock.mock.calls[0]
		expect(payload.dataRegisters).toEqual([
			{ register: 'spectr', includeData: true },
			{ register: 'bag-adressen', includeData: false },
		])
	})

	it('submit payload has an empty dataRegisters array when the Application has no bindings', async () => {
		const wrapper = mountDialog({ dataRegisters: [] })
		await wrapper.vm.submit()
		const [, payload] = axiosPostMock.mock.calls[0]
		expect(payload.dataRegisters).toEqual([])
	})
})

describe('ExportDialog — Own server target (apps-standalone-deploy, board BqExporterenZelfstandig)', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
		axiosPostMock.mockResolvedValue({ data: { uuid: 'job-uuid' } })
		axiosGetMock.mockReset()
		axiosGetMock.mockImplementation((url) => (url.endsWith('/api/exports/server-requirements')
			? Promise.resolve({ data: { nextcloud: '32', openregister: '1.14.2', php: '8.3', database: 'PostgreSQL 16' } })
			: Promise.reject(new Error('not mocked'))))
	})

	it('offers Own server as a target', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.targetOptions.map((o) => o.value)).toEqual(['zip', 'server', 'github'])
	})

	it('shows what the server needs only for the Own server target, from the server', async () => {
		const wrapper = mountDialog()
		await new Promise((resolve) => setTimeout(resolve))
		expect(wrapper.find('[data-test="export-server-section"]').exists()).toBe(false)

		wrapper.vm.form.target = { label: 'Own server', value: 'server' }
		await wrapper.vm.$nextTick()

		const section = wrapper.find('[data-test="export-server-section"]')
		expect(section.exists()).toBe(true)
		expect(section.findAll('tbody tr')).toHaveLength(4)
		expect(wrapper.vm.requirementRows.map((r) => [r.key, r.version])).toEqual([
			['nextcloud', '{version} or newer'],
			['openregister', '{version} or newer'],
			['php', '{version} or newer'],
			['database', 'PostgreSQL 16'],
		])
		expect(wrapper.vm.serverRequirements.openregister).toBe('1.14.2')
		expect(section.find('[data-test="export-server-compose"]').exists()).toBe(true)
	})

	it('sends the server bundle choice with the Own server target only', async () => {
		const wrapper = mountDialog()
		wrapper.vm.form.target = { label: 'Own server', value: 'server' }
		await wrapper.vm.submit()
		let [, payload] = axiosPostMock.mock.calls[0]
		expect(payload.target).toBe('server')
		expect(payload.includeServerBundle).toBe(true)

		axiosPostMock.mockClear()
		wrapper.vm.form.target = { label: 'ZIP download', value: 'zip' }
		await wrapper.vm.submit()
		;[, payload] = axiosPostMock.mock.calls[0]
		expect(payload).not.toHaveProperty('includeServerBundle')
	})
})
