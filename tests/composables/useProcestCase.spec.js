/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Procest case runtime integration.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-003, REQ-PWA-004).
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import {
	renderDescription,
	useProcestCase,
} from '../../src/composables/useProcestCase.js'

const UUID = '11111111-2222-3333-4444-555555555555'
const attachment = {
	caseTypeUuid: UUID,
	caseTypeName: 'Kapvergunning',
	linkProperty: 'zaakUrl',
	schema: 'kapaanvraag',
	descriptionTemplate: 'Application for {{title}}',
}
const object = {
	'@self': { id: 'obj-1', register: 'r', schema: 'kapaanvraag' },
	title: 'tree removal',
}

describe('renderDescription', () => {
	it('interpolates object properties', () => {
		expect(renderDescription('Application for {{title}}', { title: 'X' })).toBe(
			'Application for X',
		)
	})
	it('renders empty placeholders for missing keys', () => {
		expect(renderDescription('a {{missing}} b', {})).toBe('a  b')
	})
})

describe('useProcestCase', () => {
	it('starts a case and writes the reference back onto the object', async () => {
		const zaak = { uuid: UUID, url: `https://host/zaken/${UUID}` }
		const client = {
			post: vi.fn().mockResolvedValue({ data: zaak }),
			put: vi.fn().mockResolvedValue({ data: {} }),
		}
		const pc = useProcestCase({ attachment, client })
		const result = await pc.startCase(object)
		expect(result).toEqual(zaak)
		// ZRC create called with zaaktype + object kenmerk + rendered description.
		const body = client.post.mock.calls[0][1]
		expect(body.zaaktype).toBe(UUID)
		expect(body.kenmerken[0].kenmerk).toBe('obj-1')
		expect(body.omschrijving).toBe('Application for tree removal')
		// write-back PUT carries the link value.
		const putBody = client.put.mock.calls[0][1]
		expect(putBody.zaakUrl).toBe(zaak.url)
	})

	it('preserves the object and surfaces startError on failure', async () => {
		const client = {
			post: vi.fn().mockRejectedValue(new Error('500')),
			put: vi.fn(),
		}
		const pc = useProcestCase({ attachment, client })
		const result = await pc.startCase(object)
		expect(result).toBeNull()
		expect(pc.startError.value).toBeInstanceOf(Error)
		expect(client.put).not.toHaveBeenCalled()
	})

	it('reconcileOrStart adopts an already-linked case without duplicating', async () => {
		const linked = { ...object, zaakUrl: `https://host/zaken/${UUID}` }
		const client = {
			post: vi.fn(),
			put: vi.fn(),
			get: vi.fn().mockResolvedValue({
				data: { uuid: UUID, identificatie: 'ZAAK-1' },
			}),
		}
		const pc = useProcestCase({ attachment, client })
		await pc.reconcileOrStart(linked)
		expect(client.post).not.toHaveBeenCalled() // no duplicate create
		expect(pc.caseDetail.value.identificatie).toBe('ZAAK-1')
	})

	it('reconcileOrStart adopts an existing case found by kenmerk', async () => {
		const found = { uuid: UUID, url: `https://host/zaken/${UUID}` }
		const client = {
			post: vi.fn().mockResolvedValue({ data: { results: [found] } }), // _zoek
			put: vi.fn().mockResolvedValue({ data: {} }),
		}
		const pc = useProcestCase({ attachment, client })
		await pc.reconcileOrStart(object)
		// only the _zoek POST, no create POST
		expect(client.post).toHaveBeenCalledTimes(1)
		expect(client.put).toHaveBeenCalled() // wrote back the found case
	})

	it('loadDetail renders a no-access state on 403, not an error', async () => {
		const client = {
			get: vi.fn().mockRejectedValue({ response: { status: 403 } }),
		}
		const pc = useProcestCase({ attachment, client })
		await pc.loadDetail(UUID)
		expect(pc.noAccess.value).toBe(true)
		expect(pc.detailError.value).toBeNull()
	})
})

