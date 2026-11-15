/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the applicationContext mixin's `obAppUuid`
 * computed: the uuid of the resolved Application, else of the object context
 * a tab or action component was handed, in the order each source is tried.
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn(), patch: vi.fn() } }))

const applicationContext = (await import('../../src/mixins/applicationContext.js')).default
const obAppUuid = (ctx) => applicationContext.computed.obAppUuid.call(ctx)

describe('applicationContext obAppUuid', () => {
	it.each([
		['the @self id', { '@self': { id: 'a', uuid: 'b' }, uuid: 'c', id: 'd' }, 'a'],
		['the @self uuid', { '@self': { uuid: 'b' }, uuid: 'c', id: 'd' }, 'b'],
		['the uuid', { '@self': {}, uuid: 'c', id: 'd' }, 'c'],
		['the id', { id: 'd' }, 'd'],
		['nothing', {}, ''],
	])('reads a resolved Application by %s', (_label, obApp, expected) => {
		expect(obAppUuid({ obApp, objectId: 'ignored' })).toBe(expected)
	})

	it.each([
		['the objectId', { objectId: 7, objectUuid: 'u', object: { uuid: 'o' } }, '7'],
		['the objectUuid', { objectId: '', objectUuid: 'u', object: { uuid: 'o' } }, 'u'],
		['the object @self id', { object: { '@self': { id: 's', uuid: 'x' }, uuid: 'o', id: 'i' } }, 's'],
		['the object uuid, never its @self uuid', { object: { '@self': { uuid: 'x' }, uuid: 'o', id: 'i' } }, 'o'],
		['the object id', { object: { id: 'i' } }, 'i'],
		['nothing', { object: null }, ''],
		['an object without ids', { object: {} }, ''],
	])('falls back to the context, by %s', (_label, context, expected) => {
		expect(obAppUuid({ obApp: null, objectId: '', objectUuid: '', object: null, ...context })).toBe(expected)
	})
})
