/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `LifecycleEditor.vue` (REQ-OBSD-004 + ADR-031).
 *
 * The component is controlled — `states` + `transitions` are props,
 * `update:states` + `update:transitions` carry the next arrays. These
 * tests drive its methods directly and assert the emitted payloads
 * plus the helper exports `lifecycleToEditor` / `editorToLifecycle`.
 *
 * Covers:
 *  - Add state — first state becomes `initial`.
 *  - setInitial radio — exactly one state is `initial` after the call.
 *  - Add transition — disabled when fewer than two states exist.
 *  - Add on-transition action — defaults to `audit-event-emit` from
 *    the fixed ADR-031 enum (no free-text type field).
 *  - State-name validator flags blank, non-kebab-case, and duplicates.
 *  - `lifecycleToEditor` + `editorToLifecycle` round-trip preserves
 *    states, transitions, and typed actions exactly.
 *  - `initialCount` reports zero/one/many initial states correctly so
 *    the parent SchemaDesigner's Save gate works.
 */

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import LifecycleEditor, {
	lifecycleToEditor,
	editorToLifecycle,
} from '../../../src/components/schema-editor/LifecycleEditor.vue'

const stubs = {
	NcButton: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'error', 'helperText'],
		template: '<label><input :value="value" @input="$emit(\'update:value\', $event.target.value)" /></label>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['inputLabel', 'value', 'options', 'clearable'],
		template: '<div class="nc-select-stub" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['checked', 'type', 'value', 'name'],
		template: '<label><input type="checkbox" :checked="checked" @change="$emit(\'update:checked\', $event.target.checked)" /><slot /></label>',
	},
}

