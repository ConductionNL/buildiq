/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for RecordGridPage — the runtime "Grid" page
 * (data-index-grid-and-saved-views T01/T02, REQ-BQIX-001/002).
 *
 * The object store and the lock composable are mocked at the
 * `@conduction/nextcloud-vue` boundary; CnDataMatrix is stubbed so the spec
 * reads the rows and columns it is handed and emits `cell-edit` itself.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const store = {
	objectTypeRegistry: {},
	errors: {},
	registerObjectType: vi.fn(function (slug) {
		this.objectTypeRegistry[slug] = {}
	}),
	fetchSchema: vi.fn(),
	fetchCollection: vi.fn(),
	getPagination: vi.fn(() => ({ total: 2, page: 1, pages: 1, limit: 50 })),
	patchObject: vi.fn(),
}
const lock = { acquire: vi.fn(), release: vi.fn() }
const useObjectLockSpy = vi.fn()

vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'me' }) }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	class LockConflictError extends Error {
		constructor(message, info = null) {
			super(message)
			this.name = 'LockConflictError'
			this.lockedBy = info?.user ?? null
		}
	}
	return {
		LockConflictError,
		useObjectStore: () => store,
		useObjectLock: (...args) => {
			useObjectLockSpy(...args)
			return lock
		},
		CnDataMatrix: {
			name: 'CnDataMatrix',
			props: ['rows', 'columns', 'rowIdKey', 'rowLabelKey', 'rowSublabelKey', 'rowHeader', 'emptyLabel'],
			emits: ['cell-edit'],
			render() {
				return h('div', { class: 'matrix-stub' })
			},
		},
	}
})

const { LockConflictError } = await import('@conduction/nextcloud-vue')
const { default: RecordGridPage } = await import('../../../src/components/runtime/RecordGridPage.vue')

