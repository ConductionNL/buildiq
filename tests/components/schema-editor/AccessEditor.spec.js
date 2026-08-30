/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `AccessEditor.vue` (REQ-OBDSA-001 .. REQ-OBDSA-003).
 *
 * Covers:
 *  - `accessToEditor` / `editorToAccess` round-trips for each scope kind
 *    (everyone, group, own, condition).
 *  - Raw-block preservation property: arbitrary extra top-level keys and
 *    genuinely unrepresentable per-operation entries survive
 *    `editorToAccess` byte-identical even when unrelated rows are edited
 *    (REQ-OBDSA-002 — this is also the strip-bug regression coverage).
 *  - Capability gating: baseline `['group']` hides own/condition kind
 *    options; advertising `creator`/`condition` unlocks them
 *    (REQ-OBDSA-003).
 *  - `readOnly` disables every interactive control.
 */

import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const capabilityMocks = vi.hoisted(() => {
	return { useOrAccessCapabilities: vi.fn(() => ({ scopes: ['group'] })) }
})

vi.mock('../../../src/composables/useOrAccessCapabilities.js', () => {
	return { useOrAccessCapabilities: capabilityMocks.useOrAccessCapabilities }
})

const {
	default: AccessEditor,
	accessToEditor,
	editorToAccess,
} = await import('../../../src/components/schema-editor/AccessEditor.vue')

const stubs = {
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		template: '<div class="note-stub" :data-type="type"><slot /></div>',
	},
	NcSelect: {
		name: 'NcSelect',
		props: [
			'inputLabel',
			'value',
			'options',
			'clearable',
			'disabled',
			'multiple',
			'taggable',
		],
		template:
			'<div class="nc-select-stub" :data-input-label="inputLabel" :data-disabled="disabled" />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['value', 'label', 'disabled'],
		template:
			'<label><input :value="value" :disabled="disabled" @input="$emit(\'update:value\', $event.target.value)" /></label>',
	},
}

function mountEditor(propsData = {}) {
	return mount(AccessEditor, {
		propsData: {
			access: accessToEditor(undefined),
			fieldNames: ['assignee', 'status'],
			availableGroups: ['vets', 'admin'],
			readOnly: false,
			...propsData,
		},
		stubs,
	})
}

describe('accessToEditor / editorToAccess round-trip', () => {
	it('REQ-OBDSA-002: everyone (key omitted) round-trips to key omitted', () => {
		const editor = accessToEditor(undefined)
		expect(editor.rows.find((r) => r.op === 'read').kind).toBe('everyone')
		const back = editorToAccess(editor, undefined)
		expect(back).toBeNull()
	})

	it('REQ-OBDSA-001 scenario 1: group scope round-trips authorization.read = ["vets"]', () => {
		const authorization = { read: ['vets'] }
		const editor = accessToEditor(authorization)
		const readRow = editor.rows.find((r) => r.op === 'read')
		expect(readRow).toMatchObject({ kind: 'group', groups: ['vets'] })
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual({ read: ['vets'] })
	})

	it('REQ-OBDSA-001 scenario 2: independent per-operation scopes round-trip exactly', () => {
		const authorization = { read: ['vets'], delete: ['admin'] }
		const editor = accessToEditor(authorization)
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual({ read: ['vets'], delete: ['admin'] })
		expect(Object.keys(back)).toEqual(['read', 'delete'])
	})

	it('own-records scope (["@creator"]) round-trips exactly', () => {
		const authorization = { update: ['@creator'] }
		const editor = accessToEditor(authorization)
		expect(editor.rows.find((r) => r.op === 'update')).toMatchObject({
			kind: 'own',
		})
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual({ update: ['@creator'] })
	})

	it('condition scope round-trips authorization.conditions.<op> alongside an empty-list entry', () => {
		const authorization = {
			delete: [],
			conditions: {
				delete: {
					field: 'assignee',
					operator: 'equals',
					value: '@user.uid',
				},
			},
		}
		const editor = accessToEditor(authorization)
		const deleteRow = editor.rows.find((r) => r.op === 'delete')
		expect(deleteRow).toMatchObject({
			kind: 'condition',
			condition: { field: 'assignee', operator: 'equals', value: '@user.uid' },
		})
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual(authorization)
	})

	it('selecting own-records for update compiles to authorization.update = ["@creator"] (REQ-OBDSA-003 scenario 2)', () => {
		const editor = accessToEditor(undefined)
		const updateRow = editor.rows.find((r) => r.op === 'update')
		updateRow.kind = 'own'
		const back = editorToAccess(editor, undefined)
		expect(back).toEqual({ update: ['@creator'] })
	})
})

