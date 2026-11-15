/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/dialogs/ImportAppDialog.vue` — importing an app
 * archive exported from another buildiq.
 *
 * Pinned down: picking an archive asks for a summary and creates nothing; the
 * summary's slug is the default; "Import records" is offered only when the
 * archive holds records and is off by default; the import posts the archive,
 * slug and records choice; a taken slug asks for another; a refused package
 * shows the parser's code and the file at fault.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { post: (...args) => post(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => '/index.php' + url }))

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'noClose'],
		template: '<div class="nc-dialog-stub"><slot /><div class="nc-dialog-actions"><slot name="actions" /></div></div>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" :data-variant="variant" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label class="nc-checkbox-stub"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'disabled', 'helperText'],
		emits: ['update:modelValue'],
		template: '<input class="nc-text-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="nc-note-stub"><slot /></div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
}

// The suite's shared t()/n() stubs return the key untouched; this spec reads the
// rendered text, so it fills in `{vars}` and `%n` the way Nextcloud does.
const fill = (text, vars = {}) => Object.entries(vars).reduce((out, [key, value]) => out.replaceAll('{' + key + '}', String(value)), text)
const tFill = (_app, text, vars) => fill(text, vars)
const nFill = (_app, one, many, count, vars) => fill((count === 1 ? one : many).replace('%n', String(count)), vars)
globalThis.t = tFill
globalThis.n = nFill
const mocks = { t: tFill, n: nFill }

const { default: ImportAppDialog, importErrorMessage } = await import('../../src/dialogs/ImportAppDialog.vue')

const archive = new File(['zip'], 'vergunningen-1.2.0.zip', { type: 'application/zip' })

/**
 * Mount the dialog closed, then open it, so it starts from a clean state.
 *
 * @return {Promise<object>} The wrapper.
 */
async function openDialog() {
	const wrapper = mount(ImportAppDialog, { props: { open: false }, global: { stubs, mocks } })
	await wrapper.setProps({ open: true })
	return wrapper
}

/**
 * Pick the archive in the file input.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<void>}
 */
async function pick(wrapper) {
	const input = wrapper.find('[data-testid="import-app-file"]')
	Object.defineProperty(input.element, 'files', { value: [archive], configurable: true })
	await input.trigger('change')
	await flushPromises()
}

/**
 * The form fields of the n-th post, as an object.
 *
 * @param {number} call The call index.
 * @return {object} The fields.
 */
function fields(call) {
	return Object.fromEntries(post.mock.calls[call][1].entries())
}

const summary = { name: 'Vergunningen', slug: 'vergunningen', pages: 3, schemas: 2, records: 40 }

beforeEach(() => {
	post.mockReset()
})

describe('ImportAppDialog', () => {
	it('summarises a picked archive without importing it', async () => {
		post.mockResolvedValueOnce({ data: summary })
		const wrapper = await openDialog()

		expect(wrapper.find('[data-testid="import-app-submit"]').attributes('disabled')).toBeDefined()
		await pick(wrapper)

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toBe('/index.php/apps/buildiq/api/applications/import/preview')
		expect(fields(0).archive.name).toBe('vergunningen-1.2.0.zip')
		const text = wrapper.find('[data-testid="import-app-summary"]').text()
		expect(text).toContain('Vergunningen')
		expect(text).toContain('3 pages')
		expect(text).toContain('2 schemas')
		expect(text).toContain('40 records')
		expect(wrapper.find('.nc-text-stub').element.value).toBe('vergunningen')
		expect(wrapper.emitted('imported')).toBeUndefined()
	})

	it('offers "Import records" only when the archive holds records, off by default', async () => {
		post.mockResolvedValueOnce({ data: { ...summary, records: 0 } })
		const empty = await openDialog()
		await pick(empty)
		expect(empty.find('.nc-checkbox-stub').exists()).toBe(false)

		post.mockResolvedValueOnce({ data: summary })
		const full = await openDialog()
		await pick(full)
		expect(full.find('.nc-checkbox-stub').text()).toContain('Import records')
		expect(full.find('.nc-checkbox-stub input').element.checked).toBe(false)
	})

	it('imports under the chosen slug with the records when ticked, then closes', async () => {
		const created = { uuid: 'app-1', slug: 'vergunningen-test', records: { imported: 40 } }
		post.mockResolvedValueOnce({ data: summary }).mockResolvedValueOnce({ data: created })
		const wrapper = await openDialog()
		await pick(wrapper)

		await wrapper.find('.nc-text-stub').setValue(' vergunningen-test ')
		await wrapper.find('.nc-checkbox-stub input').setValue(true)
		await wrapper.find('[data-testid="import-app-submit"]').trigger('click')
		await flushPromises()

		expect(post.mock.calls[1][0]).toBe('/index.php/apps/buildiq/api/applications/import')
		expect(fields(1)).toMatchObject({ slug: 'vergunningen-test', records: '1' })
		expect(fields(1).archive.name).toBe('vergunningen-1.2.0.zip')
		expect(wrapper.emitted('imported')).toEqual([[created]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('leaves the records behind when not ticked', async () => {
		post.mockResolvedValueOnce({ data: summary }).mockResolvedValueOnce({ data: { uuid: 'app-1' } })
		const wrapper = await openDialog()
		await pick(wrapper)
		await wrapper.find('[data-testid="import-app-submit"]').trigger('click')
		await flushPromises()

		expect(fields(1)).toMatchObject({ slug: 'vergunningen', records: '0' })
	})

	it('asks for another slug when the slug is taken, and stays open', async () => {
		post.mockResolvedValueOnce({ data: summary })
			.mockRejectedValueOnce({ response: { status: 409, data: { error: 'slug_collision' } } })
		const wrapper = await openDialog()
		await pick(wrapper)
		await wrapper.find('[data-testid="import-app-submit"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="import-app-error"]').text())
			.toBe('The slug "vergunningen" is already taken. Choose another slug.')
		expect(wrapper.emitted('imported')).toBeUndefined()
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('shows the parser\'s code and the file at fault for a refused package', async () => {
		post.mockRejectedValueOnce({
			response: { status: 422, data: { error: 'schema_unparseable', path: 'schemas/permit.json', detail: 'bad JSON' } },
		})
		const wrapper = await openDialog()
		await pick(wrapper)

		expect(wrapper.find('[data-testid="import-app-error"]').text()).toBe('schema_unparseable in schemas/permit.json: bad JSON')
		expect(wrapper.find('[data-testid="import-app-summary"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="import-app-submit"]').attributes('disabled')).toBeDefined()
	})

	it('falls back to a plain message when the server gave no code', () => {
		expect(importErrorMessage(new Error('network'), 'x')).toBe('The archive could not be imported.')
		expect(importErrorMessage({ response: { status: 413, data: { error: 'archive_too_large' } } }, 'x')).toBe('archive_too_large')
	})
})
