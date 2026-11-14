/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the register + schema picker of the Detail, Index, Logs and Search
 * page editors: the rendered markup (with and without a validation error on
 * each field) and the `update:config` each select change emits. Scoped-style
 * attributes (`data-v-*`) are left out: they name the component that renders
 * the markup, not the markup itself.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../../src/composables/useRegisterPicker.js', () => ({
	useRegisterPicker: () => ({
		fetchRegisters: async () => [{ slug: 'r1', title: 'R1' }, { id: 7, slug: 'r2' }],
		fetchSchemas: async () => [{ slug: 's1', title: 'S1' }, { id: 9, slug: 's2' }],
		fetchSchemaProperties: async () => ({}),
		resolveAppRegister: () => '',
	}),
}))

import DetailPageEditor from '../../../src/components/page-editor/DetailPageEditor.vue'
import IndexPageEditor from '../../../src/components/page-editor/IndexPageEditor.vue'
import LogsPageEditor from '../../../src/components/page-editor/LogsPageEditor.vue'
import SearchPageEditor from '../../../src/components/page-editor/SearchPageEditor.vue'

const EDITORS = { DetailPageEditor, IndexPageEditor, LogsPageEditor, SearchPageEditor }

/**
 * @param {object} Editor the page editor.
 * @param {object} options `{ config, invalid }`.
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountEditor(Editor, options) {
	const invalid = options.invalid || []
	const wrapper = mount(Editor, {
		props: { config: options.config, parentRoute: '/x/:id', appSlug: 'app' },
		global: {
			provide: {
				pageEditorValidator: {
					register: () => {},
					unregister: () => {},
					errorFor: (key) => (invalid.includes(key)
						? { hasError: true, message: `${key} is wrong` }
						: { hasError: false, message: '' }),
				},
			},
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * The markup of the register and schema labels.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper the mounted editor.
 * @return {string[]}
 */
function pickerMarkup(wrapper) {
	return wrapper.findAll('label')
		.filter((label) => label.find('select').exists())
		.slice(0, 2)
		.map((label) => label.html().replace(/ data-v-[0-9a-f]+=""/g, ''))
}

describe.each(Object.entries(EDITORS))('%s register + schema picker', (_name, Editor) => {
	it('renders both selects without errors', async () => {
		const wrapper = await mountEditor(Editor, { config: { register: 'r1', schema: 's1' } })
		expect(pickerMarkup(wrapper)).toMatchSnapshot()
	})

	it('renders both selects with errors and no register', async () => {
		const wrapper = await mountEditor(Editor, { config: {}, invalid: ['register', 'schema'] })
		expect(pickerMarkup(wrapper)).toMatchSnapshot()
	})

	it('emits update:config on a register and a schema change', async () => {
		const wrapper = await mountEditor(Editor, { config: { register: 'r1', schema: 's1', keep: 1 } })
		const selects = wrapper.findAll('label').filter((l) => l.find('select').exists()).slice(0, 2)

		await selects[0].find('select').setValue('r2')
		await selects[1].find('select').setValue('s2')
		await selects[0].find('select').setValue('')

		expect(wrapper.emitted('update:config')).toMatchSnapshot()
	})
})
