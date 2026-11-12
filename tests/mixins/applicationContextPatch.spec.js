/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the applicationContext mixin's `obPatchApp`: no
 * write without a uuid or a loaded record, the merged PUT body, and each
 * answer shape it adopts (wrapped, bare with @self, anything else falls back
 * to the local merge).
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

beforeAll(() => {
	globalThis.t = (_app, key) => key
})

const applicationContext = (await import('../../src/mixins/applicationContext.js')).default

const URL_BASE = '/apps/openregister/api/objects/buildiq/built-app'

function context(props) {
	const ctx = { obApp: null, objectId: '', objectUuid: '', object: null, ...props }
	Object.defineProperty(ctx, 'obAppUuid', { get: () => applicationContext.computed.obAppUuid.call(ctx) })
	return ctx
}

const patch = (ctx, p) => applicationContext.methods.obPatchApp.call(ctx, p)

describe('applicationContext obPatchApp', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it('writes nothing without a loaded application', async () => {
		const ctx = context({ objectId: 'u-1' })
		await patch(ctx, { status: 'draft' })
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(ctx.obApp).toBeNull()
	})

	it('writes nothing when the loaded application has no uuid', async () => {
		const ctx = context({ obApp: { slug: 'x' } })
		await patch(ctx, { status: 'draft' })
		expect(axiosMock.put).not.toHaveBeenCalled()
	})

	it('PUTs the record merged with the patch to its uuid', async () => {
		axiosMock.put.mockResolvedValue({ data: null })
		const ctx = context({ obApp: { '@self': { id: 'u-1' }, slug: 'x', status: 'published' } })
		await patch(ctx, { status: 'draft' })
		expect(axiosMock.put).toHaveBeenCalledWith(`${URL_BASE}/u-1`, {
			'@self': { id: 'u-1' },
			slug: 'x',
			status: 'draft',
		})
	})

	it('adopts a wrapped answer', async () => {
		axiosMock.put.mockResolvedValue({ data: { results: { '@self': { id: 'u-1' }, status: 'server' } } })
		const ctx = context({ obApp: { '@self': { id: 'u-1' }, status: 'published' } })
		await patch(ctx, { status: 'draft' })
		expect(ctx.obApp).toEqual({ '@self': { id: 'u-1' }, status: 'server' })
	})

	it('adopts a bare answer that carries @self', async () => {
		axiosMock.put.mockResolvedValue({ data: { '@self': { id: 'u-1' }, status: 'bare' } })
		const ctx = context({ obApp: { '@self': { id: 'u-1' }, status: 'published' } })
		await patch(ctx, { status: 'draft' })
		expect(ctx.obApp).toEqual({ '@self': { id: 'u-1' }, status: 'bare' })
	})

	it.each([[null], [{}], [{ status: 'no-self' }]])('merges locally when the answer is %j', async (data) => {
		axiosMock.put.mockResolvedValue({ data })
		const ctx = context({ obApp: { uuid: 'u-2', status: 'published', name: 'App' } })
		await patch(ctx, { status: 'draft' })
		expect(axiosMock.put.mock.calls[0][0]).toBe(`${URL_BASE}/u-2`)
		expect(ctx.obApp).toEqual({ uuid: 'u-2', status: 'draft', name: 'App' })
	})

	it('lets a failed PUT reject and keeps the record', async () => {
		axiosMock.put.mockImplementation(async () => {
			throw new Error('forbidden')
		})
		const app = { uuid: 'u-3', status: 'published' }
		const ctx = context({ obApp: app })
		await expect(patch(ctx, { status: 'draft' })).rejects.toThrow('forbidden')
		expect(ctx.obApp).toBe(app)
	})
})
