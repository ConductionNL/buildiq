/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden tests for the pure parse/compile pair of `AccessEditor.vue`: every
 * branch of `accessToEditor` (per-operation row kinds, condition validation,
 * extra keys) and of `editorToAccess` (each row kind, raw preservation, the
 * empty result). These pin which operations end up restricted and which stay
 * open, so the authored access rules cannot drift.
 */

import { describe, expect, it } from 'vitest'
import {
	accessToEditor,
	editorToAccess,
} from '../../../src/components/schema-editor/AccessEditor.vue'

/**
 * The row of one operation after parsing an authorization block.
 *
 * @param {object} auth The authorization block.
 * @param {string} op The operation.
 * @return {object}
 */
function rowOf(auth, op) {
	return accessToEditor(auth).rows.find((row) => row.op === op)
}

describe('accessToEditor rows', () => {
	it.each([null, undefined, 'text', 3])('treats %j as an empty block', (auth) => {
		expect(accessToEditor(auth)).toEqual({
			rows: [
				{ op: 'read', kind: 'everyone' },
				{ op: 'create', kind: 'everyone' },
				{ op: 'update', kind: 'everyone' },
				{ op: 'delete', kind: 'everyone' },
			],
			extraKeys: {},
		})
	})

	it.each([
		[['@creator'], { op: 'read', kind: 'own' }],
		[['vets', 'admin'], { op: 'read', kind: 'group', groups: ['vets', 'admin'] }],
		[['@creator', 'vets'], { op: 'read', kind: 'unrepresentable', raw: ['@creator', 'vets'] }],
		[['@other'], { op: 'read', kind: 'unrepresentable', raw: ['@other'] }],
		[['vets', 3], { op: 'read', kind: 'unrepresentable', raw: ['vets', 3] }],
		[[], { op: 'read', kind: 'unrepresentable', raw: [] }],
		['vets', { op: 'read', kind: 'unrepresentable', raw: 'vets' }],
		[null, { op: 'read', kind: 'unrepresentable', raw: null }],
	])('parses read = %j', (list, row) => {
		expect(rowOf({ read: list }, 'read')).toEqual(row)
	})

	it('copies the group list instead of sharing it', () => {
		const list = ['vets']
		const row = rowOf({ read: list }, 'read')
		expect(row.groups).not.toBe(list)
	})

	it('parses a valid condition', () => {
		const condition = { field: 'assignee', operator: 'equals', value: '$userId', extra: 1 }
		expect(rowOf({ update: [], conditions: { update: condition } }, 'update')).toEqual({
			op: 'update',
			kind: 'condition',
			condition: { field: 'assignee', operator: 'equals', value: '$userId' },
		})
	})

	it.each([
		[null],
		['text'],
		[{ field: '', operator: 'equals', value: 'x' }],
		[{ field: 3, operator: 'equals', value: 'x' }],
		[{ field: 'a', operator: 'in', value: 'x' }],
		[{ field: 'a', operator: 'equals', value: 3 }],
		[{ operator: 'equals', value: 'x' }],
	])('keeps the malformed condition %j verbatim', (condition) => {
		expect(rowOf({ read: ['x'], conditions: { read: condition } }, 'read')).toEqual({
			op: 'read',
			kind: 'unrepresentable',
			raw: { read: ['x'], conditions: condition },
		})
	})

	it('reads the list when the conditions block lacks the operation', () => {
		expect(rowOf({ read: ['@creator'], conditions: { update: {} } }, 'read')).toEqual({
			op: 'read',
			kind: 'own',
		})
	})

	it.each([[null], [0], [false]])('treats conditions = %j as a malformed condition', (conditions) => {
		expect(rowOf({ read: ['x'], conditions }, 'read')).toEqual({
			op: 'read',
			kind: 'unrepresentable',
			raw: { read: ['x'], conditions },
		})
	})

	it('surfaces only the unrelated top-level keys', () => {
		expect(
			accessToEditor({ _note: 'n', read: ['x'], conditions: {}, z: 1 }).extraKeys,
		).toEqual({ _note: 'n', z: 1 })
	})
})

describe('editorToAccess', () => {
	it.each([null, undefined, {}, { rows: 'x' }])('compiles %j to null', (access) => {
		expect(editorToAccess(access, null)).toBeNull()
	})

	it('keeps the unrelated keys of a non-object raw block out', () => {
		expect(editorToAccess({ rows: [] }, 'text')).toBeNull()
	})

	it('compiles every kind, with keys in row order and conditions last', () => {
		const result = editorToAccess(
			{
				rows: [
					{ op: 'read', kind: 'group', groups: ['vets', '', null, 'admin'] },
					{ op: 'create', kind: 'everyone', groups: ['ignored'] },
					{ op: 'update', kind: 'own' },
					{ op: 'delete', kind: 'condition', condition: { field: 'a', value: 'b' } },
				],
			},
			{ _note: 'n', read: ['old'], create: ['old'], conditions: { read: {} } },
		)
		expect(result).toEqual({
			_note: 'n',
			read: ['vets', 'admin'],
			update: ['@creator'],
			delete: [],
			conditions: { delete: { field: 'a', operator: 'equals', value: 'b' } },
		})
		expect(Object.keys(result)).toEqual(['_note', 'read', 'update', 'delete', 'conditions'])
	})

	it.each([
		[{ op: 'read', kind: 'group', groups: [] }],
		[{ op: 'read', kind: 'group', groups: ['', null] }],
		[{ op: 'read', kind: 'group', groups: 'vets' }],
		[{ op: 'read', kind: 'group' }],
		[{ op: 'read', kind: 'mystery' }],
		[{ op: 'read', kind: 'toString' }],
	])('leaves read open for %j', (row) => {
		expect(editorToAccess({ rows: [row] }, null)).toBeNull()
	})

	it('fills a condition without a field or value with empty strings', () => {
		expect(
			editorToAccess({ rows: [{ op: 'read', kind: 'condition' }] }, null),
		).toEqual({
			read: [],
			conditions: { read: { field: '', operator: 'equals', value: '' } },
		})
	})

	it('restores an unrepresentable row from the raw block', () => {
		const raw = {
			read: ['@creator', 'vets'],
			conditions: { read: { field: 'x' }, update: { field: 'y' } },
		}
		expect(
			editorToAccess(
				{
					rows: [
						{ op: 'read', kind: 'unrepresentable' },
						{ op: 'update', kind: 'unrepresentable' },
						{ op: 'delete', kind: 'unrepresentable' },
					],
				},
				raw,
			),
		).toEqual({
			read: ['@creator', 'vets'],
			conditions: { read: { field: 'x' }, update: { field: 'y' } },
		})
	})

	it('restores an unrepresentable key that holds undefined', () => {
		const result = editorToAccess(
			{ rows: [{ op: 'read', kind: 'unrepresentable' }] },
			{ read: undefined },
		)
		expect(Object.hasOwn(result, 'read')).toBe(true)
	})

	it('skips a raw conditions block that is not an object', () => {
		expect(
			editorToAccess(
				{ rows: [{ op: 'read', kind: 'unrepresentable' }] },
				{ read: ['x'], conditions: null },
			),
		).toEqual({ read: ['x'] })
	})
})
