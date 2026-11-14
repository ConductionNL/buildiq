/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the "Saved views" section of IndexPageEditor
 * (data-index-grid-and-saved-views T05, REQ-BQIX-003): the switch that lets
 * app users save their own views and the views the maker defines for
 * everyone (`config.defaultSavedViews`).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const requestProperties = { status: { type: 'string' }, date: { type: 'string' } }

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [],
		fetchSchemas: async () => [{ slug: 'request', title: 'Request', properties: requestProperties }],
		fetchSchemaProperties: async () => requestProperties,
		resolveAppRegister: () => 'app',
	}),
}))

const IndexPageEditor = (
	await import('../../../src/components/page-editor/IndexPageEditor.vue')
).default

/**
 * Mount the editor on a page config.
 *
 * @param {object} config - the page config.
 * @return {Promise<object>} the wrapper.
 */
async function mountEditor(config) {
	const wrapper = mount(IndexPageEditor, { props: { config, appSlug: 'app' } })
	await flushPromises()
	return wrapper
}

/**
 * The saved-views switch.
 *
 * @param {object} wrapper - the mounted editor.
 * @return {object} the checkbox wrapper.
 */
function savedViewsSwitch(wrapper) {
	const label = wrapper
		.findAll('label')
		.find((l) => l.text().includes('Let app users save their own views'))
	return label.find('input[type="checkbox"]')
}

describe('IndexPageEditor saved views', () => {
	it('switches allowSavedViews on and keeps the other keys', async () => {
		const wrapper = await mountEditor({ register: 'r', schema: 'request', columns: ['status'] })
		expect(savedViewsSwitch(wrapper).element.checked).toBe(false)
		await savedViewsSwitch(wrapper).setValue(true)
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			register: 'r',
			schema: 'request',
			columns: ['status'],
			allowSavedViews: true,
		})
	})

	it('switching off deletes the key instead of storing false', async () => {
		const wrapper = await mountEditor({ register: 'r', schema: 'request', allowSavedViews: true })
		expect(savedViewsSwitch(wrapper).element.checked).toBe(true)
		await savedViewsSwitch(wrapper).setValue(false)
		const emitted = wrapper.emitted('update:config').at(-1)[0]
		expect(emitted).toEqual({ register: 'r', schema: 'request' })
		expect('allowSavedViews' in emitted).toBe(false)
	})

	it('writes the views for everyone to config.defaultSavedViews', async () => {
		const wrapper = await mountEditor({ register: 'r', schema: 'request' })
		const builder = wrapper.findComponent({ name: 'SavedViewsBuilder' })
		expect(builder.props('schemaProperties')).toEqual(requestProperties)

		const view = { slug: 'view-1', name: 'Open requests', filters: { status: 'open' }, sort: [{ key: 'date', order: 'desc' }] }
		builder.vm.$emit('update:modelValue', [view])
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({
			register: 'r',
			schema: 'request',
			defaultSavedViews: [view],
		})

		builder.vm.$emit('update:modelValue', [])
		expect(wrapper.emitted('update:config').at(-1)[0]).toEqual({ register: 'r', schema: 'request' })
	})
})
