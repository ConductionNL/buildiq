/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row-level tests for `LifecycleEditor.vue`, driven through the template.
 *
 * The stubs emit `update:modelValue` and `click` the way the real Nextcloud
 * components do, so each test exercises the template binding as well as the
 * method behind it: editing a state or transition field, removing rows,
 * editing and removing on-transition actions, and the helper fallbacks.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LifecycleEditor, {
	editorToLifecycle,
	lifecycleToEditor,
} from '../../../src/components/schema-editor/LifecycleEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'ariaLabel'],
		template: '<button :aria-label="ariaLabel" :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText', 'placeholder'],
		template: '<input :data-label="label" :value="modelValue" />',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'modelValue', 'options', 'clearable'],
		template: '<div class="nc-select-stub" :data-label="inputLabel" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'value', 'name'],
		template: '<label><slot /></label>',
	},
	PlusIcon: true,
	DeleteIcon: true,
}

function states () {
  return [
	{ _key: 's1', name: 'draft', label: 'Draft', initial: true },
	{ _key: 's2', name: 'published', label: 'Published', initial: false },
]
}

function transitions () {
  return [
	{
		_key: 't1',
		from: 'draft',
		to: 'published',
		label: 'Publish',
		actions: [
			{ _key: 'a1', type: 'audit-event-emit', payload: 'published' },
			{ _key: 'a2', type: 'audit-event-emit', payload: 'second' },
		],
	},
]
}

/**
 * Mount the editor with two states and one transition carrying two actions.
 *
 * @return {object} The wrapper.
 */
function mountEditor() {
	return mount(LifecycleEditor, {
		propsData: { states: states(), transitions: transitions() },
		stubs,
		mocks: { t: (app, text) => text },
	})
}

/**
 * The stub component with the given label, of the given kind.
 *
 * @param {object} wrapper Mounted wrapper.
 * @param {string} kind Component name.
 * @param {string} label Label prop value.
 * @param {number} at Which match.
 * @return {object} The component wrapper.
 */
function byLabel(wrapper, kind, label, at = 0) {
	const prop = kind === 'NcSelect' ? 'inputLabel' : (kind === 'NcButton' ? 'ariaLabel' : 'label')
	return wrapper.findAllComponents({ name: kind }).filter((c) => c.props(prop) === label).at(at)
}

