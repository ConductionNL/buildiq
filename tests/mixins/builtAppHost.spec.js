/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Characterisation tests for the builtAppHost mixin: resolving the Application
 * by the route slug from each objects-API answer shape, seeding the editor
 * manifest (version, then application, then the host's empty manifest, always
 * a copy), the load failure message, and saving the manifest onto the
 * Application.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({ axiosMock: { get: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))

const mod = await import('../../src/mixins/builtAppHost.js')
const builtAppHost = mod.default
const { manifestOf, failureText } = mod

const BUILT_APPS = '/apps/openregister/api/objects/buildiq/built-app'

function host(fields) {
	const ctx = {
		routeSlug: 'permits',
		application: null,
		applicationVersion: null,
		manifest: null,
		loading: false,
		error: 'old',
		toast: 'old',
		emptyManifest: () => ({ pages: [], empty: true }),
		...fields,
	}
	for (const [name, fn] of Object.entries(builtAppHost.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.defineProperty(ctx, 'applicationUuid', { get: () => builtAppHost.computed.applicationUuid.call(ctx) })
	return ctx
}

describe('manifestOf and failureText', () => {
	it.each([
		[null, null],
		[{}, null],
		[{ manifest: 'text' }, null],
		[{ manifest: { a: 1 } }, { a: 1 }],
	])('manifestOf(%j) is %j', (record, expected) => {
		expect(manifestOf(record)).toEqual(expected)
	})

	it.each([
		[new Error('boom'), 'boom'],
		['plain', 'plain'],
		[null, 'null'],
	])('failureText(%s) is %s', (e, text) => {
		expect(failureText(e)).toBe(text)
	})
})

describe('builtAppHost fetchRouteApplication', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it.each([
		['a wrapped answer', { results: [{ slug: 'other' }, { slug: 'permits', id: 1 }] }],
		['a bare list', [null, { slug: 'permits', id: 1 }]],
	])('finds the route slug in %s', async (_label, data) => {
		axiosMock.get.mockResolvedValue({ data })
		const ctx = host()
		expect(await ctx.fetchRouteApplication()).toEqual({ slug: 'permits', id: 1 })
		expect(axiosMock.get).toHaveBeenCalledWith(BUILT_APPS, { params: { _limit: 100 } })
	})

	it.each([[null], [{}], ['text'], [{ results: [{ slug: 'other' }] }]])('is null for %j', async (data) => {
		axiosMock.get.mockResolvedValue({ data })
		expect(await host().fetchRouteApplication()).toBeNull()
	})
})

describe('builtAppHost load and seedManifest', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('loads the app, seeds a copy of its manifest and clears the messages', async () => {
		const manifest = { pages: ['home'] }
		axiosMock.get.mockResolvedValue({ data: [{ slug: 'permits', manifest }] })
		const ctx = host()
		await ctx.load()
		expect(ctx.application).toEqual({ slug: 'permits', manifest })
		expect(ctx.manifest).toEqual(manifest)
		expect(ctx.manifest).not.toBe(manifest)
		expect(ctx.error).toBe('')
		expect(ctx.toast).toBe('')
		expect(ctx.loading).toBe(false)
	})

	it('prefers the version manifest over the application one', () => {
		const ctx = host({ applicationVersion: { manifest: { v: 1 } }, application: { manifest: { a: 1 } } })
		ctx.seedManifest()
		expect(ctx.manifest).toEqual({ v: 1 })
	})

	it('falls back to the empty manifest', () => {
		const ctx = host({ application: { manifest: 'not-an-object' } })
		ctx.seedManifest()
		expect(ctx.manifest).toEqual({ pages: [], empty: true })
	})

	it('reports a failed load and drops the application', async () => {
		axiosMock.get.mockImplementation(async () => {
			throw new Error('offline')
		})
		const ctx = host({ application: { slug: 'stale' } })
		await ctx.load()
		expect(ctx.application).toBeNull()
		expect(ctx.error).toBe('Failed to load the app: offline')
		expect(ctx.loading).toBe(false)
	})
})

describe('builtAppHost applicationUuid and saveOntoApplication', () => {
	beforeEach(() => {
		axiosMock.put.mockReset()
	})

	it.each([
		[null, ''],
		[{ uuid: 'u' }, 'u'],
		[{ '@self': { id: 's' }, uuid: 'u' }, 's'],
	])('applicationUuid of %j is %s', (application, uuid) => {
		expect(host({ application }).applicationUuid).toBe(uuid)
	})

	it('PUTs the application with the edited manifest and adopts the answer', async () => {
		axiosMock.put.mockResolvedValue({ data: { uuid: 'u', saved: true } })
		const ctx = host({ application: { uuid: 'u', manifest: { old: 1 }, name: 'A' }, manifest: { new: 1 } })
		await ctx.saveOntoApplication()
		expect(axiosMock.put).toHaveBeenCalledWith(`${BUILT_APPS}/u`, { uuid: 'u', manifest: { new: 1 }, name: 'A' })
		expect(ctx.application).toEqual({ uuid: 'u', saved: true })
	})

	it.each([[null], ['ok']])('keeps the application when the answer is %j', async (data) => {
		axiosMock.put.mockResolvedValue({ data })
		const app = { uuid: 'u' }
		const ctx = host({ application: app, manifest: {} })
		await ctx.saveOntoApplication()
		expect(ctx.application).toBe(app)
	})
})
