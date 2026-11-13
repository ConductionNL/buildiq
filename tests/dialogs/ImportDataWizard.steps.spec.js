/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the stepped flow of `src/dialogs/ImportDataWizard.vue`:
 * choosing a file, what the file panel says about it, moving between steps,
 * the preview columns, the CSV sample, the error report download and close.
 */
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'canClose', 'size'],
		template: '<div class="nc-dialog-stub"><slot /><div class="nc-dialog-actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		emits: ['click'],
		template: '<button class="btn" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcSelect: { name: 'NcSelect', props: ['options'], template: '<div class="nc-select-stub" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="nc-note-stub" :data-type="type"><slot /></div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', template: '<label><slot /></label>' },
	DownloadIcon: { name: 'DownloadIcon', template: '<span />' },
	UploadIcon: { name: 'UploadIcon', template: '<span />' },
}

const ImportDataWizard = (await import('../../src/dialogs/ImportDataWizard.vue')).default

const permit = { slug: 'permit', title: 'Permit', properties: { number: { type: 'string' }, issued: { type: 'date' }, note: null } }

/**
 * Mount the wizard with a stub import client.
 *
 * @param {object} props Extra props.
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountWizard(props = {}) {
	return mount(ImportDataWizard, {
		props: {
			registerId: 'app-staging',
			schemas: [permit, { slug: 'nested', title: 'Nested', schema: { properties: { code: { type: 'integer' } } } }],
			importClient: { importFile: vi.fn(), rollbackImport: vi.fn(), templateUrl: vi.fn().mockReturnValue('') },
			...props,
		},
		global: { stubs },
	})
}

/**
 * The button with the given label.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The wizard.
 * @param {string} label Its text.
 * @return {import('@vue/test-utils').DOMWrapper|undefined}
 */
function button(wrapper, label) {
	return wrapper.findAll('button.btn').find((b) => b.text() === label)
}

/**
 * A File of the given size and name.
 *
 * @param {string} name The file name.
 * @param {number} size Its size in bytes.
 * @param {string} content Its text.
 * @return {File}
 */
function fileOf(name, size, content = '') {
	const file = new File([content], name)
	Object.defineProperty(file, 'size', { value: size })
	return file
}

/**
 * Choose a file in the step-2 input as the browser would.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The wizard at step 2.
 * @param {File} file The file.
 * @return {Promise<void>}
 */
