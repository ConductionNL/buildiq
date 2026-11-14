import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/ImportDataWizard.vue`: the schema it is
 * opened on, the CSV sample read, Undo without a job or with a failing
 * rollback, and the error text it shows. The import client is stubbed.
 *
 * Spec: buildiq-data-import-wizard (tasks 2.1).
 */
import { describe, expect, it, vi } from 'vitest'

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

describe('ImportDataWizard edges', () => {
	it('lists no targets without schemas, and pre-selects only a schema it knows', () => {
		const bare = mount(ImportDataWizard, {
			propsData: { registerId: 'reg', importClient: makeClient() },
			stubs: baseStubs,
		})
		expect(bare.vm.schemaOptions).toEqual([])

		const known = mountWizard({ initialSchema: 'person' })
		expect(known.vm.selectedSchema.id).toBe('person')

		const unknown = mountWizard({ initialSchema: 'missing' })
		expect(unknown.vm.selectedSchema).toBeFalsy()
	})

	it('reads no sample without a file', () => {
		const wrapper = mountWizard()
		wrapper.vm.sampleRows = [['kept']]
		wrapper.vm.file = null

		wrapper.vm.readCsvSample()

		expect(wrapper.vm.sampleRows).toEqual([['kept']])
	})

	it('a failed sample read leaves no sample', () => {
		const original = globalThis.FileReader
		globalThis.FileReader = class {
			readAsText() {
				this.onerror()
			}
		}
		try {
			const wrapper = mountWizard()
			wrapper.vm.sampleRows = [['old']]
			wrapper.vm.file = new File(['a,b\n1,2'], 'data.csv', { type: 'text/csv' })

			wrapper.vm.readCsvSample()

			expect(wrapper.vm.sampleRows).toEqual([])
		} finally {
			globalThis.FileReader = original
		}
	})

	it('Undo does nothing without an import job', async () => {
		const client = makeClient()
		const wrapper = mountWizard({}, client)
		wrapper.vm.result = { importJobId: null, created: 2 }

		await wrapper.vm.undo()

		expect(client.rollbackImport).not.toHaveBeenCalled()
		expect(wrapper.emitted('imported')).toBeUndefined()
	})

	it('a failed Undo shows the server error and keeps the import', async () => {
		const client = makeClient({
			rollbackImport: vi.fn().mockRejectedValue({ response: { data: { error: 'Job expired' } } }),
		})
		const wrapper = mountWizard({}, client)
		wrapper.vm.result = { importJobId: 'job-1', created: 2 }

		await wrapper.vm.undo()

		expect(client.rollbackImport).toHaveBeenCalledWith('job-1')
		expect(wrapper.vm.error).toBe('Job expired')
		expect(wrapper.vm.result.importJobId).toBe('job-1')
		expect(wrapper.vm.undoing).toBe(false)
		expect(wrapper.emitted('imported')).toBeUndefined()
	})

	it('names an error by the server text, else its message, else a fixed text', () => {
		const wrapper = mountWizard()

		expect(wrapper.vm.readError({ response: { data: { error: 'Bad CSV' } } })).toBe('Bad CSV')
		expect(wrapper.vm.readError(new Error('Network down'))).toBe('Network down')
		expect(wrapper.vm.readError({})).toBe('Import failed.')
		expect(wrapper.vm.readError(null)).toBe('Import failed.')
	})
})
