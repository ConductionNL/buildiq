/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what every page editor offers its host: its props and their defaults, the
 * events it declares, what `update()` and the register-picker handlers emit, and
 * which register-picker calls it makes.
 */

import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'

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
	beforeEach(() => vi.clearAllMocks())

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
