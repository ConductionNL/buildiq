/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The board move handler of a built app (pages-index-calendar-and-manual-order,
 * REQ-PEC-010): a dropped card saves its column into the record through
 * OpenRegister, and a refusal keeps OpenRegister's own sentence.
 *
 * @spec openspec/changes/archive/2026-11-24-pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */
import { describe, expect, it, vi } from 'vitest'
import { BOARD_MOVE_HANDLER, moveCardOnBoard, recordAddressOf } from '../../src/services/boardMove.js'
import { getRuntimeRegistry } from '../../src/runtimeRegistry.js'

const card = { id: 'c-1', status: 'new', '@self': { id: 'c-1', register: 7, schema: 'incident' } }

describe('boardMove', () => {
	it('reads the record address from the row', () => {
		expect(recordAddressOf(card)).toEqual({ register: '7', schema: 'incident', id: 'c-1' })
		expect(recordAddressOf({ id: 'x' })).toBeNull()
		expect(recordAddressOf(null)).toBeNull()
	})

	it('patches only the board field with the column key', async () => {
		const client = { patch: vi.fn(async () => ({ data: { ...card, status: 'triaged' } })) }
		const saved = await moveCardOnBoard({ card, toKey: 'triaged', field: 'status' }, client)
		expect(client.patch).toHaveBeenCalledTimes(1)
		const [url, body] = client.patch.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/objects/7/incident/c-1')
		expect(body).toEqual({ status: 'triaged' })
		expect(saved.status).toBe('triaged')
	})

	it('writes the given value when the column differs from its key', async () => {
		const client = { patch: vi.fn(async () => ({ data: null })) }
		const saved = await moveCardOnBoard({ card, toKey: 'col-2', field: 'stage', value: 'uuid-2' }, client)
		expect(client.patch.mock.calls[0][1]).toEqual({ stage: 'uuid-2' })
		expect(saved.stage).toBe('uuid-2')
	})

	it('refuses a card without an address, sending nothing', async () => {
		const client = { patch: vi.fn() }
		await expect(moveCardOnBoard({ card: { id: 'x' }, toKey: 'a', field: 'status' }, client)).rejects.toThrow('This card cannot move to that column.')
		await expect(moveCardOnBoard({ card, toKey: 'a', field: '' }, client)).rejects.toThrow()
		expect(client.patch).not.toHaveBeenCalled()
	})

	it('lets a refusal from OpenRegister reach the board unchanged', async () => {
		const refusal = Object.assign(new Error('Request failed'), { response: { data: { message: 'Closed incidents cannot be triaged.' } } })
		const client = { patch: vi.fn(async () => { throw refusal }) }
		await expect(moveCardOnBoard({ card, toKey: 'triaged', field: 'status' }, client)).rejects.toBe(refusal)
	})

	it('is registered as a handler in the runtime registry', () => {
		const entry = getRuntimeRegistry()[BOARD_MOVE_HANDLER]
		expect(entry.kind).toBe('handler')
		expect(entry.handler).toBe(moveCardOnBoard)
	})
})