async function choose(wrapper, file) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('ImportDataWizard steps', () => {
	it('cannot leave step 1 in existing mode without a schema, but can in create mode', async () => {
		const wrapper = mountWizard()

		expect(button(wrapper, 'Next').attributes('disabled')).toBeDefined()
		wrapper.vm.next()
		expect(wrapper.vm.step).toBe(1)

		await wrapper.setData({ targetMode: 'create' })
		await button(wrapper, 'Next').trigger('click')
		expect(wrapper.vm.step).toBe(2)
		expect(wrapper.find('input[type="file"]').exists()).toBe(true)
	})

	it('back steps down but never below the first step', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2 })

		await button(wrapper, 'Back').trigger('click')
		expect(wrapper.vm.step).toBe(1)
		wrapper.vm.back()
		expect(wrapper.vm.step).toBe(1)
	})

	it('the select button opens the hidden file input', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2 })
		const click = vi.spyOn(wrapper.find('input[type="file"]').element, 'click').mockImplementation(() => {})

		await button(wrapper, 'Select xlsx, xls, csv or json').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
	})

	it('a chosen CSV is named, typed and sized, and lets the wizard advance', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, targetMode: 'create', error: 'old', sampleRows: [['x']] })

		await choose(wrapper, fileOf('people.csv', 2048))

		expect(wrapper.find('.ob-import-wizard__file-meta strong').text()).toBe('people.csv')
		expect(wrapper.find('.ob-import-wizard__file-meta span').text()).toBe('CSV file · 2 KB')
		expect(wrapper.vm.error).toBe('')
		expect(wrapper.vm.sampleRows).toEqual([])
		expect(wrapper.find('.nc-note-stub').exists()).toBe(false)
		expect(button(wrapper, 'Choose a different file')).toBeDefined()
		expect(button(wrapper, 'Next').attributes('disabled')).toBeUndefined()
	})

	it.each([
		['book.xlsx', 500, 'Excel spreadsheet · 500 B'],
		['dump.json', 3 * 1024 * 1024, 'JSON export · 3.0 MB'],
		['notes.txt', 1023, 'Unsupported file type · 1023 B'],
	])('describes %s as "%s"', async (name, size, described) => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, targetMode: 'create' })

		await choose(wrapper, fileOf(name, size))

		expect(wrapper.find('.ob-import-wizard__file-meta span').text()).toBe(described)
	})

	it('an unsupported file keeps the wizard on step 2', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, targetMode: 'create' })

		await choose(wrapper, fileOf('notes.txt', 10))

		expect(button(wrapper, 'Next').attributes('disabled')).toBeDefined()
		wrapper.vm.next()
		expect(wrapper.vm.step).toBe(2)
	})

	it('a file over 5 MB shows the large-file warning', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, targetMode: 'create' })

		await choose(wrapper, fileOf('big.xlsx', 5 * 1024 * 1024 + 1))

		expect(wrapper.find('.nc-note-stub').attributes('data-type')).toBe('warning')
		expect(wrapper.find('.nc-note-stub').text()).toContain('Large files import synchronously')
	})

	it('choosing nothing clears the file', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2 })
		wrapper.vm.file = fileOf('a.csv', 1)

		wrapper.vm.onFileChosen({ target: { files: [] } })

		expect(wrapper.vm.file).toBeNull()
	})

	it('the preview of an existing schema lists its fields and their types', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, selectedSchema: wrapper.vm.schemaOptions[0] })
		wrapper.vm.file = fileOf('p.xlsx', 10)
		await wrapper.vm.$nextTick()

		await button(wrapper, 'Next').trigger('click')

		expect(wrapper.vm.step).toBe(3)
		expect(wrapper.vm.previewColumns).toEqual([
			{ name: 'number', type: 'string' },
			{ name: 'issued', type: 'date' },
			{ name: 'note', type: '' },
		])
		expect(wrapper.findAll('.ob-import-wizard__column-name').map((c) => c.text())).toEqual(['number', 'issued', 'note'])
		expect(wrapper.findAll('.ob-import-wizard__column-type').map((c) => c.text())).toEqual(['string', 'date'])
	})

	it('the preview reads fields nested under schema.properties', async () => {
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, selectedSchema: wrapper.vm.schemaOptions[1] })
		wrapper.vm.file = fileOf('p.json', 10)

		wrapper.vm.next()

		expect(wrapper.vm.previewColumns).toEqual([{ name: 'code', type: 'integer' }])
	})

	it('creating from a CSV reads the header and at most five rows as the sample', async () => {
		const csv = 'name, email\r\nAda,a@x\n\nB,b@x\nC,c@x\nD,d@x\nE,e@x\nF,f@x\n'
		const wrapper = mountWizard()
		await wrapper.setData({ step: 2, targetMode: 'create' })
		wrapper.vm.file = new File([csv], 'people.csv')

		wrapper.vm.next()
		await vi.waitFor(() => expect(wrapper.vm.sampleRows.length).toBeGreaterThan(0))

		expect(wrapper.vm.sampleRows).toEqual([['name', ' email'], ['Ada', 'a@x'], ['B', 'b@x'], ['C', 'c@x'], ['D', 'd@x'], ['E', 'e@x']])
		expect(wrapper.vm.previewColumns).toEqual([{ name: 'name', type: '' }, { name: 'email', type: '' }])
	})

	it('downloads the error report as a base64 CSV with its file name', async () => {
		const wrapper = mountWizard()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
			expect(this.getAttribute('href')).toBe('data:text/csv;base64,YSxi')
			expect(this.download).toBe('errors.csv')
			expect(document.body.contains(this)).toBe(true)
		})

		wrapper.vm.result = { errorsCsv: 'YSxi', errorsCsvFilename: 'errors.csv' }
		wrapper.vm.downloadErrorCsv()
		wrapper.vm.result = { errorsCsv: 'YSxi' }
		click.mockImplementation(function () {
			expect(this.download).toBe('import_errors.csv')
		})
		wrapper.vm.downloadErrorCsv()

		expect(click).toHaveBeenCalledTimes(2)
		expect(document.body.querySelector('a[download]')).toBeNull()
	})

	it('without an error report nothing is downloaded', async () => {
		const wrapper = mountWizard()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')

		wrapper.vm.result = { errorsCsv: null }
		wrapper.vm.downloadErrorCsv()

		expect(click).not.toHaveBeenCalled()
	})

	it('done on the summary closes the wizard', async () => {
		const wrapper = mountWizard()
		wrapper.vm.step = 5
		wrapper.vm.result = { importJobId: null, created: 1, updated: 0, skipped: 0, errors: [] }
		await wrapper.vm.$nextTick()

		await button(wrapper, 'Done').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})
