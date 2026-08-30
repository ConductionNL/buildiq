/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/ImportDataWizard.vue`. The wizard
 * delegates ALL parsing/inference/writes to OpenRegister (stubbed here via an
 * injected import client) and only drives the stepped UI + renders OR's
 * summary. Covers: create-schema path (no schema param), existing-schema path
 * (schema param), rollback (Undo), version-scoped target list (only the passed
 * register's schemas are selectable — shared dataRegisters never appear), and
 * template download.
 *
 * Spec: buildiq-data-import-wizard (tasks 2.1).
 */
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

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
		props: ['inputLabel', 'options', 'disabled', 'placeholder'],
		template: '<div class="nc-select-stub" :data-input-label="inputLabel" />',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="nc-note-stub"><slot /></div>',
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		template: '<span class="nc-loading-stub" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'value', 'name', 'type'],
		template:
			'<label class="nc-radio-stub"><input type="radio" :value="value" @change="$emit(\'update:checked\', value)"><slot /></label>',
	},
	DownloadIcon: { name: 'DownloadIcon', template: '<span />' },
	UploadIcon: { name: 'UploadIcon', template: '<span />' },
}

const ImportDataWizard = (await import('../../src/dialogs/ImportDataWizard.vue'))
	.default

function makeClient(overrides = {}) {
	return {
		importFile: vi.fn().mockResolvedValue({
			importJobId: 'job-1',
			created: 3,
			updated: 1,
			skipped: 0,
			errors: [],
			errorsCsv: null,
			errorsCsvFilename: null,
		}),
		rollbackImport: vi.fn().mockResolvedValue({ deleted: 3 }),
		templateUrl: vi.fn().mockReturnValue('/tpl-url'),
		...overrides,
	}
}

function mountWizard(propsData = {}, importClient = makeClient()) {
	return mount(ImportDataWizard, {
		propsData: {
			registerId: 'openbuild-app-staging',
			schemas: [
				{ slug: 'permit', title: 'Permit' },
				{ slug: 'person', title: 'Person' },
			],
			importClient,
			...propsData,
		},
		stubs: baseStubs,
	})
}

describe('ImportDataWizard', () => {
	it('create-schema path imports via OpenRegister with NO schema param', async () => {
		const client = makeClient()
		const wrapper = mountWizard({}, client)
		await wrapper.setData({
			targetMode: 'create',
			file: new File(['a,b\n1,2\n'], 'people.csv'),
			step: 4,
		})

		await wrapper.vm.runImport()

		expect(client.importFile).toHaveBeenCalledTimes(1)
		const arg = client.importFile.mock.calls[0][0]
		expect(arg.registerId).toBe('openbuild-app-staging')
		expect(arg.schema).toBeUndefined() // create-from-file: OR infers the schema
		expect(arg.includeObjects).toBe(true)
		expect(wrapper.vm.step).toBe(5)
		expect(wrapper.emitted('imported')).toBeTruthy()
		expect(wrapper.text()).toContain('created')
	})

	it('existing-schema path passes the selected schema slug', async () => {
		const client = makeClient()
		const wrapper = mountWizard({}, client)
		await wrapper.setData({
			targetMode: 'existing',
			selectedSchema: { id: 'permit', label: 'Permit' },
			file: new File(['x'], 'rows.xlsx'),
			step: 4,
		})

		await wrapper.vm.runImport()

		expect(client.importFile.mock.calls[0][0].schema).toBe('permit')
	})

	it('Undo triggers OpenRegister rollback with the importJobId', async () => {
		const client = makeClient()
		const wrapper = mountWizard({}, client)
		await wrapper.setData({
			targetMode: 'create',
			file: new File(['x'], 'a.csv'),
			step: 4,
		})
		await wrapper.vm.runImport()

		await wrapper.vm.undo()

		expect(client.rollbackImport).toHaveBeenCalledWith('job-1')
		expect(wrapper.vm.result.importJobId).toBeNull()
	})

	it('target list = only the passed register schemas (shared dataRegisters excluded)', () => {
		const wrapper = mountWizard()
		const ids = wrapper.vm.schemaOptions.map((o) => o.id)
		expect(ids).toEqual(['permit', 'person'])
	})

	it('download-template delegates to the import client template URL', async () => {
		const client = makeClient()
		const wrapper = mountWizard({}, client)
		await wrapper.setData({ selectedSchema: { id: 'permit', label: 'Permit' } })

		wrapper.vm.downloadTemplate('csv')

		expect(client.templateUrl).toHaveBeenCalledWith({
			registerId: 'openbuild-app-staging',
			schema: 'permit',
			format: 'csv',
		})
	})

	it('surfaces an OpenRegister error (e.g. 403 manage-permission) without faking success', async () => {
		const client = makeClient({
			importFile: vi.fn().mockRejectedValue({
				response: {
					data: {
						error: 'User does not have permission to manage this register',
					},
				},
			}),
		})
		const wrapper = mountWizard({}, client)
		await wrapper.setData({
			targetMode: 'create',
			file: new File(['x'], 'a.csv'),
			step: 4,
		})

		await wrapper.vm.runImport()

		expect(wrapper.vm.step).toBe(4) // stayed on confirm; no fake summary
		expect(wrapper.vm.error).toContain('permission')
	})

	// -------------------------------------------------------------------
	// WCAG 2.2 AA 1.3.1 Info and Relationships.
	//
	// `sampleRows` is filled only by readCsvSample(), so row 0 is the CSV's
	// own header line. It used to render as ordinary <td> data, leaving the
	// sample table with no <th> at all — a screen reader could not tell a
	// user which column a cell belonged to.
	//
	// Asserted on the CELLS, not on the <thead> wrapper: an empty <thead>
	// would satisfy a container assertion while announcing nothing.
	// -------------------------------------------------------------------
	it('renders the CSV header line as scoped column headers, and not as a data row', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({
			step: 3,
			sampleRows: [
				['name', 'email'],
				['Ada', 'ada@example.org'],
				['Grace', 'grace@example.org'],
			],
		})

		const headerCells = wrapper.findAll('.ob-import-wizard__sample thead th')
		expect(headerCells).toHaveLength(2)
		expect(headerCells.at(0).text()).toBe('name')
		expect(headerCells.at(1).text()).toBe('email')
		expect(headerCells.at(0).attributes('scope')).toBe('col')
		expect(headerCells.at(1).attributes('scope')).toBe('col')

		// The header must not ALSO appear as a body row.
		const bodyRows = wrapper.findAll('.ob-import-wizard__sample tbody tr')
		expect(bodyRows).toHaveLength(2)
		expect(bodyRows.at(0).text()).toContain('Ada')
		expect(wrapper.find('.ob-import-wizard__sample tbody').text()).not.toContain(
			'email',
		)
	})
})
