/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for src/dialogs/SqlQueryDialog.vue
 * (data-external-database-sources T08 and T09, REQ-BQDB-005 / REQ-BQDB-006):
 * the preview through OpenRegister's query-preview route, "Save as table"
 * through its schema and register API, the refusals shown without SQL or
 * connection details, and "Add a query table" only for an administrator.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}))

vi.mock('../../src/composables/useConnectedDatabases.js', () => ({
	fetchConnectedDatabases: vi.fn(async () => [
		{ register: 'permits-db', label: 'Permits database', source: 'src-permits', sourceId: '7', writable: true },
	]),
}))

import axios from '@nextcloud/axios'
import SqlQueryDialog, { columnProperties, PREVIEW_ROWS, refusalMessage } from '../../src/dialogs/SqlQueryDialog.vue'
import SchemaDesigner from '../../src/views/SchemaDesigner.vue'

const QUERY = 'SELECT district, count(*) AS open FROM permits WHERE status = \'open\' GROUP BY district'

const NcDialogStub = {
	name: 'NcDialog',
	props: ['open', 'name', 'size'],
	template: '<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
}

const NcSelectStub = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'inputLabel', 'label', 'clearable'],
	template: '<div class="nc-select-stub" />',
}

const NcTextFieldStub = {
	name: 'NcTextField',
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	template: '<input class="nc-text-field-stub" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
}

/**
 * Mount the dialog open, its single connected database preselected.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountDialog() {
	const wrapper = mount(SqlQueryDialog, {
		props: { open: true },
		global: {
			stubs: { NcDialog: NcDialogStub, NcSelect: NcSelectStub, NcTextField: NcTextFieldStub, CnDataTable: true },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * Write the query and run the preview.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The dialog.
 * @param {string} query The query.
 * @return {Promise<void>}
 */
async function preview(wrapper, query = QUERY) {
	await wrapper.find('[data-testid="sql-query-text"]').setValue(query)
	await wrapper.find('[data-testid="sql-query-preview"]').trigger('click')
	await flushPromises()
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.patch.mockReset()
})

afterEach(() => {
	delete globalThis.OC
})

