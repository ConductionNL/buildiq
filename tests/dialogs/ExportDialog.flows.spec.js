/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: ExportDialog offers the app's flows checked by default and sends
 * only the chosen flows' ids, does not start a job without an id, and stays
 * open while an export is being submitted.
 *
 * @spec openspec/specs/openbuild-exporter/spec.md
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

describe('ExportDialog flows and closing', () => {
	beforeEach(() => {
		axiosPostMock.mockReset()
		axiosPostMock.mockResolvedValue({ data: { uuid: 'job-uuid' } })
	})

	it('lists the app flows, each included by default', () => {
		const wrapper = mountDialog({
			flows: [
				{ flow: 'flow-1', label: 'Send reminders' },
				{ flow: 'flow-2' },
			],
		})

		expect(wrapper.vm.flowChoices).toEqual([
			{ flow: 'flow-1', label: 'Send reminders', include: true },
			{ flow: 'flow-2', label: undefined, include: true },
		])
		expect(wrapper.find('[data-test="export-flow-flow-1"]').text()).toBe('Send reminders')
		expect(wrapper.find('[data-test="export-flow-flow-2"]').text()).toBe('flow-2')
	})

	it('sends only the chosen flows, by id', () => {
		const wrapper = mountDialog({
			flows: [
				{ flow: 'flow-1', label: 'Send reminders' },
				{ flow: 'flow-2', label: 'Archive' },
			],
		})
		wrapper.vm.flowChoices[1].include = false

		expect(wrapper.vm.flowPayload()).toEqual([{ flow: 'flow-1' }])
	})

	it('starts no job without an id, and starts the named one', async () => {
		const wrapper = mountDialog()

		wrapper.vm.startNow('')
		expect(axiosPostMock).not.toHaveBeenCalled()

		wrapper.vm.startNow('job 1')
		expect(axiosPostMock).toHaveBeenCalledWith('/apps/buildiq/api/exports/job%201/run')
	})

	it('a failing start is swallowed: cron still runs the job', async () => {
		axiosPostMock.mockRejectedValue(new Error('down'))
		const wrapper = mountDialog()

		expect(() => wrapper.vm.startNow('job-1')).not.toThrow()
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(axiosPostMock).toHaveBeenCalledWith('/apps/buildiq/api/exports/job-1/run')
		expect(wrapper.vm.errorMessage).toBeFalsy()
	})

	it('does not close while submitting', () => {
		const wrapper = mountDialog()

		wrapper.vm.submitting = true
		wrapper.vm.onClose()
		expect(wrapper.emitted('close')).toBeUndefined()

		wrapper.vm.submitting = false
		wrapper.vm.onClose()
		expect(wrapper.emitted('close')).toEqual([[]])
	})
})
