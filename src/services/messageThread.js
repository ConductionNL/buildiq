// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * messageThread — a conversation kept as the records of one OpenRegister
 * schema, for a chat page bound to it (capability-requirements-backfill
 * REQ-BQCV-009).
 *
 * Each record is one message: its text is the page's `messageField`, its
 * author the record's owner and its time when it was created. Posting a
 * message creates a record, through the same OpenRegister rules as any
 * other create.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/** The field a message's text is kept in when the page names none. */
export const DEFAULT_MESSAGE_FIELD = 'message'

/** The most messages one thread shows. */
export const THREAD_LIMIT = 100

/**
 * The records address of a register and schema, as the manifest keeps it.
 *
 * @param {string} register The register slug or id.
 * @param {string} schema The schema slug or id.
 * @return {string} The app-absolute path.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
 */
export function messagesPath(register, schema) {
	return '/apps/openregister/api/objects/' + encodeURIComponent(register) + '/' + encodeURIComponent(schema)
}

/**
 * One record as a message of CnConversationThread.
 *
 * @param {object} record The OpenRegister record.
 * @param {string} field The field holding the text.
 * @param {string} me The signed-in user's id.
 * @return {?object} The message, or null for a record without text.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
 */
export function toMessage(record, field, me) {
	const text = record && record[field]
	if (typeof text !== 'string' || text.trim() === '') {
		return null
	}
	const self = record['@self'] || {}
	const author = self.owner || ''
	return {
		id: self.id || record.id,
		author,
		time: self.created || '',
		text,
		side: author !== '' && author === me ? 'us' : 'them',
	}
}

/**
 * The messages of a thread, oldest first.
 *
 * @param {object} options The thread.
 * @param {string} options.register The register slug or id.
 * @param {string} options.schema The schema slug or id.
 * @param {string} options.field The field holding the text.
 * @param {string} options.me The signed-in user's id.
 * @return {Promise<Array<object>>} The messages.
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-chat-page-req-bqcv-009
 */
export async function fetchMessages({ register, schema, field, me }) {
	const params = new URLSearchParams({
		_limit: String(THREAD_LIMIT),
		_order: JSON.stringify({ '@self.created': 'ASC' }),
	})
	const { data } = await axios.get(generateUrl(messagesPath(register, schema)) + '?' + params.toString())
	const rows = Array.isArray(data) ? data : (data && Array.isArray(data.results) ? data.results : [])
	return rows.map((row) => toMessage(row, field, me)).filter((message) => message !== null)
}