describe('SqlQueryDialog — preview and save (REQ-BQDB-005)', () => {
	it('previews the query through OpenRegister on the picked database\'s source, at most 50 rows', async () => {
		const rows = Array.from({ length: 60 }, (_, index) => ({ district: 'D' + index, open: index }))
		axios.post.mockResolvedValue({ data: { columns: [{ name: 'district', type: 'string' }, { name: 'open', type: 'integer' }], rows } })
		const wrapper = await mountDialog()

		await preview(wrapper)

		expect(axios.post).toHaveBeenCalledWith('/apps/openregister/api/sources/7/query-preview', { query: QUERY })
		expect(wrapper.vm.previewColumns).toEqual([{ key: 'district', label: 'district' }, { key: 'open', label: 'open' }])
		expect(wrapper.vm.previewRows).toHaveLength(PREVIEW_ROWS)
		expect(wrapper.find('[data-testid="sql-query-error"]').exists()).toBe(false)
	})

	it('saves the previewed query as a read-only table of the database register', async () => {
		axios.post
			.mockResolvedValueOnce({ data: { columns: [{ name: 'district', type: 'string' }, { name: 'open', type: 'integer' }], rows: [] } })
			.mockResolvedValueOnce({ data: { id: 31, slug: 'open-permits-per-district' } })
		axios.get.mockResolvedValue({ data: { id: 1, slug: 'permits-db', schemas: [{ id: 11 }, 12] } })
		axios.patch.mockResolvedValue({ data: {} })
		const wrapper = await mountDialog()

		await preview(wrapper)
		await wrapper.find('[data-testid="sql-query-name"]').setValue('Open permits per district')
		await wrapper.find('[data-testid="sql-query-save"]').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenLastCalledWith('/apps/openregister/api/schemas', {
			title: 'Open permits per district',
			slug: 'open-permits-per-district',
			properties: { district: { type: 'string' }, open: { type: 'integer' } },
			configuration: {
				'x-openregister-object-source': {
					provider: 'dbal-source',
					readOnly: true,
					config: { sourceId: '7', query: QUERY },
				},
			},
		})
		expect(axios.patch).toHaveBeenCalledWith('/apps/openregister/api/registers/permits-db', { schemas: [11, 12, 31] })
		expect(wrapper.emitted('saved')).toEqual([[{ register: 'permits-db', schema: 'open-permits-per-district' }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('types a column OpenRegister did not type as a string', () => {
		expect(columnProperties([{ name: 'a', type: 'date' }, { name: 'b' }, { name: 'c', type: 'number' }])).toEqual({
			a: { type: 'string' },
			b: { type: 'string' },
			c: { type: 'number' },
		})
	})
})

describe('SqlQueryDialog — refusals (REQ-BQDB-006)', () => {
	it('keeps "Save as table" off until a preview of this very query succeeded', async () => {
		axios.post.mockResolvedValue({ data: { columns: [{ name: 'district', type: 'string' }], rows: [] } })
		const wrapper = await mountDialog()
		const save = () => wrapper.find('[data-testid="sql-query-save"]').attributes('disabled')

		await wrapper.find('[data-testid="sql-query-name"]').setValue('Open permits per district')
		expect(save()).toBeDefined()

		await preview(wrapper)
		expect(save()).toBeUndefined()

		await wrapper.find('[data-testid="sql-query-text"]').setValue(QUERY + ' ORDER BY district')
		expect(save()).toBeDefined()
	})

	it('shows a refused DELETE as one sentence, without the statement', async () => {
		axios.post.mockRejectedValue({ response: { status: 400, data: { error: 'A query-backed schema may not use "DELETE".' } } })
		const wrapper = await mountDialog()

		await preview(wrapper, 'DELETE FROM permits')

		const error = wrapper.find('[data-testid="sql-query-error"]').text()
		expect(error).toBe('Only a single read-only query can run.')
		expect(error).not.toContain('DELETE')
		expect(wrapper.vm.preview).toBeNull()
		expect(wrapper.find('[data-testid="sql-query-save"]').attributes('disabled')).toBeDefined()
	})

	it('shows a timeout as OpenRegister\'s fixed sentence, without the error class', async () => {
		axios.post.mockRejectedValue({ response: { status: 502, data: { error: 'The database refused the query.', errorClass: 'QueryCanceled' } } })
		const wrapper = await mountDialog()

		await preview(wrapper)

		const error = wrapper.find('[data-testid="sql-query-error"]').text()
		expect(error).toBe('The database refused the query.')
		expect(error).not.toContain('QueryCanceled')
	})

	it('shows a 403 as administrators only', async () => {
		axios.post.mockRejectedValue({ response: { status: 403, data: { error: 'Admin only' } } })
		const wrapper = await mountDialog()

		await preview(wrapper)

		expect(wrapper.find('[data-testid="sql-query-error"]').text()).toBe('Only an administrator can run a query.')
	})

	it('answers every other failure with a fixed sentence', () => {
		expect(refusalMessage({ status: 404 })).toBe('This database is no longer connected.')
		expect(refusalMessage({ status: 503, data: { error: 'Could not connect to the database source' } })).toBe('Could not connect to the database source')
		expect(refusalMessage({ status: 500, data: { error: 'SQLSTATE[42P01] relation "permits" at host db.internal' } })).toBe('The query could not be run. Try again later.')
		expect(refusalMessage(undefined)).toBe('The query could not be run. Try again later.')
	})
})

describe('SchemaDesigner — "Add a query table" (REQ-BQDB-006)', () => {
	it('is offered to an administrator only', () => {
		const isAdministrator = SchemaDesigner.computed.isAdministrator

		globalThis.OC = { isUserAdmin: () => false }
		expect(isAdministrator.call({})).toBe(false)

		globalThis.OC = { isUserAdmin: () => true }
		expect(isAdministrator.call({})).toBe(true)

		delete globalThis.OC
		expect(isAdministrator.call({})).toBe(false)
	})
})
