/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `WidgetEditor.vue` and its block helpers.
 *
 * The component is controlled: `widgets` is a prop and every edit emits the
 * next array on `update:widgets`. The config field is validated as JSON on
 * every keystroke; an invalid row is kept in the editor but dropped when the
 * block is serialised.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WidgetEditor, {
	editorToWidgets,
	widgetsToEditor,
} from '../../../src/components/schema-editor/WidgetEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'ariaLabel'],
		emits: ['click'],
		template: '<button :aria-label="ariaLabel" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText'],
		emits: ['update:modelValue'],
		template: '<input :data-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
	PlusIcon: true,
	DeleteIcon: true,
}

const row = (overrides = {}) => ({ _key: 'w1', slot: 'sidebar', widget: 'stats', configJson: '{}', configError: '', ...overrides })

const mountWith = (widgets) => mount(WidgetEditor, { props: { widgets }, global: { stubs } })

function lastEmit (wrapper) {
	const events = wrapper.emitted('update:widgets')
	return events[events.length - 1][0]
}

describe('WidgetEditor', () => {
	it('warns that ids are typed by hand and shows the empty message', () => {
		const wrapper = mountWith([])

		expect(wrapper.find('.note').text()).toBe('There is no list of widgets yet, so type the widget ID yourself.')
		expect(wrapper.find('.buildiq-widget-editor__empty').text()).toBe('No widgets yet.')
	})

	it('adds a blank widget with an empty JSON config', async () => {
		const wrapper = mountWith([row()])

		await wrapper.findAll('button')[0].trigger('click')

		const next = lastEmit(wrapper)
		expect(next[0]).toEqual(row())
		expect(next[1]).toMatchObject({ slot: '', widget: '', configJson: '{}', configError: '' })
		expect(next[1]._key).toMatch(/^widget-\d+$/)
	})

	it('updates slot and widget id of one row', async () => {
		const wrapper = mountWith([row(), row({ _key: 'w2', slot: 'main' })])
		const inputs = wrapper.findAll('input')

		await inputs[0].setValue('header')
		expect(lastEmit(wrapper)[0]).toEqual(row({ slot: 'header' }))
		expect(lastEmit(wrapper)[1]).toEqual(row({ _key: 'w2', slot: 'main' }))

		await inputs[1].setValue('chart')
		expect(lastEmit(wrapper)[0]).toEqual(row({ widget: 'chart' }))
	})

	it('flags invalid JSON config and clears the flag once it parses', async () => {
		const wrapper = mountWith([row()])
		const config = wrapper.findAll('input')[2]

		await config.setValue('{"limit": ')
		expect(lastEmit(wrapper)[0]).toEqual(row({ configJson: '{"limit": ', configError: 'Config must be valid JSON.' }))

		await config.setValue('{"limit": 5}')
		expect(lastEmit(wrapper)[0]).toEqual(row({ configJson: '{"limit": 5}', configError: '' }))
	})

	it('treats an empty config as an empty object', () => {
		const wrapper = mountWith([row()])

		wrapper.vm.updateConfig(0, '')

		expect(lastEmit(wrapper)[0]).toEqual(row({ configJson: '', configError: '' }))
	})

	it('passes a row error on to its config field', () => {
		const wrapper = mount(WidgetEditor, { props: { widgets: [row({ configError: 'Config must be valid JSON.' })] }, global: { stubs } })
		const config = wrapper.findAllComponents({ name: 'NcTextField' })[2]

		expect(config.props('error')).toBe(true)
		expect(config.props('helperText')).toBe('Config must be valid JSON.')
	})

	it('removes the clicked row', async () => {
		const wrapper = mountWith([row(), row({ _key: 'w2', slot: 'main' })])

		await wrapper.find('button[aria-label="Remove widget"]').trigger('click')

		expect(lastEmit(wrapper)).toEqual([row({ _key: 'w2', slot: 'main' })])
	})
})

describe('widgetsToEditor', () => {
	it('returns no rows for a block that is not an array', () => {
		expect(widgetsToEditor(undefined)).toEqual([])
		expect(widgetsToEditor({ slot: 'x' })).toEqual([])
	})

	it('pretty-prints each config and fills defaults', () => {
		const rows = widgetsToEditor([{ slot: 'main', widget: 'stats', config: { limit: 5 } }, {}])

		expect(rows.map(({ _key, ...rest }) => rest)).toEqual([
			{ slot: 'main', widget: 'stats', configJson: '{\n  "limit": 5\n}', configError: '' },
			{ slot: '', widget: '', configJson: '{}', configError: '' },
		])
	})
})

describe('editorToWidgets', () => {
	it('serialises nothing for no rows', () => {
		expect(editorToWidgets(null)).toBeNull()
		expect(editorToWidgets([])).toBeNull()
	})

	it('drops incomplete and invalid rows and parses each config', () => {
		expect(
			editorToWidgets([
				row({ configJson: '{"limit": 5}' }),
				row({ _key: 'w2', slot: '' }),
				row({ _key: 'w3', widget: '' }),
				row({ _key: 'w4', configJson: '{', configError: 'Config must be valid JSON.' }),
				row({ _key: 'w5', configJson: '' }),
				row({ _key: 'w6', configJson: 'not json' }),
			]),
		).toEqual([
			{ slot: 'sidebar', widget: 'stats', config: { limit: 5 } },
			{ slot: 'sidebar', widget: 'stats', config: {} },
			{ slot: 'sidebar', widget: 'stats', config: {} },
		])
	})

	it('round-trips a block through the editor', () => {
		const block = [{ slot: 'main', widget: 'stats', config: { limit: 5, nested: { a: [1, 2] } } }]

		expect(editorToWidgets(widgetsToEditor(block))).toEqual(block)
	})
})