describe('useProcestCase writeBack and loadDetail', () => {
	const putClient = () => ({ put: vi.fn().mockResolvedValue({ data: {} }) })

	it('writes the case url, else its uuid, else its @self id onto the link property', async () => {
		for (const [zaak, value] of [
			[{ url: 'u', uuid: 'x' }, 'u'],
			[{ uuid: 'x' }, 'x'],
			[{ '@self': { id: 's' } }, 's'],
		]) {
			const client = putClient()
			await useProcestCase({ attachment, client }).writeBack(object, zaak)
			expect(client.put).toHaveBeenCalledWith(
				'/apps/openregister/api/objects/r/kapaanvraag/obj-1',
				{ ...object, zaakUrl: value },
			)
		}
	})

	it('writes nothing without a link value, a link property or a full object address', async () => {
		const client = putClient()
		const pc = useProcestCase({ attachment, client })
		await pc.writeBack(object, null)
		await pc.writeBack(object, {})
		await pc.writeBack(object, { '@self': {} })
		await useProcestCase({ attachment: {}, client }).writeBack(object, { url: 'u' })
		await useProcestCase({ client }).writeBack(object, { url: 'u' })
		await pc.writeBack({ '@self': { id: 'i' }, schema: 's' }, { url: 'u' })
		await useProcestCase({ attachment: { linkProperty: 'l' }, client }).writeBack(
			{ register: 'r', id: 'i' },
			{ url: 'u' },
		)
		await pc.writeBack({ register: 'r', schema: 's' }, { url: 'u' })
		expect(client.put).not.toHaveBeenCalled()
	})

	it('falls back to the top-level address and the attachment schema', async () => {
		const client = putClient()
		const pc = useProcestCase({ attachment, client })
		await pc.writeBack({ register: 'r2', uuid: 'u2', id: 'i2' }, { url: 'u' })
		await pc.writeBack({ register: 'r3', schema: 's3', id: 'i3' }, { url: 'u' })
		expect(client.put.mock.calls.map((c) => c[0])).toEqual([
			'/apps/openregister/api/objects/r2/kapaanvraag/u2',
			'/apps/openregister/api/objects/r3/s3/i3',
		])
	})

	it('throws on a missing object once there is a link value', async () => {
		const pc = useProcestCase({ attachment, client: putClient() })
		await expect(pc.writeBack(null, { url: 'u' })).rejects.toThrow(TypeError)
		await expect(pc.writeBack(null, null)).resolves.toBeUndefined()
	})

	it('loads the case and its status history, in that order', async () => {
		const client = {
			get: vi
				.fn()
				.mockResolvedValueOnce({ data: { uuid: UUID } })
				.mockResolvedValueOnce({ data: { results: [{ s: 1 }] } }),
		}
		const pc = useProcestCase({ attachment, client })
		const pending = pc.loadDetail(`https://host/zaken/${UUID}`)
		expect(pc.loadingDetail.value).toBe(true)
		await pending
		expect(client.get.mock.calls).toEqual([
			[`/apps/dossiq/api/zgw/zaken/v1/zaken/${UUID}`],
			['/apps/dossiq/api/zgw/zaken/v1/statussen', { params: { zaak: UUID } }],
		])
		expect(pc.caseDetail.value).toEqual({ uuid: UUID })
		expect(pc.statusHistory.value).toEqual([{ s: 1 }])
		expect(pc.loadingDetail.value).toBe(false)
	})

	it('reads a bare status list, and empties it for anything else or a failure', async () => {
		for (const [answer, expected] of [
			[{ data: [{ s: 2 }] }, [{ s: 2 }]],
			[{ data: { results: 'x' } }, []],
			[{ data: null }, []],
			[Promise.reject(new Error('x')), []],
		]) {
			const client = {
				get: vi
					.fn()
					.mockResolvedValueOnce({ data: { uuid: UUID } })
					.mockImplementationOnce(() => answer),
			}
			const pc = useProcestCase({ attachment, client })
			pc.statusHistory.value = ['old']
			await pc.loadDetail(UUID)
			expect(pc.statusHistory.value).toEqual(expected)
			expect(pc.detailError.value).toBeNull()
		}
	})

	it('records a failed case load as an error and resets a previous state', async () => {
		const client = {
			get: vi
				.fn()
				.mockRejectedValueOnce({ response: { status: 403 } })
				.mockRejectedValueOnce('down')
				.mockRejectedValueOnce(new Error('500')),
		}
		const pc = useProcestCase({ attachment, client })
		await pc.loadDetail(UUID)
		expect(pc.noAccess.value).toBe(true)
		await pc.loadDetail(UUID)
		expect(pc.noAccess.value).toBe(false)
		expect(pc.detailError.value).toBeInstanceOf(Error)
		expect(pc.detailError.value.message).toBe('down')
		await pc.loadDetail(UUID)
		expect(pc.detailError.value.message).toBe('500')
		expect(pc.loadingDetail.value).toBe(false)
		expect(pc.statusHistory.value).toEqual([])
	})

	it('does nothing for a reference without a uuid', async () => {
		const client = { get: vi.fn() }
		const pc = useProcestCase({ attachment, client })
		pc.noAccess.value = true
		await pc.loadDetail('not-a-case')
		await pc.loadDetail(null)
		expect(client.get).not.toHaveBeenCalled()
		expect(pc.noAccess.value).toBe(true)
	})
})
