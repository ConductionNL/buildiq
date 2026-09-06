/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/services/blockInsert.js`.
 *
 * Covers the MANDATORY insert/id-mint/remap logic (tasks.md 7.1):
 *   - insertBlock deep-copies a fragment and mints fresh widget ids.
 *   - Inserting the same block twice never collides on id.
 *   - computeSchemaMismatches drives the remap-dialog trigger condition.
 *   - Unresolved dependencies mark a visible placeholder, never a silent drop.
 *   - Editing the source block after insert never affects an inserted copy
 *     (insert reads the fragment once; deep-clone breaks any live link).
 */

import { describe, expect, it } from 'vitest'
import { buildSectionFragment } from '../../src/services/blockCapture.js'
import {
	computeSchemaMismatches,
	fragmentWidgets,
	insertBlock,
	markUnresolvedRefs,
	mintWidgetId,
	remapBlockRecord,
	UNRESOLVED_SCHEMA_PLACEHOLDER,
} from '../../src/services/blockInsert.js'

const singleWidgetBlock = {
	schemaDependencies: ['permit-application'],
	fragment: {
		id: 'status-badge',
		widgetKey: 'status-badge',
		slot: 'body',
		gridX: 0,
		gridY: 0,
		gridWidth: 4,
		gridHeight: 2,
		dataSource: { schema: 'permit-application' },
	},
}

describe('blockInsert — fragmentWidgets', () => {
	it('wraps a single-widget fragment as a one-item list', () => {
		expect(fragmentWidgets(singleWidgetBlock.fragment)).toEqual([
			singleWidgetBlock.fragment,
		])
	})

	it('unwraps a section fragment into its widgets', () => {
		const section = buildSectionFragment('s', [{ id: 'a' }, { id: 'b' }])
		expect(fragmentWidgets(section)).toEqual([{ id: 'a' }, { id: 'b' }])
	})

	it('returns an empty array for a null/undefined fragment', () => {
		expect(fragmentWidgets(null)).toEqual([])
	})
})

describe('blockInsert — computeSchemaMismatches (remap trigger condition)', () => {
	it('returns empty when every dependency exact-matches a target schema', () => {
		expect(
			computeSchemaMismatches(
				['permit-application'],
				['permit-application', 'other'],
			),
		).toEqual([])
	})

	it('returns the mismatched dependency when no target schema matches', () => {
		expect(computeSchemaMismatches(['permit-application'], ['other'])).toEqual([
			'permit-application',
		])
	})

	it('treats an empty schemaDependencies list as no mismatch', () => {
		expect(computeSchemaMismatches([], ['other'])).toEqual([])
	})
})

describe('blockInsert — mintWidgetId', () => {
	it('mints a kebab-case id derived from the base id', () => {
		const ids = new Set()
		const id = mintWidgetId('status-badge', ids)
		expect(id).toMatch(/^status-badge-[a-z0-9]+$/)
	})

	it('never collides against the provided existing-id set, even across repeated calls', () => {
		const ids = new Set(['status-badge'])
		const first = mintWidgetId('status-badge', ids)
		const second = mintWidgetId('status-badge', ids)
		expect(first).not.toBe('status-badge')
		expect(second).not.toBe('status-badge')
		expect(first).not.toBe(second)
		expect(ids.has(first)).toBe(true)
		expect(ids.has(second)).toBe(true)
	})

	it('falls back to a generic base when given no id', () => {
		expect(mintWidgetId('', new Set())).toMatch(/^widget-[a-z0-9]+$/)
	})
})

describe('blockInsert — insertBlock (deep-copy + id-mint)', () => {
	it("mints a fresh id and never reuses the fragment's original id", () => {
		const [widget] = insertBlock(singleWidgetBlock, { targetWidgets: [] })
		expect(widget.id).not.toBe('status-badge')
		expect(widget.widgetKey).toBe('status-badge')
	})

	it('inserting the same block twice does not collide (REQ)', () => {
		const first = insertBlock(singleWidgetBlock, { targetWidgets: [] })
		const second = insertBlock(singleWidgetBlock, { targetWidgets: first })
		expect(first[0].id).not.toBe(second[0].id)
	})

	it('editing the source block after insert does not affect an inserted copy (deep clone, no live link)', () => {
		// A private copy, mutated below — never the shared fixture, so this
		// test cannot leak state into the others in this file.
		const sourceCopy = JSON.parse(JSON.stringify(singleWidgetBlock))
		const [inserted] = insertBlock(sourceCopy, { targetWidgets: [] })
		sourceCopy.fragment.dataSource.schema = 'mutated-after-insert'
		expect(inserted.dataSource.schema).toBe('permit-application')
	})

	it('remaps schema references per the resolved remapMap', () => {
		const [widget] = insertBlock(singleWidgetBlock, {
			remapMap: { 'permit-application': 'vergunning' },
			targetWidgets: [],
		})
		expect(widget.dataSource.schema).toBe('vergunning')
	})

	it('leaves an already-matching reference untouched when no remap is supplied (no-prompt scenario)', () => {
		const [widget] = insertBlock(singleWidgetBlock, { targetWidgets: [] })
		expect(widget.dataSource.schema).toBe('permit-application')
	})

	it('marks an unresolved dependency with a visible placeholder, never a silent drop', () => {
		const [widget] = insertBlock(singleWidgetBlock, {
			unresolvedDependencies: ['permit-application'],
			targetWidgets: [],
		})
		expect(widget.dataSource.schema).toBe(UNRESOLVED_SCHEMA_PLACEHOLDER)
		expect(widget.dataSource.needsRemap).toBe(true)
		// The field itself is still present — never silently omitted.
		expect(widget.dataSource).toHaveProperty('schema')
	})

	it('inserts every widget of a section fragment, each with its own fresh id', () => {
		const section = {
			schemaDependencies: [],
			fragment: buildSectionFragment('section', [
				{ id: 'a', widgetKey: 'field-display' },
				{ id: 'b', widgetKey: 'status-badge' },
			]),
		}
		const widgets = insertBlock(section, { targetWidgets: [] })
		expect(widgets).toHaveLength(2)
		expect(new Set(widgets.map((w) => w.id)).size).toBe(2)
		expect(widgets.map((w) => w.widgetKey)).toEqual([
			'field-display',
			'status-badge',
		])
	})
})

describe('blockInsert — markUnresolvedRefs', () => {
	it('returns the node unchanged when there is nothing to mark', () => {
		const node = { schema: 'x' }
		expect(markUnresolvedRefs(node, [])).toBe(node)
	})

	it('recurses into nested arrays and objects', () => {
		const node = { widgets: [{ dataSource: { relatedSchema: 'x' } }] }
		const marked = markUnresolvedRefs(node, ['x'])
		expect(marked.widgets[0].dataSource.relatedSchema).toBe(
			UNRESOLVED_SCHEMA_PLACEHOLDER,
		)
		expect(marked.widgets[0].dataSource.needsRemap).toBe(true)
	})
})

describe('blockInsert — remapBlockRecord (import-time remap finalisation)', () => {
	it('rewrites the fragment and updates schemaDependencies to the resolved target slugs', () => {
		const record = remapBlockRecord(
			singleWidgetBlock,
			{ 'permit-application': 'vergunning' },
			[],
		)
		expect(record.fragment.dataSource.schema).toBe('vergunning')
		expect(record.schemaDependencies).toEqual(['vergunning'])
	})

	it('keeps an unresolved dependency slug and marks the fragment placeholder', () => {
		const record = remapBlockRecord(singleWidgetBlock, {}, [
			'permit-application',
		])
		expect(record.fragment.dataSource.schema).toBe(UNRESOLVED_SCHEMA_PLACEHOLDER)
		expect(record.schemaDependencies).toEqual(['permit-application'])
	})

	it('does not mutate the source record', () => {
		const copy = JSON.parse(JSON.stringify(singleWidgetBlock))
		remapBlockRecord(
			singleWidgetBlock,
			{ 'permit-application': 'vergunning' },
			[],
		)
		expect(singleWidgetBlock).toEqual(copy)
	})
})
