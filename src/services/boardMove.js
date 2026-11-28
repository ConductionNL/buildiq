// SPDX-License-Identifier: EUPL-1.2
/**
 * Board moves in a built app.
 *
 * nextcloud-vue draws the board of an index page but never writes the status
 * field itself: without a host transition the board is read-only. A built app
 * has no host code of its own, so buildiq supplies one handler, registered in
 * `src/runtimeRegistry.js` as `board-move`, and the index page editor names it
 * as `config.board.moveHandler` whenever the maker picks a status field.
 *
 * The handler saves the one field on the record through OpenRegister, which
 * runs the schema's validation, lifecycle guards and audit trail exactly as a
 * save from the record's form does. A refusal rejects with OpenRegister's own
 * sentence, so the board returns the card and shows why.
 *
 * @module services/boardMove
 * @spec openspec/changes/archive/2026-11-24-pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** Registry name of the board move handler (`src/runtimeRegistry.js`). */
export const BOARD_MOVE_HANDLER = 'board-move'

/**
 * Where a card's record lives: its register, schema and id, read from the
 * row OpenRegister returned.
 *
 * @param {object} card - The board card (an OpenRegister object row).
 * @return {{register: string, schema: string, id: string}|null} The address, or null when one part is missing.
 * @spec openspec/changes/archive/2026-11-24-pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */
export function recordAddressOf(card) {
	const self = (card && typeof card['@self'] === 'object' && card['@self']) || {}
	const part = (value) => (value === undefined || value === null ? '' : String(value))
	const register = part(self.register)
	const schema = part(self.schema)
	const id = part(card?.id ?? self.id)
	if (register === '' || schema === '' || id === '') {
		return null
	}
	return { register, schema, id }
}

/**
 * Move a card: write the column's value into the board's field on the record.
 *
 * @param {object} move - What the board hands a move handler.
 * @param {object} move.card - The card dropped.
 * @param {string} move.toKey - The column it was dropped on.
 * @param {string} move.field - The field the columns come from.
 * @param {string|number|boolean|null} [move.value] - The value to write, when the column differs from its key.
 * @param {object} [client] - axios-compatible client (tests inject one).
 * @return {Promise<object>} The record as OpenRegister saved it.
 * @spec openspec/changes/archive/2026-11-24-pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
 */
export async function moveCardOnBoard({ card, toKey, field, value } = {}, client = axios) {
	const address = recordAddressOf(card)
	if (address === null || typeof field !== 'string' || field === '') {
		throw new Error(t('buildiq', 'This card cannot move to that column.'))
	}
	const url = generateUrl('/apps/openregister/api/objects/{register}/{schema}/{id}', address)
	const { data } = await client.patch(url, { [field]: value === undefined ? toKey : value })
	return data && typeof data === 'object' ? data : { ...card, [field]: value === undefined ? toKey : value }
}