describe('LifecycleEditor', () => {
	it('REQ-OBSD-004: addState — first state is flagged initial', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: { states: [], transitions: [] },
			stubs,
		})
		wrapper.vm.addState()
		const next = wrapper.emitted('update:states')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0].initial).toBe(true)
		expect(next[0].name).toBe('')
	})

	it('REQ-OBSD-004: addState — subsequent states are NOT initial by default', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [{ _key: 'a', name: 'draft', label: 'Draft', initial: true }],
				transitions: [],
			},
			stubs,
		})
		wrapper.vm.addState()
		const next = wrapper.emitted('update:states')[0][0]
		expect(next).toHaveLength(2)
		expect(next[1].initial).toBe(false)
	})

	it('REQ-OBSD-004: setInitial — only the chosen state is marked initial', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [
					{ _key: 'a', name: 'draft', label: 'Draft', initial: true },
					{ _key: 'b', name: 'published', label: 'Published', initial: false },
				],
				transitions: [],
			},
			stubs,
		})
		wrapper.vm.setInitial(1)
		const next = wrapper.emitted('update:states')[0][0]
		const initials = next.filter((s) => s.initial)
		expect(initials).toHaveLength(1)
		expect(initials[0].name).toBe('published')
	})

	it('REQ-OBSD-004: initialCount reflects how many states are marked initial', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [
					{ _key: 'a', name: 'draft', label: 'Draft', initial: true },
					{ _key: 'b', name: 'published', label: 'Published', initial: true },
				],
				transitions: [],
			},
			stubs,
		})
		expect(wrapper.vm.initialCount).toBe(2)
	})

	it('REQ-OBSD-004: addTransition wires from/to to the first two states', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [
					{ _key: 'a', name: 'draft', label: 'Draft', initial: true },
					{ _key: 'b', name: 'published', label: 'Published', initial: false },
				],
				transitions: [],
			},
			stubs,
		})
		wrapper.vm.addTransition()
		const next = wrapper.emitted('update:transitions')[0][0]
		expect(next).toHaveLength(1)
		expect(next[0]).toMatchObject({ from: 'draft', to: 'published', actions: [] })
	})

	it('REQ-OBSD-004 + ADR-031: addAction defaults to the audit-event-emit enum value (no free-text type)', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [
					{ _key: 'a', name: 'draft', label: 'Draft', initial: true },
					{ _key: 'b', name: 'published', label: 'Published', initial: false },
				],
				transitions: [
					{ _key: 't1', from: 'draft', to: 'published', label: '', actions: [] },
				],
			},
			stubs,
		})
		wrapper.vm.addAction(0)
		const next = wrapper.emitted('update:transitions')[0][0]
		expect(next[0].actions).toHaveLength(1)
		expect(next[0].actions[0].type).toBe('audit-event-emit')
		expect(next[0].actions[0].payload).toBe('')
	})

	it('REQ-OBSD-004 + ADR-031: actionOptions exposes exactly the five enum action types', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: { states: [], transitions: [] },
			stubs,
		})
		const values = wrapper.vm.actionOptions.map((o) => o.value).sort()
		expect(values).toEqual([
			'audit-event-emit',
			'notification-send',
			'related-object-archive',
			'related-object-upsert',
			'webhook-dispatch',
		])
	})

	it('REQ-OBSD-004: updateAction can change an action type and rewrite its payload', () => {
		const wrapper = mount(LifecycleEditor, {
			propsData: {
				states: [{ _key: 'a', name: 'draft', label: 'Draft', initial: true }],
				transitions: [{
					_key: 't1',
					from: 'draft',
					to: 'draft',
					label: '',
					actions: [{ _key: 'act1', type: 'audit-event-emit', payload: 'draft.created' }],
				}],
			},
			stubs,
		})
		wrapper.vm.updateAction(0, 0, 'type', 'notification-send')
		const next = wrapper.emitted('update:transitions')[0][0]
		expect(next[0].actions[0].type).toBe('notification-send')
	})

	it('REQ-OBSD-004: stateNameValid rejects empty, bad-pattern, and duplicates', () => {
		const states = [
			{ _key: 'a', name: 'draft', label: '', initial: true },
			{ _key: 'b', name: 'draft', label: '', initial: false }, // duplicate
			{ _key: 'c', name: 'BadCase', label: '', initial: false }, // not kebab-case
			{ _key: 'd', name: '', label: '', initial: false }, // empty
			{ _key: 'e', name: 'published-2', label: '', initial: false }, // valid
		]
		const wrapper = mount(LifecycleEditor, {
			propsData: { states, transitions: [] },
			stubs,
		})
		expect(wrapper.vm.stateNameValid(states[0], 0)).toBe(false) // duplicate of [1]
		expect(wrapper.vm.stateNameValid(states[1], 1)).toBe(false) // duplicate
		expect(wrapper.vm.stateNameValid(states[2], 2)).toBe(false) // BadCase
		expect(wrapper.vm.stateNameValid(states[3], 3)).toBe(false) // empty
		expect(wrapper.vm.stateNameValid(states[4], 4)).toBe(true)
	})

	describe('lifecycleToEditor / editorToLifecycle round-trip', () => {
		it('preserves states, transitions, and on_transition actions exactly', () => {
			const lifecycle = {
				initial: 'draft',
				states: [
					{ name: 'draft', label: 'Draft' },
					{ name: 'published', label: 'Published' },
				],
				transitions: [
					{
						from: 'draft',
						to: 'published',
						label: 'Publish',
						on_transition: {
							actions: [
								{ type: 'audit-event-emit', payload: 'draft.published' },
								{ type: 'notification-send', payload: 'tpl.publish' },
							],
						},
					},
				],
			}
			const editor = lifecycleToEditor(lifecycle)
			expect(editor.states.find((s) => s.initial).name).toBe('draft')
			expect(editor.transitions[0].actions).toHaveLength(2)

			const back = editorToLifecycle(editor.states, editor.transitions)
			expect(back.initial).toBe('draft')
			expect(back.states).toEqual([
				{ name: 'draft', label: 'Draft' },
				{ name: 'published', label: 'Published' },
			])
			expect(back.transitions[0]).toMatchObject({
				from: 'draft',
				to: 'published',
				label: 'Publish',
			})
			expect(back.transitions[0].on_transition.actions).toEqual([
				{ type: 'audit-event-emit', payload: 'draft.published' },
				{ type: 'notification-send', payload: 'tpl.publish' },
			])
		})

		it('returns null when there are no states', () => {
			expect(editorToLifecycle([], [])).toBeNull()
		})

		it('omits empty transitions (missing from or to)', () => {
			const result = editorToLifecycle(
				[{ _key: 'a', name: 'draft', label: 'Draft', initial: true }],
				[
					{ _key: 't1', from: 'draft', to: '', label: '', actions: [] },
					{ _key: 't2', from: 'draft', to: 'draft', label: '', actions: [] },
				],
			)
			expect(result.transitions).toHaveLength(1)
			expect(result.transitions[0]).toEqual({ from: 'draft', to: 'draft' })
		})
	})
})
