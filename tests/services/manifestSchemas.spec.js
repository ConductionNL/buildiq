/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for manifestValidation/manifestSchemas: how a schema is named,
 * and when a manifest counts as knowing a schema — embedded as a list or a
 * map, bound by a page, or not checkable at all because the manifest names
 * no schema anywhere.
 */

import { describe, expect, it } from 'vitest'
import { schemaKnown, schemaName } from '../../src/services/manifestValidation/manifestSchemas.js'

describe('schemaName', () => {
	it('prefers the slug, then the id, then the title', () => {
		expect(schemaName({ slug: 'case', id: 'c-1', title: 'Case' })).toBe('case')
		expect(schemaName({ id: 'c-1', title: 'Case' })).toBe('c-1')
		expect(schemaName({ title: 'Case' })).toBe('Case')
		expect(schemaName({ slug: '', id: 'c-1' })).toBe('c-1')
		expect(schemaName({})).toBeUndefined()
	})
})

describe('schemaKnown', () => {
	it('knows a schema embedded in a list, by its name', () => {
		const manifest = { schemas: [{ slug: 'case' }, { id: 'note' }, { title: 'Task' }] }

		expect(schemaKnown(manifest, 'case')).toBe(true)
		expect(schemaKnown(manifest, 'note')).toBe(true)
		expect(schemaKnown(manifest, 'Task')).toBe(true)
		expect(schemaKnown(manifest, 'person')).toBe(false)
	})

	it('knows a schema embedded in a map, by its own key only', () => {
		const manifest = { schemas: { case: {} } }

		expect(schemaKnown(manifest, 'case')).toBe(true)
		expect(schemaKnown(manifest, 'person')).toBe(false)
		expect(schemaKnown(manifest, 'toString')).toBe(false)
	})

	it('knows a schema a page binds, even when the embedded schemas do not list it', () => {
		const manifest = {
			schemas: [{ slug: 'case' }],
			pages: [{ id: 'notes', config: { schema: 'note' } }, { id: 'home' }],
		}

		expect(schemaKnown(manifest, 'note')).toBe(true)
		expect(schemaKnown(manifest, 'person')).toBe(false)
	})

	it('cannot check, and so accepts, when the manifest names no schema at all', () => {
		expect(schemaKnown({}, 'anything')).toBe(true)
		expect(schemaKnown(null, 'anything')).toBe(true)
		expect(schemaKnown({ pages: [{ id: 'home', config: {} }, null] }, 'anything')).toBe(true)
	})

	it('refuses an unknown schema once any page binds one, even without embedded schemas', () => {
		const manifest = { pages: [{ id: 'cases', config: { schema: 'case' } }] }

		expect(schemaKnown(manifest, 'case')).toBe(true)
		expect(schemaKnown(manifest, 'person')).toBe(false)
	})

	it('refuses an unknown schema once schemas are embedded, even as an empty list', () => {
		expect(schemaKnown({ schemas: [] }, 'case')).toBe(false)
		expect(schemaKnown({ schemas: {} }, 'case')).toBe(false)
	})
})
