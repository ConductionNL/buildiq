/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Procest case runtime integration.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-003, REQ-PWA-004).
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import { useProcestCase, renderDescription } from '../../src/composables/useProcestCase.js'

const UUID = '11111111-2222-3333-4444-555555555555'
const attachment = { caseTypeUuid: UUID, caseTypeName: 'Kapvergunning', linkProperty: 'zaakUrl', schema: 'kapaanvraag', descriptionTemplate: 'Application for {{title}}' }
const object = { '@self': { id: 'obj-1', register: 'r', schema: 'kapaanvraag' }, title: 'tree removal' }

describe('renderDescription', () => {
	it('interpolates object properties', () => {
		expect(renderDescription('Application for {{title}}', { title: 'X' })).toBe('Application for X')
	})
	it('renders empty placeholders for missing keys', () => {
		expect(renderDescription('a {{missing}} b', {})).toBe('a  b')
	})
})

describe('useProcestCase', () => {
	it('starts a case and writes the reference back onto the object', async () => {
		const zaak = { uuid: UUID, url: `https://host/zaken/${UUID}` }
		const client = { post: vi.fn().mockResolvedValue({ data: zaak }), put: vi.fn().mockResolvedValue({ data: {} }) }
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
		const client = { post: vi.fn().mockRejectedValue(new Error('500')), put: vi.fn() }
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
			get: vi.fn().mockResolvedValue({ data: { uuid: UUID, identificatie: 'ZAAK-1' } }),
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
		const client = { get: vi.fn().mockRejectedValue({ response: { status: 403 } }) }
		const pc = useProcestCase({ attachment, client })
		await pc.loadDetail(UUID)
		expect(pc.noAccess.value).toBe(true)
		expect(pc.detailError.value).toBeNull()
	})
})
