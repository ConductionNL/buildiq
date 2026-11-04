/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what every page editor offers its host: its props and their defaults, the
 * events it declares, what `update()` and the register-picker handlers emit, and
 * which register-picker calls it makes.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const calls = []
vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: (options) => {
		calls.push(['useRegisterPicker', options.appSlug, options.appRegister?.(), options.dataRegisters])
		return {
			fetchRegisters: async () => { calls.push(['fetchRegisters']); return [{ slug: 'r1', title: 'R1' }] },
			fetchSchemas: async (register) => { calls.push(['fetchSchemas', register]); return [{ slug: 's1', title: 'S1' }] },
			fetchSchemaProperties: async (register, schema) => { calls.push(['fetchSchemaProperties', register, schema]); return { p: { type: 'string' } } },
			resolveAppRegister: () => 'own-register',
		}
	},
}))

const modules = import.meta.glob('../../../src/components/page-editor/*PageEditor.vue')
const FIXTURE = resolve(__dirname, '__golden__/pageEditorSurface.golden.json')

/**
 * Serialisable default of a prop.
 *
 * @param {*} value The value.
 * @return {*} The value, with functions named.
 */
function plain(value) {
	return value === undefined ? '(undefined)' : JSON.parse(JSON.stringify(value))
}

/**
 * Record what one editor offers.
 *
 * @param {object} Component The editor.
 * @return {Promise<object>} The record.
 */
async function surface(Component) {
	calls.length = 0
	const wrapper = mount(Component, {
		props: {
			config: { keep: 'me', register: 'reg-a', schema: 'sch-a', sidebarSchema: 'side-a', x: 'old' },
			appSlug: 'my-app',
			appRegister: 'own',
			dataRegisters: [{ slug: 'data' }],
		},
		global: { mocks: { t: (_app, text) => text }, renderStubDefaultSlot: true },
		shallow: true,
	})
	await flushPromises()
	const vm = wrapper.vm
	const record = {
		props: Object.fromEntries(Object.keys(wrapper.vm.$props).sort().map((key) => [key, plain(vm.$props[key])])),
		emits: Object.keys(vm.$.emitsOptions || {}).sort(),
		picker: [...calls],
		handlers: {},
	}
	const inputs = { update: [['x', 'new'], ['x', ''], ['x', null], ['x', []], ['y', 0], ['y', false]], updateRegister: [['reg-b'], [''], [null]] }
	for (const [handler, cases] of Object.entries(inputs)) {
		if (typeof vm[handler] !== 'function') {
			continue
		}
		record.handlers[handler] = cases.map((args) => {
			const before = (wrapper.emitted('update:config') || []).length
			vm[handler](...args)
			return { args, emitted: plain((wrapper.emitted('update:config') || []).slice(before)) }
		})
	}
	wrapper.unmount()
	return plain(record)
}

describe('page editor surface', () => {
	// Transforming the editors takes most of a minute on a loaded machine: not on any test's clock.
	beforeAll(() => Promise.all(Object.values(modules).map((load) => load())), 300000)
	beforeEach(() => vi.clearAllMocks())

	it('removes a cleared key rather than leaving it undefined', async () => {
		const { default: Wiki } = await modules['../../../src/components/page-editor/WikiPageEditor.vue']()
		const wrapper = mount(Wiki, { props: { config: { x: 'old', keep: 1 } }, shallow: true, global: { mocks: { t: (_a, text) => text } } })
		wrapper.vm.update('x', '')
		expect(Object.keys(wrapper.emitted('update:config')[0][0])).toEqual(['keep'])
		wrapper.unmount()
	})

	it('follows the bound register and schema, and empties the lists when they are cleared', async () => {
		const { default: Logs } = await modules['../../../src/components/page-editor/LogsPageEditor.vue']()
		const wrapper = mount(Logs, { props: { config: { register: 'reg-a', schema: 'sch-a' } }, shallow: true, global: { mocks: { t: (_a, text) => text } } })
		await flushPromises()
		expect(calls).toContainEqual(['fetchSchemaProperties', 'reg-a', 'sch-a'])
		expect(wrapper.vm.schemas).toEqual([{ slug: 's1', title: 'S1' }])
		expect(wrapper.vm.schemaProperties).toEqual({ p: { type: 'string' } })

		calls.length = 0
		await wrapper.setProps({ config: { schema: 'sch-b' } })
		await flushPromises()
		expect(wrapper.vm.schemas).toEqual([])
		expect(wrapper.vm.schemaProperties).toEqual({})
		expect(calls).toEqual([])
		wrapper.unmount()
	})

	it('matches what was recorded for every editor', async () => {
		const actual = {}
		for (const path of Object.keys(modules).sort()) {
			const name = path.split('/').pop()
			actual[name] = await surface((await modules[path]()).default)
		}
		if (!existsSync(FIXTURE) && process.env.GOLDEN_RECORD === '1') {
			writeFileSync(FIXTURE, JSON.stringify(actual, null, '\t') + '\n')
		}
		const expected = JSON.parse(readFileSync(FIXTURE, 'utf8'))
		expect(Object.keys(actual)).toEqual(Object.keys(expected))
		for (const name of Object.keys(expected)) {
			expect(actual[name], name).toEqual(expected[name])
		}
	}, 60000)
})
