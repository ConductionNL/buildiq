/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the typed-action helpers (pages-action-buttons T02, T04):
 * the three legacy targets migrate as design D3 says, a type switch keeps only
 * the common keys, and an object-op on the lifecycle field is caught.
 *
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md
 */

import { describe, expect, it } from 'vitest'
import {
	isEditableType,
	lifecycleFieldPatched,
	migrateActions,
	missingFor,
	withType,
} from '../../../src/components/page-editor/fields/actionTypes.js'

describe('actionTypes', () => {
	it('migrates a manifest carrying the three legacy targets', () => {
		const result = migrateActions(
			[
				{ id: 'go', label: 'Go', target: 'navigate', route: 'requests' },
				{ id: 'away', label: 'Away', target: 'navigate', route: 'elsewhere' },
				{ id: 'tell', label: 'Tell', target: 'emit' },
				{ id: 'nothing', label: 'Nothing', target: 'none' },
				{ id: 'typed', label: 'Typed', type: 'open-page', target: 'requests' },
			],
			['requests'],
		)

		expect(result).toEqual({
			actions: [
				{ id: 'go', label: 'Go', type: 'open-page', target: 'requests' },
				{ id: 'away', label: 'Away', type: 'navigate' },
				{ id: 'tell', label: 'Tell', type: 'handler', handler: 'tell' },
				{ id: 'typed', label: 'Typed', type: 'open-page', target: 'requests' },
			],
			migrated: 4,
			dropped: 1,
		})
	})

	it('treats a non-array as no actions', () => {
		expect(migrateActions(null)).toEqual({ actions: [], migrated: 0, dropped: 0 })
	})

	it('keeps the common keys and seeds what a type needs on a switch', () => {
		const base = { id: 'a', label: 'A', icon: 'Play', visibleWhen: { field: 'status', value: 'x' }, flowId: 'f', nodeId: 'n', type: 'run-node' }

		expect(withType(base, 'object-op')).toEqual({ id: 'a', label: 'A', icon: 'Play', visibleWhen: { field: 'status', value: 'x' }, type: 'object-op', op: 'patch', values: {} })
		expect(withType(base, 'open-form')).toEqual({ id: 'a', label: 'A', icon: 'Play', visibleWhen: { field: 'status', value: 'x' }, type: 'open-form', schema: '' })
	})

	it('catches an object-op that patches a lifecycle field', () => {
		expect(lifecycleFieldPatched({ type: 'object-op', values: { title: 'x', status: 'approved' } })).toBe('status')
		expect(lifecycleFieldPatched({ type: 'object-op', values: { state: 'x' } }, ['state'])).toBe('state')
		expect(lifecycleFieldPatched({ type: 'object-op', values: { title: 'x' } })).toBeNull()
		expect(lifecycleFieldPatched({ type: 'run-node', values: { status: 'x' } })).toBeNull()
	})

	it('names what a typed action still lacks, and which types are offered', () => {
		expect(missingFor({ id: 'a' })).toBe('An action needs an id and a label.')
		expect(missingFor({ id: 'a', label: 'A', type: 'open-form' })).toBe('Pick the schema the form creates.')
		expect(missingFor({ id: 'a', label: 'A', type: 'navigate' })).toBe('Pick where the button goes.')
		expect(missingFor({ id: 'a', label: 'A', type: 'export' })).toBe('')
		expect(isEditableType('run-node')).toBe(true)
		expect(isEditableType('agent')).toBe(false)
	})
})
