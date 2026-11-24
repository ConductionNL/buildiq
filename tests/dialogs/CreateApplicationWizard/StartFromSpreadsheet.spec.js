/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for "Start from a spreadsheet" in the create-app wizard
 * (capability-requirements-backfill T01, REQ-BQCV-001): the option on step 1,
 * the file checks, the step's validity, and the `fromFile` block the wizard posts.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: vi.fn(() => Promise.reject(new Error('no provider in tests'))),
		post: vi.fn(),
	},
}))

vi.mock('../../../src/utils/iconCatalogues.js', () => ({
	buildIconCatalogues: () => [],
	ICON_SOURCES: [],
	resolveAppIcon: () => null,
}))

import axios from '@nextcloud/axios'
import CreateApplicationWizard from '../../../src/dialogs/CreateApplicationWizard.vue'
import StartFromSpreadsheet, {
	MAX_SPREADSHEET_BYTES,
	readAsBase64,
	spreadsheetError,
} from '../../../src/dialogs/CreateApplicationWizard/StartFromSpreadsheet.vue'
import Step1Basics from '../../../src/dialogs/CreateApplicationWizard/Step1Basics.vue'
import { clearCopilotHealthCache } from '../../../src/composables/useCopilot.js'

const CSV = 'name,address,status\nPermit 1,Main street 1,open\n'

/**
 * Mount a component, re-rendering with every payload patch it emits, as the wizard shell does.
 *
 * @param {object} component The component.
 * @param {object} payload The starting payload.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountWithPayload(component, payload = {}) {
	const wrapper = mount(component, {
		props: {
			payload: { name: 'Vergunningen', slug: 'vergunningen', description: '', ...payload },
			'onUpdate:payload': (partial) => wrapper.setProps({ payload: { ...wrapper.props('payload'), ...partial } }),
		},
		global: { stubs: { CnIconPicker: true, CopilotGenerateDialog: true } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Pick a file in the component's file input.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted component.
 * @param {File} file The file.
 * @return {Promise<void>}
 */
async function pick(wrapper, file) {
	await wrapper.vm.onPickFile(file)
	await flushPromises()
}

beforeEach(() => {
	clearCopilotHealthCache()
	axios.post.mockReset()
})

describe('spreadsheetError', () => {
	it('takes CSV, XLS and XLSX files up to 10 MB', () => {
		expect(spreadsheetError({ name: 'permits.csv', size: 10 })).toBeNull()
		expect(spreadsheetError({ name: 'Permits.XLSX', size: 10 })).toBeNull()
		expect(spreadsheetError({ name: 'permits.xls', size: MAX_SPREADSHEET_BYTES })).toBeNull()
	})

	it('refuses other files with a reason', () => {
		expect(spreadsheetError(null)).toBe('Choose a file to continue.')
		expect(spreadsheetError({ name: 'permits.pdf', size: 10 })).toBe('Choose a CSV, XLS or XLSX file.')
		expect(spreadsheetError({ name: 'csv', size: 10 })).toBe('Choose a CSV, XLS or XLSX file.')
		expect(spreadsheetError({ name: 'permits.csv', size: 0 })).toBe('The file is empty.')
		expect(spreadsheetError({ name: 'permits.csv', size: MAX_SPREADSHEET_BYTES + 1 })).toBe('The file is larger than 10 MB.')
	})
})

describe('readAsBase64', () => {
	it('gives the contents without the data-URL prefix', async () => {
		const content = await readAsBase64(new Blob([CSV], { type: 'text/csv' }))
		expect(atob(content)).toBe(CSV)
	})
})

describe('StartFromSpreadsheet', () => {
	it('turns the path on without a file and off again', async () => {
		const wrapper = await mountWithPayload(StartFromSpreadsheet)
		expect(wrapper.find('[data-testid="wizard-spreadsheet-file"]').exists()).toBe(false)

		await wrapper.find('[data-testid="wizard-spreadsheet-toggle"]').setValue(true)
		expect(wrapper.props('payload').fromFile).toEqual({ name: '', mime: '', content: '' })
		expect(wrapper.find('[data-testid="wizard-spreadsheet-file"]').attributes('accept')).toBe('.csv,.xls,.xlsx')

		await wrapper.find('[data-testid="wizard-spreadsheet-toggle"]').setValue(false)
		expect(wrapper.props('payload').fromFile).toBeNull()
	})

	it('puts a picked CSV on the payload as base64 and names it', async () => {
		const wrapper = await mountWithPayload(StartFromSpreadsheet, { fromFile: { name: '', mime: '', content: '' } })
		await pick(wrapper, new File([CSV], 'permits.csv', { type: 'text/csv' }))

		const fromFile = wrapper.props('payload').fromFile
		expect(fromFile.name).toBe('permits.csv')
		expect(fromFile.mime).toBe('text/csv')
		expect(atob(fromFile.content)).toBe(CSV)
		expect(wrapper.find('[data-testid="wizard-spreadsheet-chosen"]').exists()).toBe(true)
		expect(wrapper.vm.fileName).toBe('permits.csv')
	})

	it('shows why a file cannot be used and keeps no contents', async () => {
		const wrapper = await mountWithPayload(StartFromSpreadsheet, { fromFile: { name: '', mime: '', content: '' } })
		await pick(wrapper, new File(['x'], 'permits.pdf'))

		expect(wrapper.find('[data-testid="wizard-spreadsheet-error"]').text()).toBe('Choose a CSV, XLS or XLSX file.')
		expect(wrapper.props('payload').fromFile.content).toBe('')
	})

	it('cannot be turned on while "Start from a database" is on', async () => {
		const wrapper = await mountWithPayload(StartFromSpreadsheet, { fromDatabase: { register: 'permits-db', schemas: [] } })
		expect(wrapper.find('[data-testid="wizard-spreadsheet-toggle"]').attributes('disabled')).toBeDefined()
	})
})

describe('Step1Basics with a spreadsheet', () => {
	it('is valid only once a readable file is chosen', async () => {
		const wrapper = await mountWithPayload(Step1Basics)
		expect(wrapper.vm.isValid).toBe(true)

		await wrapper.find('[data-testid="wizard-spreadsheet-toggle"]').setValue(true)
		expect(wrapper.vm.isValid).toBe(false)

		await wrapper.setProps({ payload: { ...wrapper.props('payload'), fromFile: { name: 'permits.csv', mime: 'text/csv', content: btoa(CSV) } } })
		expect(wrapper.vm.isValid).toBe(true)
	})
})

describe('CreateApplicationWizard posts the file', () => {
	it('sends fromFile with the payload, and leaves it out when no file is chosen', async () => {
		axios.post.mockResolvedValue({ status: 201, data: { applicationUuid: 'app-1' } })
		const wrapper = mount(CreateApplicationWizard, {
			props: { show: true },
			global: { stubs: { CnWizardDialog: true } },
		})
		const stepData = {
			name: 'Vergunningen',
			slug: 'vergunningen',
			description: '',
			preset: 'single',
			versions: [],
			fromFile: { name: 'permits.csv', mime: 'text/csv', content: btoa(CSV) },
		}

		await wrapper.vm.onSubmit(stepData)
		expect(axios.post.mock.calls[0][1].fromFile).toEqual({ name: 'permits.csv', mime: 'text/csv', content: btoa(CSV) })

		await wrapper.vm.onSubmit({ ...stepData, fromFile: { name: '', mime: '', content: '' } })
		expect(axios.post.mock.calls[1][1]).not.toHaveProperty('fromFile')
	})
})
