/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the applicationContext mixin's `obLoadApp`: the
 * object-prop shortcut, the forced refetch, the missing-uuid refusal, each
 * answer shape the shared fetch can return.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { fetchMock } = vi.hoisted(() => ({ fetchMock: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), put: vi.fn() } }))
vi.mock('../../src/composables/useApplicationRecord.js', () => ({
	fetchApplicationRecord: (...a) => fetchMock(...a),
}))

beforeAll(() => {
	globalThis.t = (_app, key) => key
})

const applicationContext = (await import('../../src/mixins/applicationContext.js')).default

/**
 * A component context for the mixin's method, with obAppUuid computed live.
 *
 * @param {object} props The prop values.
 * @return {object} The context.
 */
function context(props) {
	const ctx = { obApp: null, obAppError: '', obAppLoading: false, objectId: '', objectUuid: '', object: null, ...props }
	Object.defineProperty(ctx, 'obAppUuid', { get: () => applicationContext.computed.obAppUuid.call(ctx) })
	return ctx
}

const load = (ctx, force) => applicationContext.methods.obLoadApp.call(ctx, force)

describe('applicationContext obLoadApp', () => {
	beforeEach(() => fetchMock.mockReset())

	it.each([
		['a manifest', { manifest: {}, uuid: 'u' }],
		['a slug', { slug: 's', uuid: 'u' }],
		['a null manifest', { manifest: null }],
	])('uses an object prop carrying %s as-is', async (_label, object) => {
		const ctx = context({ object })
		await load(ctx)
		expect(ctx.obApp).toBe(object)
		expect(fetchMock).not.toHaveBeenCalled()
	})

	it('refetches by uuid when forced, even with a usable object prop', async () => {
		const record = { '@self': { id: 'u' }, slug: 's' }
		fetchMock.mockResolvedValue(record)
		const ctx = context({ object: { slug: 's', uuid: 'u' } })
		await load(ctx, true)
		expect(fetchMock).toHaveBeenCalledWith('u')
		expect(ctx.obApp).toBe(record)
		expect(ctx.obAppLoading).toBe(false)
	})

	it('fetches when the object prop carries neither manifest nor slug', async () => {
		fetchMock.mockResolvedValue({ results: { slug: 'r' } })
		const ctx = context({ object: { uuid: 'u2' } })
		await load(ctx)
		expect(fetchMock).toHaveBeenCalledWith('u2')
		expect(ctx.obApp).toEqual({ slug: 'r' })
	})

	it.each([
		['a plain record', { slug: 'p' }, { slug: 'p' }],
		['nothing', null, null],
	])('keeps %s as it is', async (_label, answer, expected) => {
		fetchMock.mockResolvedValue(answer)
		const ctx = context({ objectId: 'id-1' })
		await load(ctx)
		expect(ctx.obApp).toEqual(expected)
	})

	it('says no application is selected without a uuid, and fetches nothing', async () => {
		const ctx = context({})
		await load(ctx)
		expect(ctx.obAppError).toBe('No application selected.')
		expect(ctx.obAppLoading).toBe(false)
		expect(fetchMock).not.toHaveBeenCalled()
	})
})