const NcNoteCard = { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' }
const NcButton = { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' }

// The suite's global `t` returns the bare key; this spec checks the names
// the messages carry, so it fills the placeholders in.
const tFill = (_app, key, vars = {}) => key.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const originalT = globalThis.t

const schema = {
	properties: {
		name: { type: 'string', title: 'Name' },
		quantity: { type: 'integer', title: 'Quantity' },
		location: { type: 'string', readOnly: true },
	},
}

/**
 * Two stock items as OpenRegister lists them.
 *
 * @param {object} [lockOnFirst] - a `@self.locked` block for the first one.
 * @return {Array<object>}
 */
function records(lockOnFirst = null) {
	return [
		{ id: 'u1', name: 'Printer paper', quantity: 12, location: 'A1', '@self': { name: 'Printer paper', locked: lockOnFirst } },
		{ id: 'u2', name: 'Toner', quantity: 3, location: 'B2', '@self': { name: 'Toner', locked: null } },
	]
}

/**
 * Mount the grid for register `stock`, schema `item`.
 *
 * @param {object} [props] - extra props.
 * @return {Promise<object>} the wrapper, after loading.
 */
async function mountGrid(props = {}) {
	const wrapper = mount(RecordGridPage, {
		props: {
			register: 'stock',
			schema: 'item',
			columns: ['name', { key: 'quantity', label: 'Qty' }, 'location'],
			editable: ['quantity', 'location'],
			...props,
		},
		global: { stubs: { NcNoteCard, NcButton }, mocks: { t: tFill } },
	})
	await flushPromises()
	return wrapper
}

const matrix = (wrapper) => wrapper.findComponent({ name: 'CnDataMatrix' })

describe('RecordGridPage', () => {
	beforeAll(() => {
		globalThis.t = tFill
	})
	afterAll(() => {
		globalThis.t = originalT
	})

	beforeEach(() => {
		vi.clearAllMocks()
		store.objectTypeRegistry = {}
		store.errors = {}
		store.fetchSchema.mockResolvedValue(schema)
		store.fetchCollection.mockResolvedValue(records())
		lock.acquire.mockResolvedValue(undefined)
		lock.release.mockResolvedValue(undefined)
	})

	it('registers the type and loads one page of the schema\'s records', async () => {
		await mountGrid()
		expect(store.registerObjectType).toHaveBeenCalledWith('stock-item', 'item', 'stock')
		expect(store.fetchSchema).toHaveBeenCalledWith('stock-item')
		expect(store.fetchCollection).toHaveBeenCalledWith('stock-item', { _limit: 50, _page: 1 })
	})

	it('hands the matrix one row per record and columns that follow the maker and the schema', async () => {
		const wrapper = await mountGrid()
		expect(matrix(wrapper).props('rows')).toEqual([
			{ id: 'u1', label: 'Printer paper', name: 'Printer paper', quantity: 12, location: 'A1' },
			{ id: 'u2', label: 'Toner', name: 'Toner', quantity: 3, location: 'B2' },
		])
		expect(matrix(wrapper).props('columns')).toEqual([
			{ key: 'name', label: 'Name', type: 'string', readOnly: true, aggregate: 'none' },
			{ key: 'quantity', label: 'Qty', type: 'number', readOnly: false, aggregate: 'none' },
			// Marked editable by the maker, but the schema says readOnly.
			{ key: 'location', label: 'location', type: 'string', readOnly: true, aggregate: 'none' },
		])
	})

	it('saves one property on its record after taking the row lock', async () => {
		store.patchObject.mockResolvedValue({ id: 'u1', quantity: 10 })
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 10 })
		await flushPromises()

		expect(lock.acquire).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.lockedRowId).toBe('u1')
		expect(store.patchObject).toHaveBeenCalledWith('stock-item', 'u1', { quantity: 10 })
		expect(matrix(wrapper).props('rows')[0].quantity).toBe(10)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('keeps a number a number when the server echoes it back', async () => {
		store.patchObject.mockResolvedValue({ id: 'u2', quantity: 4 })
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u2', colKey: 'quantity', value: 4 })
		await flushPromises()
		expect(matrix(wrapper).props('rows')[1].quantity).toBe(4)
		expect(typeof matrix(wrapper).props('rows')[1].quantity).toBe('number')
	})

	it('restores the old value and names the reason when the save is refused', async () => {
		store.patchObject.mockImplementation(async () => {
			store.errors['stock-item'] = { message: 'You may not change this record' }
			return null
		})
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 10 })
		await flushPromises()

		expect(matrix(wrapper).props('rows')[0].quantity).toBe(12)
		const note = wrapper.find('.note')
		expect(note.attributes('data-type')).toBe('error')
		expect(note.text()).toBe('Could not save Qty of Printer paper: You may not change this record')
	})

	it('does not save a column the maker did not mark editable, nor an unchanged value', async () => {
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'name', value: 'Paper' })
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'location', value: 'C3' })
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 12 })
		await flushPromises()
		expect(store.patchObject).not.toHaveBeenCalled()
		expect(lock.acquire).not.toHaveBeenCalled()
	})

	it('refuses a row a colleague holds, names them, and does not lock or save', async () => {
		const future = new Date(Date.now() + 600000).toISOString()
		store.fetchCollection.mockResolvedValue(records({ user: 'anna', displayName: 'Anna', expiresAt: future }))
		const wrapper = await mountGrid()

		expect(matrix(wrapper).props('rows')[0].lockNote).toBe('Anna is editing this record')
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 10 })
		await flushPromises()

		expect(lock.acquire).not.toHaveBeenCalled()
		expect(store.patchObject).not.toHaveBeenCalled()
		expect(matrix(wrapper).props('rows')[0].quantity).toBe(12)
		expect(wrapper.find('.note').text()).toBe('Anna is editing Printer paper; your change was not saved.')
	})

	it('treats its own lock and an expired lock as free', async () => {
		store.fetchCollection.mockResolvedValue([
			{ ...records()[0], '@self': { locked: { user: 'me' } } },
			{ ...records()[1], '@self': { locked: { user: 'anna', expiresAt: '2000-01-01T00:00:00Z' } } },
		])
		const wrapper = await mountGrid()
		expect(matrix(wrapper).props('rows').map((row) => row.lockNote)).toEqual([undefined, undefined])
	})

	it('marks the row locked when taking the lock conflicts, and saves nothing', async () => {
		lock.acquire.mockRejectedValue(new LockConflictError('locked', { user: 'piet' }))
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u2', colKey: 'quantity', value: 5 })
		await flushPromises()

		expect(store.patchObject).not.toHaveBeenCalled()
		expect(wrapper.vm.lockedRowId).toBe('')
		expect(matrix(wrapper).props('rows')[1].lockNote).toBe('piet is editing this record')
		expect(wrapper.find('.note').attributes('data-type')).toBe('warning')
	})

	it('releases the previous row\'s lock before taking the next one', async () => {
		store.patchObject.mockImplementation(async (type, id, changes) => ({ id, ...changes }))
		const wrapper = await mountGrid()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 11 })
		await flushPromises()
		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u1', colKey: 'quantity', value: 9 })
		await flushPromises()
		expect(lock.acquire).toHaveBeenCalledTimes(1)
		expect(lock.release).not.toHaveBeenCalled()

		matrix(wrapper).vm.$emit('cell-edit', { rowId: 'u2', colKey: 'quantity', value: 2 })
		await flushPromises()
		expect(lock.release).toHaveBeenCalledTimes(1)
		expect(lock.acquire).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.lockedRowId).toBe('u2')
	})

	it('binds the lock to the register, the type slug and the schema slug', async () => {
		await mountGrid()
		const [lockStore, register, type, id, options] = useObjectLockSpy.mock.calls[0]
		expect(lockStore).toBe(store)
		expect(register.value).toBe('stock')
		expect(type.value).toBe('stock-item')
		expect(id.value).toBe('')
		expect(options.schemaSlug.value).toBe('item')
	})

	it('shows a note and loads nothing without a register and schema', async () => {
		const wrapper = await mountGrid({ register: '', schema: '' })
		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(wrapper.find('.note').text()).toBe('This grid has no register and schema yet.')
	})

	it('pages through the records', async () => {
		store.getPagination.mockReturnValue({ total: 120, page: 1, pages: 3, limit: 50 })
		const wrapper = await mountGrid()
		const buttons = wrapper.findAll('button')
		expect(buttons[0].attributes('disabled')).toBeDefined()
		await buttons[1].trigger('click')
		await flushPromises()
		expect(store.fetchCollection).toHaveBeenLastCalledWith('stock-item', { _limit: 50, _page: 2 })
	})
})
