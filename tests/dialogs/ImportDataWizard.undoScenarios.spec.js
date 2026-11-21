import { mount } from '@vue/test-utils'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two scenarios of REQ-BQCV-022: a maker undoes an import from its
 * summary through OpenRegister's import rollback, using the import job the
 * summary reports; a failed rollback shows its error and the import stays.
 * Which records the rollback removes (only those the job created) is
 * OpenRegister's rollback, reached here through `useDataImport`.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-undoes-an-import-from-its-summary-req-bqcv-022
 */
import { describe, expect, it, vi } from 'vitest'
import { useDataImport } from '../../src/composables/useDataImport.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		template: '<div><slot /><div class="actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcSelect: { name: 'NcSelect', template: '<div />' },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' },
	DownloadIcon: { name: 'DownloadIcon', template: '<span />' },
	UploadIcon: { name: 'UploadIcon', template: '<span />' },
}

const ImportDataWizard = (await import('../../src/dialogs/ImportDataWizard.vue')).default

/**
 * A wizard that imported 200 rows into `request`, showing its summary.
 *
 * @param {Function} rollbackImport The stubbed rollback.
 * @return {Promise<object>} The mounted wrapper.
 */
async function summaryOf200Rows(rollbackImport) {
	const importClient = {
		importFile: vi.fn().mockResolvedValue({
			importJobId: 'job-200',
			created: 200,
			updated: 0,
			skipped: 0,
			errors: [],
			errorsCsv: null,
			errorsCsvFilename: null,
		}),
		rollbackImport,
		templateUrl: vi.fn().mockReturnValue(''),
	}
	const wrapper = mount(ImportDataWizard, {
		propsData: {
			registerId: 'app-register',
			schemas: [{ slug: 'request', title: 'Request' }],
			initialSchema: 'request',
			importClient,
		},
		stubs,
	})
	wrapper.vm.file = new File(['a\n1'], 'rows.csv', { type: 'text/csv' })
	await wrapper.vm.runImport()
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * The summary's Undo button.
 *
 * @param {object} wrapper The mounted wizard.
 * @return {object|undefined} The button wrapper.
 */
function undoButton(wrapper) {
	return wrapper.findAll('button').find((b) => b.text() === 'Undo import')
}

describe('REQ-BQCV-022: a maker undoes an import from its summary', () => {
	it('Undo rolls the import back by its job, and the summary says it was undone', async () => {
		const rollbackImport = vi.fn().mockResolvedValue({ deleted: 200 })
		const wrapper = await summaryOf200Rows(rollbackImport)
		expect(wrapper.text()).toContain('Import complete')
		expect(wrapper.find('.ob-import-wizard__count-n').text()).toBe('200')

		await undoButton(wrapper).trigger('click')
		await vi.waitFor(() => expect(wrapper.vm.undoing).toBe(false))
		await wrapper.vm.$nextTick()

		expect(rollbackImport).toHaveBeenCalledWith('job-200')
		expect(wrapper.text()).toContain('Import undone')
		expect(wrapper.find('[data-testid="import-undone"]').text()).toBe(
			'The import was undone. The records it created have been removed.',
		)
		expect(wrapper.find('.ob-import-wizard__count-n').text()).toBe('0')
		expect(undoButton(wrapper)).toBeUndefined()
		expect(wrapper.emitted('imported').at(-1)).toEqual([{ rolledBack: true }])
	})

	it('the rollback goes to OpenRegister\'s import rollback with only the job id', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: { deleted: 200 } }) }

		const report = await useDataImport({ client }).rollbackImport('job-200')

		expect(client.post).toHaveBeenCalledWith(
			'/apps/openregister/api/registers/import/rollback',
			{ importJobId: 'job-200' },
			{ headers: { 'OCS-APIREQUEST': 'true' } },
		)
		expect(report).toEqual({ deleted: 200 })
	})

	it('a failed rollback shows the error and the imported records stay', async () => {
		const rollbackImport = vi.fn().mockRejectedValue({ response: { data: { error: 'Rollback failed' } } })
		const wrapper = await summaryOf200Rows(rollbackImport)

		await undoButton(wrapper).trigger('click')
		await vi.waitFor(() => expect(wrapper.vm.undoing).toBe(false))
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Rollback failed')
		expect(wrapper.text()).toContain('Import complete')
		expect(wrapper.text()).not.toContain('Import undone')
		expect(wrapper.find('.ob-import-wizard__count-n').text()).toBe('200')
		expect(undoButton(wrapper)).toBeDefined()
		expect(wrapper.emitted('imported')).toHaveLength(1)
	})
})