describe('LifecycleEditor rows', () => {
	it('editing a state slug and label emits the updated row only', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcTextField', 'State slug', 1).vm.$emit('update:modelValue', 'live')
		byLabel(wrapper, 'NcTextField', 'Label', 0).vm.$emit('update:modelValue', 'Concept')

		const [renamed] = wrapper.emitted('update:states')[0]
		expect(renamed.map((s) => s.name)).toEqual(['draft', 'live'])
		expect(renamed[0]).toEqual(states()[0])

		const [relabelled] = wrapper.emitted('update:states')[1]
		expect(relabelled[0].label).toBe('Concept')
		expect(relabelled[1]).toEqual(states()[1])
	})

	it('the initial radio marks only the clicked state', () => {
		const wrapper = mountEditor()

		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).at(1).vm.$emit('update:modelValue', 's2')

		const [next] = wrapper.emitted('update:states')[0]
		expect(next.map((s) => s.initial)).toEqual([false, true])
	})

	it('removing a state drops exactly that row', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcButton', 'Remove state', 0).vm.$emit('click')

		const [next] = wrapper.emitted('update:states')[0]
		expect(next).toEqual([states()[1]])
	})

	it('changing from / to stores the option value, and a cleared select stores an empty string', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcSelect', 'From').vm.$emit('update:modelValue', { value: 'published', label: 'Published' })
		byLabel(wrapper, 'NcSelect', 'To').vm.$emit('update:modelValue', null)
		byLabel(wrapper, 'NcTextField', 'Label (optional)').vm.$emit('update:modelValue', 'Go live')

		const emitted = wrapper.emitted('update:transitions').map(([next]) => next[0])
		expect(emitted[0].from).toBe('published')
		expect(emitted[0].to).toBe('published')
		expect(emitted[1].to).toBe('')
		expect(emitted[1].from).toBe('draft')
		expect(emitted[2].label).toBe('Go live')
		expect(emitted[2].actions).toEqual(transitions()[0].actions)
	})

	it('removing a transition empties the list', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcButton', 'Remove transition').vm.$emit('click')

		expect(wrapper.emitted('update:transitions')[0][0]).toEqual([])
	})

	it('editing an action type and payload touches only that action', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcSelect', 'Action type', 1).vm.$emit('update:modelValue', { value: 'notification-send' })
		byLabel(wrapper, 'NcSelect', 'Action type', 0).vm.$emit('update:modelValue', null)
		byLabel(wrapper, 'NcTextField', 'Payload key (declarative)', 0).vm.$emit('update:modelValue', 'evt')

		const emitted = wrapper.emitted('update:transitions').map(([next]) => next[0].actions)
		expect(emitted[0][1].type).toBe('notification-send')
		expect(emitted[0][0]).toEqual(transitions()[0].actions[0])
		expect(emitted[1][0].type).toBe('audit-event-emit')
		expect(emitted[2][0].payload).toBe('evt')
		expect(emitted[2][1]).toEqual(transitions()[0].actions[1])
	})

	it('removing an action keeps the other and does not mutate the props', () => {
		const wrapper = mountEditor()

		byLabel(wrapper, 'NcButton', 'Remove action', 0).vm.$emit('click')

		const [next] = wrapper.emitted('update:transitions')[0]
		expect(next[0].actions).toEqual([transitions()[0].actions[1]])
		expect(wrapper.props('transitions')[0].actions).toHaveLength(2)
	})

	it('adding an action to a transition without actions starts a list', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: { states: states(), transitions: [{ _key: 't', from: 'draft', to: 'published' }] },
			stubs,
			mocks: { t: (app, text) => text },
		})

		expect(wrapper.text()).toContain('No actions on this transition.')
		wrapper.vm.addAction(0)

		const [next] = wrapper.emitted('update:transitions')[0]
		expect(next[0].actions).toHaveLength(1)
		expect(next[0].actions[0].type).toBe('audit-event-emit')
		expect(next[0].actions[0].payload).toBe('')
	})
})

describe('LifecycleEditor helpers', () => {
	it('lifecycleToEditor of nothing is an empty model', () => {
		expect(lifecycleToEditor(null)).toEqual({ states: [], transitions: [] })
		expect(lifecycleToEditor(undefined)).toEqual({ states: [], transitions: [] })
	})

	it('lifecycleToEditor reads string states, flat actions and alternative payload keys', () => {
		const model = lifecycleToEditor({
			initial: 'open',
			states: ['open', { name: 'closed' }],
			transitions: [
				{ from: 'open', actions: [{ event: 'e1' }, { type: 'webhook', url: 'u' }, { template: 'tpl' }, {}] },
			],
		})

		expect(model.states.map(({ name, label, initial }) => ({ name, label, initial }))).toEqual([
			{ name: 'open', label: 'open', initial: true },
			{ name: 'closed', label: 'closed', initial: false },
		])
		const [tr] = model.transitions
		expect(tr.to).toBe('')
		expect(tr.label).toBe('')
		expect(tr.actions.map(({ type, payload }) => ({ type, payload }))).toEqual([
			{ type: 'audit-event-emit', payload: 'e1' },
			{ type: 'webhook', payload: 'u' },
			{ type: 'audit-event-emit', payload: 'tpl' },
			{ type: 'audit-event-emit', payload: '' },
		])
	})

	it('editorToLifecycle of no states is null', () => {
		expect(editorToLifecycle([], [])).toBeNull()
		expect(editorToLifecycle(null, [])).toBeNull()
	})
})