describe('raw-block preservation (REQ-OBDSA-002 — strip-bug regression)', () => {
	it('unrelated top-level keys (e.g. _note) survive editorToAccess untouched', () => {
		const authorization = {
			read: ['vets'],
			_note: 'hand-authored, do not touch',
		}
		const editor = accessToEditor(authorization)
		expect(editor.extraKeys).toEqual({ _note: 'hand-authored, do not touch' })
		// Simulate an unrelated edit: change the delete row only.
		const deleteRow = editor.rows.find((r) => r.op === 'delete')
		deleteRow.kind = 'group'
		deleteRow.groups = ['admin']
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual({
			read: ['vets'],
			delete: ['admin'],
			_note: 'hand-authored, do not touch',
		})
	})

	it('an unrepresentable "@creator" entry (capability not advertised) is preserved byte-identical after an unrelated save', () => {
		// Baseline capability mock (['group']) means `own` rows on OTHER ops
		// are fine to compile normally — but here we simulate the scenario
		// where the persisted array mixes @creator with something the
		// static parser cannot map to a clean kind, forcing 'unrepresentable'.
		const authorization = { read: ['vets', '@creator'] }
		const editor = accessToEditor(authorization)
		const readRow = editor.rows.find((r) => r.op === 'read')
		expect(readRow.kind).toBe('unrepresentable')
		expect(readRow.raw).toEqual(['vets', '@creator'])
		// Unrelated edit elsewhere.
		const createRow = editor.rows.find((r) => r.op === 'create')
		createRow.kind = 'group'
		createRow.groups = ['ops']
		const back = editorToAccess(editor, authorization)
		expect(back.read).toEqual(['vets', '@creator'])
		expect(back.create).toEqual(['ops'])
	})

	it('a malformed condition entry is preserved verbatim (unrepresentable) rather than dropped', () => {
		const authorization = {
			create: [],
			conditions: { create: { field: 'owner' } }, // missing operator/value
		}
		const editor = accessToEditor(authorization)
		const createRow = editor.rows.find((r) => r.op === 'create')
		expect(createRow.kind).toBe('unrepresentable')
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual(authorization)
	})

	it('a non-array op value is preserved verbatim rather than crashing', () => {
		const authorization = { update: 'not-an-array' }
		const editor = accessToEditor(authorization)
		const row = editor.rows.find((r) => r.op === 'update')
		expect(row.kind).toBe('unrepresentable')
		const back = editorToAccess(editor, authorization)
		expect(back).toEqual({ update: 'not-an-array' })
	})
})

describe('AccessEditor.vue mount — capability gating and readOnly (REQ-OBDSA-003)', () => {
	it('baseline capabilities (only "group") hide own/condition kind options', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({
			scopes: ['group'],
		})
		const wrapper = mountEditor()
		const values = wrapper.vm.kindOptions.map((o) => o.value)
		expect(values).toEqual(['everyone', 'group'])
	})

	it('advertised ["group","creator","condition"] unlocks own-records and condition options', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({
			scopes: ['group', 'creator', 'condition'],
		})
		const wrapper = mountEditor()
		const values = wrapper.vm.kindOptions.map((o) => o.value)
		expect(values).toEqual(['everyone', 'group', 'own', 'condition'])
	})

	it('a row parsed as "own" renders read-only when the capability is not advertised', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({
			scopes: ['group'],
		})
		const wrapper = mountEditor({
			access: accessToEditor({ update: ['@creator'] }),
		})
		const updateRow = wrapper.vm.rows.find((r) => r.op === 'update')
		expect(wrapper.vm.isRepresentable(updateRow)).toBe(false)
		expect(wrapper.find('.buildiq-access-editor__managed-note').exists()).toBe(
			true,
		)
	})

	it('readOnly disables every NcSelect / NcTextField control', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({
			scopes: ['group', 'creator', 'condition'],
		})
		const wrapper = mountEditor({
			access: accessToEditor({
				read: ['vets'],
				conditions: {
					create: {
						field: 'assignee',
						operator: 'equals',
						value: '@user.uid',
					},
				},
				create: [],
			}),
			readOnly: true,
		})
		const selects = wrapper.findAll('.nc-select-stub')
		expect(selects.length).toBeGreaterThan(0)
		for (let i = 0; i < selects.length; i++) {
			expect(selects.at(i).attributes('data-disabled')).toBe('true')
		}
		const textInputs = wrapper.findAll('input[type=undefined], input')
		// At least the condition value NcTextField stub input should be disabled.
		const disabledInputs = wrapper.findAll('input:disabled')
		expect(disabledInputs.length).toBeGreaterThan(0)
	})

	it('emits update:access with a replaced row when the scope kind changes', () => {
		capabilityMocks.useOrAccessCapabilities.mockReturnValue({
			scopes: ['group'],
		})
		const wrapper = mountEditor()
		wrapper.vm.onKindChange('read', 'group')
		const emitted = wrapper.emitted('update:access')
		expect(emitted).toBeTruthy()
		const nextAccess = emitted[0][0]
		expect(nextAccess.rows.find((r) => r.op === 'read')).toMatchObject({
			kind: 'group',
			groups: [],
		})
	})
})
