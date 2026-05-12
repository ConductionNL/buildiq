/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for LogsPageEditor (task 4.4 — `type: "logs"`).
 *
 * Covers:
 *  - Register picker calls useRegisterPicker.fetchRegisters on mount.
 *  - One-of source shape: register+schema vs `source`.
 *  - Switching to the `source` branch drops register+schema; setting a
 *    `source` value drops register+schema; setting a register drops
 *    `source`.
 *  - ColumnBuilder forwards through update:config; clearing to [] deletes
 *    the key.
 *  - Round-trip: a config key the editor doesn't surface survives update.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const fetchRegisters = vi.fn(async () => [
	{ slug: 'openbuilt-hello-world', title: 'Hello World' },
	{ slug: 'openbuilt', title: 'OpenBuilt apps' },
])
const fetchSchemas = vi.fn(async () => [{ slug: 'audit', title: 'Audit' }])
const fetchSchemaProperties = vi.fn(async () => ({ action: { type: 'string' } }))

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({ fetchRegisters, fetchSchemas, fetchSchemaProperties, resolveAppRegister: () => '' }),
}))
vi.mock('../../../src/components/page-editor/fields/ColumnBuilder.vue', () => ({
	default: {
		name: 'ColumnBuilder',
		props: ['modelValue', 'schemaProperties'],
		render(h) { return h('div', { staticClass: 'column-builder-stub' }) },
	},
}))

const LogsPageEditor = (await import('../../../src/components/page-editor/LogsPageEditor.vue')).default

function mountEditor(config = {}) {
	return mount(LogsPageEditor, { propsData: { config, appSlug: 'hello-world' } })
}

describe('LogsPageEditor', () => {
	beforeEach(() => {
		fetchRegisters.mockClear()
		fetchSchemas.mockClear()
	})

	it('renders the editor title', () => {
		expect(mountEditor().text()).toContain('Logs page')
	})

	it('calls fetchRegisters on mount', async () => {
		mountEditor()
		await new Promise((r) => setTimeout(r, 0))
		expect(fetchRegisters).toHaveBeenCalled()
	})

	it('defaults to the register+schema shape', () => {
		expect(mountEditor({}).vm.sourceShape).toBe('register')
	})

	it('a config with only `source` reports the source shape', () => {
		expect(mountEditor({ source: '/api/x/audit' }).vm.sourceShape).toBe('source')
	})

	it('a config with both register and source still reports register shape', () => {
		expect(mountEditor({ register: 'r', source: '/x' }).vm.sourceShape).toBe('register')
	})

	it('switching to the source branch drops register + schema', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's', columns: ['x'] })
		wrapper.vm.setSourceShape('source')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('register')
		expect(next).not.toHaveProperty('schema')
		// Unrelated keys survive.
		expect(next.columns).toEqual(['x'])
	})

	it('setting a source value drops register + schema (mutex)', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.vm.update('source', '/api/objects/r/audit')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.source).toBe('/api/objects/r/audit')
		expect(next).not.toHaveProperty('register')
		expect(next).not.toHaveProperty('schema')
	})

	it('setting a register drops a previously-set source and schema', async () => {
		const wrapper = mountEditor({ source: '/x', schema: 'old' })
		wrapper.vm.update('register', 'openbuilt')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.register).toBe('openbuilt')
		expect(next).not.toHaveProperty('source')
		expect(next).not.toHaveProperty('schema')
	})

	it('switching back to register branch drops `source`', async () => {
		const wrapper = mountEditor({ source: '/x' })
		wrapper.vm.setSourceShape('register')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('source')
	})

	it('ColumnBuilder forwards through update:config', async () => {
		const wrapper = mountEditor({ register: 'r', schema: 's' })
		wrapper.findComponent({ name: 'ColumnBuilder' }).vm.$emit('update:modelValue', [{ key: 'action' }])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.columns).toEqual([{ key: 'action' }])
	})

	it('clearing columns to [] deletes the key', async () => {
		const wrapper = mountEditor({ source: '/x', columns: [{ key: 'a' }] })
		wrapper.findComponent({ name: 'ColumnBuilder' }).vm.$emit('update:modelValue', [])
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next).not.toHaveProperty('columns')
	})

	it('preserves unsurfaced config keys on update (lossless round-trip)', async () => {
		const wrapper = mountEditor({ source: '/x', extraThing: { keep: true } })
		wrapper.vm.update('source', '/y')
		await wrapper.vm.$nextTick()
		const next = wrapper.emitted('update:config')[0][0]
		expect(next.extraThing).toEqual({ keep: true })
	})
})
