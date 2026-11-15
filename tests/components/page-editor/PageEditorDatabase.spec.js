/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest tests for the connected-database handling of IndexPageEditor and
 * DetailPageEditor (data-external-database-sources T03): the register select
 * marks a connected database, a read-only one switches the write actions off
 * and locks them with a note, a writable one leaves them alone.
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: vi.fn(async () => [
			{ slug: 'openbuild-permits', title: 'Permits app' },
			{ slug: 'permits-db', title: 'Permit database' },
			{ slug: 'crm-db', title: 'CRM' },
		]),
		fetchSchemas: vi.fn(async () => []),
		fetchSchemaProperties: vi.fn(async () => ({})),
		resolveAppRegister: () => 'openbuild-permits',
	}),
}))

const fetchConnectedDatabases = vi.fn()
vi.mock('../../../src/composables/useConnectedDatabases.js', async (importOriginal) => {
	const actual = await importOriginal()
	return { ...actual, fetchConnectedDatabases: (...args) => fetchConnectedDatabases(...args) }
})

const IndexPageEditor = (await import('../../../src/components/page-editor/IndexPageEditor.vue')).default
const DetailPageEditor = (await import('../../../src/components/page-editor/DetailPageEditor.vue')).default

const DATABASES = [
	{ register: 'permits-db', label: 'Permit database', source: '7', writable: false },
	{ register: 'crm-db', label: 'CRM', source: '8', writable: true },
]

async function mountEditor(editor, config) {
	const wrapper = mount(editor, { props: { config, appSlug: 'permits', parentRoute: '/permits/:id' } })
	await flushPromises()
	return wrapper
}

function lastConfig(wrapper) {
	const emitted = wrapper.emitted('update:config') || []
	return emitted.length ? emitted[emitted.length - 1][0] : undefined
}

function registerOptions(wrapper) {
	return wrapper.findAll('select').at(0).findAll('option').map((option) => option.text())
}

describe('IndexPageEditor — connected database (REQ-BQDB-002)', () => {
	beforeEach(() => {
		fetchConnectedDatabases.mockReset()
		fetchConnectedDatabases.mockResolvedValue(DATABASES)
	})

	it('marks the registers a connected database serves', async () => {
		const wrapper = await mountEditor(IndexPageEditor, {})

		expect(registerOptions(wrapper)).toEqual(expect.arrayContaining([
			'Permits app',
			'Permit database — Connected database',
			'CRM — Connected database',
		]))
	})

	it('a read-only database switches add, edit and delete off and locks them with the note', async () => {
		const wrapper = await mountEditor(IndexPageEditor, { register: 'permits-db', schema: 'permits' })

		expect(lastConfig(wrapper)).toEqual({
			register: 'permits-db',
			schema: 'permits',
			showAdd: false,
			showEditAction: false,
			showDeleteAction: false,
		})
		await wrapper.setProps({ config: lastConfig(wrapper) })
		for (const key of ['showAdd', 'showEditAction', 'showDeleteAction']) {
			const box = wrapper.find(`[data-test="write-action-${key}"]`)
			expect(box.element.checked).toBe(false)
			expect(box.element.disabled).toBe(true)
		}
		expect(wrapper.find('[data-test="write-actions-read-only"]').text()).toBe(
			'This database is read only. Ask an administrator to allow writes.',
		)
	})

	it('does not emit again when the actions are already off', async () => {
		const wrapper = await mountEditor(IndexPageEditor, {
			register: 'permits-db', showAdd: false, showEditAction: false, showDeleteAction: false,
		})

		expect(wrapper.emitted('update:config')).toBeUndefined()
	})

	it('a writable database leaves the actions alone, unlocked and without the note', async () => {
		const wrapper = await mountEditor(IndexPageEditor, { register: 'crm-db', showAdd: false })

		expect(wrapper.emitted('update:config')).toBeUndefined()
		expect(wrapper.find('[data-test="write-action-showAdd"]').element.disabled).toBe(false)
		expect(wrapper.find('[data-test="write-actions-read-only"]').exists()).toBe(false)

		await wrapper.find('[data-test="write-action-showAdd"]').setValue(true)
		expect(lastConfig(wrapper)).toEqual({ register: 'crm-db' })
		await wrapper.find('[data-test="write-action-showDeleteAction"]').setValue(false)
		expect(lastConfig(wrapper)).toEqual({ register: 'crm-db', showAdd: false, showDeleteAction: false })
	})

	it('unlocks again once the source takes writes, on the next open', async () => {
		fetchConnectedDatabases.mockResolvedValue([{ ...DATABASES[0], writable: true }])
		const wrapper = await mountEditor(IndexPageEditor, {
			register: 'permits-db', showAdd: false, showEditAction: false, showDeleteAction: false,
		})

		expect(wrapper.find('[data-test="write-action-showAdd"]').element.disabled).toBe(false)
		expect(wrapper.find('[data-test="write-actions-read-only"]').exists()).toBe(false)
	})

	it('a register no database serves is not locked', async () => {
		const wrapper = await mountEditor(IndexPageEditor, { register: 'openbuild-permits' })

		expect(wrapper.emitted('update:config')).toBeUndefined()
		expect(wrapper.find('[data-test="write-action-showAdd"]').element.disabled).toBe(false)
	})
})

describe('DetailPageEditor — connected database (REQ-BQDB-002)', () => {
	beforeEach(() => {
		fetchConnectedDatabases.mockReset()
		fetchConnectedDatabases.mockResolvedValue(DATABASES)
	})

	it('a read-only database switches the edit action off and locks it with the note', async () => {
		const wrapper = await mountEditor(DetailPageEditor, { register: 'permits-db', schema: 'permits' })

		expect(lastConfig(wrapper)).toEqual({ register: 'permits-db', schema: 'permits', showEditAction: false })
		await wrapper.setProps({ config: lastConfig(wrapper) })
		const box = wrapper.find('[data-test="write-action-showEditAction"]')
		expect(box.element.checked).toBe(false)
		expect(box.element.disabled).toBe(true)
		expect(wrapper.find('[data-test="write-action-showAdd"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="write-actions-read-only"]').exists()).toBe(true)
	})

	it('a writable database leaves the edit action on and unlocked', async () => {
		const wrapper = await mountEditor(DetailPageEditor, { register: 'crm-db', schema: 'contacts' })

		expect(wrapper.emitted('update:config')).toBeUndefined()
		const box = wrapper.find('[data-test="write-action-showEditAction"]')
		expect(box.element.checked).toBe(true)
		expect(box.element.disabled).toBe(false)
	})
})
