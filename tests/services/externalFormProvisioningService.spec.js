/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for externalFormProvisioningService.
 *
 * Spec: external-form-provisioning (REQ-EFP-003, REQ-EFP-004, REQ-EFP-005).
 * Central proof obligation: schema-authorization AND portalPage writes are
 * both READ-MERGE-WRITE — a PATCH/PUT NEVER carries a partial fragment that
 * could silently clobber sibling fields another app/admin set.
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import {
	enablePublicCreate,
	revokePublicCreate,
	provisionPortalPage,
	draftPortalPage,
} from '../../src/services/externalFormProvisioningService.js'

describe('enablePublicCreate', () => {
	it('preserves existing authorization groups when adding public to create', async () => {
		const current = { authorization: { read: ['public'], update: ['editors'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await enablePublicCreate({ schema: 'report' }, client)
		const [url, body] = client.patch.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/schemas/report')
		expect(body).toEqual({ authorization: { read: ['public'], update: ['editors'], create: ['public'] } })
	})

	it('adds public to read only when publicRead is true, without removing entries', async () => {
		const current = { authorization: { read: ['members'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await enablePublicCreate({ schema: 'report', publicRead: true }, client)
		const body = client.patch.mock.calls[0][1]
		expect(body.authorization.read).toEqual(['members', 'public'])
		expect(body.authorization.create).toEqual(['public'])
	})

	it('never sends a partial authorization fragment (all four verbs present when set)', async () => {
		const current = { authorization: { read: ['a'], update: ['b'], delete: ['c'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await enablePublicCreate({ schema: 'x' }, client)
		const body = client.patch.mock.calls[0][1]
		expect(Object.keys(body)).toEqual(['authorization'])
		expect(body.authorization.update).toEqual(['b'])
		expect(body.authorization.delete).toEqual(['c'])
	})

	it('does not mutate the object returned by GET', async () => {
		const current = { authorization: { read: ['public'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await enablePublicCreate({ schema: 'report' }, client)
		expect(current.authorization).toEqual({ read: ['public'] })
	})

	it('is idempotent — enabling twice does not duplicate the public entry', async () => {
		const current = { authorization: { create: ['public'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await enablePublicCreate({ schema: 'report' }, client)
		const body = client.patch.mock.calls[0][1]
		expect(body.authorization.create).toEqual(['public'])
	})
})

describe('revokePublicCreate', () => {
	it('removes only the public entry this toggle added, leaving other groups untouched', async () => {
		const current = { authorization: { create: ['public'], read: ['public'], update: ['editors'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await revokePublicCreate({ schema: 'report', removeRead: false }, client)
		const body = client.patch.mock.calls[0][1]
		expect(body.authorization).toEqual({ create: [], read: ['public'], update: ['editors'] })
	})

	it('removes public from read too when removeRead is true', async () => {
		const current = { authorization: { create: ['public'], read: ['public'], update: ['editors'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await revokePublicCreate({ schema: 'report', removeRead: true }, client)
		const body = client.patch.mock.calls[0][1]
		expect(body.authorization).toEqual({ create: [], read: [], update: ['editors'] })
	})

	it('is a no-op-safe when public was never present', async () => {
		const current = { authorization: { create: [], update: ['editors'] } }
		const client = {
			get: vi.fn().mockResolvedValue({ data: current }),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}
		await revokePublicCreate({ schema: 'report' }, client)
		const body = client.patch.mock.calls[0][1]
		expect(body.authorization).toEqual({ create: [], update: ['editors'] })
	})
})

describe('provisionPortalPage', () => {
	it('creates a new portalPage object with an anonymous create action', async () => {
		const client = {
			post: vi.fn().mockResolvedValue({ data: { '@self': { id: 'pp-1' } } }),
		}
		const result = await provisionPortalPage({ register: 'intake', schema: 'report' }, client)
		expect(result).toEqual({ objectId: 'pp-1', portalPath: '/portal', unavailable: false })
		const [url, body] = client.post.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/objects/portaliq/portalPage')
		expect(body.status).toBe('active')
		expect(body.collections).toEqual([{ register: 'intake', schema: 'report', anonymous: true }])
		expect(body.actions[0]).toMatchObject({ type: 'create', register: 'intake', schema: 'report', anonymous: true, minTrust: 0 })
	})

	it('updates the SAME object on a repeat save (matched by objectId), preserving unrelated fields', async () => {
		const existing = {
			'@self': { id: 'pp-1' },
			label: 'Existing label',
			audience: 'public',
			minTrust: 0,
			collections: [{ register: 'other', schema: 'thing', anonymous: true }],
			actions: [{ type: 'create', register: 'other', schema: 'thing', anonymous: true, minTrust: 0 }],
			pages: [{ id: 'p1' }],
		}
		const client = {
			get: vi.fn().mockResolvedValue({ data: existing }),
			put: vi.fn().mockResolvedValue({ data: existing }),
		}
		await provisionPortalPage({ register: 'intake', schema: 'report', objectId: 'pp-1' }, client)
		const [url, body] = client.put.mock.calls[0]
		expect(url).toContain('portalPage/pp-1')
		// unrelated fields survive untouched.
		expect(body.label).toBe('Existing label')
		expect(body.pages).toEqual([{ id: 'p1' }])
		// the OTHER (register,schema) collection/action entries are preserved...
		expect(body.collections).toContainEqual({ register: 'other', schema: 'thing', anonymous: true })
		expect(body.actions).toContainEqual(expect.objectContaining({ register: 'other', schema: 'thing' }))
		// ...and the new (register,schema) pair is added alongside them.
		expect(body.collections).toContainEqual({ register: 'intake', schema: 'report', anonymous: true })
		expect(body.actions).toContainEqual(expect.objectContaining({ register: 'intake', schema: 'report', anonymous: true }))
	})

	it('updating twice for the SAME (register,schema) replaces, not duplicates, the action/collection entry', async () => {
		const existing = {
			'@self': { id: 'pp-1' },
			collections: [{ register: 'intake', schema: 'report', anonymous: true }],
			actions: [{ type: 'create', register: 'intake', schema: 'report', anonymous: true, minTrust: 0 }],
		}
		const client = {
			get: vi.fn().mockResolvedValue({ data: existing }),
			put: vi.fn().mockResolvedValue({ data: existing }),
		}
		await provisionPortalPage({ register: 'intake', schema: 'report', objectId: 'pp-1' }, client)
		const body = client.put.mock.calls[0][1]
		expect(body.collections).toHaveLength(1)
		expect(body.actions).toHaveLength(1)
	})

	it('degrades gracefully when the portalPage schema does not exist (404), without throwing', async () => {
		const client = {
			post: vi.fn().mockRejectedValue({ response: { status: 404 } }),
		}
		const result = await provisionPortalPage({ register: 'intake', schema: 'report' }, client)
		expect(result).toEqual({ objectId: null, portalPath: null, unavailable: true })
	})

	it('propagates a genuine (non-404) failure', async () => {
		const client = {
			post: vi.fn().mockRejectedValue({ response: { status: 500 } }),
		}
		await expect(provisionPortalPage({ register: 'intake', schema: 'report' }, client)).rejects.toBeTruthy()
	})
})

describe('draftPortalPage', () => {
	it('sets status to draft while preserving every other field (never deletes)', async () => {
		const existing = {
			'@self': { id: 'pp-1' },
			label: 'My page',
			status: 'active',
			collections: [{ register: 'intake', schema: 'report', anonymous: true }],
			actions: [{ type: 'create', register: 'intake', schema: 'report', anonymous: true }],
		}
		const client = {
			get: vi.fn().mockResolvedValue({ data: existing }),
			put: vi.fn().mockResolvedValue({ data: { ...existing, status: 'draft' } }),
		}
		await draftPortalPage('pp-1', client)
		const [url, body] = client.put.mock.calls[0]
		expect(url).toContain('portalPage/pp-1')
		expect(body.status).toBe('draft')
		expect(body.label).toBe('My page')
		expect(body.collections).toEqual(existing.collections)
		expect(body.actions).toEqual(existing.actions)
	})

	it('no-ops when there is no linked portalPage', async () => {
		const client = { get: vi.fn(), put: vi.fn() }
		const result = await draftPortalPage(null, client)
		expect(result).toBeNull()
		expect(client.get).not.toHaveBeenCalled()
		expect(client.put).not.toHaveBeenCalled()
	})
})
