/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for useDocudeskTemplates — the shared Docudesk template-list
 * fetch reused by DocumentTemplateAttachmentDialog and AutomationEditDialog.
 *
 * Spec: automation-document-action ("Template list is shared, not duplicated").
 */
import { describe, it, expect, vi } from 'vitest'
import { fetchDocudeskTemplates, templateToOption } from '../../src/composables/useDocudeskTemplates.js'

describe('fetchDocudeskTemplates', () => {
	it('returns the results array from the response', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: { results: [{ id: '1', name: 'A' }] } }) }
		const templates = await fetchDocudeskTemplates({ client })
		expect(templates).toEqual([{ id: '1', name: 'A' }])
	})

	it('tolerates a bare-array response shape', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: [{ id: '2', name: 'B' }] }) }
		const templates = await fetchDocudeskTemplates({ client })
		expect(templates).toEqual([{ id: '2', name: 'B' }])
	})

	it('resolves to an empty array on any failure — never throws', async () => {
		const client = { get: vi.fn().mockRejectedValue(new Error('network')) }
		const templates = await fetchDocudeskTemplates({ client })
		expect(templates).toEqual([])
	})

	it('resolves to an empty array for a malformed response', async () => {
		const client = { get: vi.fn().mockResolvedValue({ data: null }) }
		const templates = await fetchDocudeskTemplates({ client })
		expect(templates).toEqual([])
	})
})

describe('templateToOption', () => {
	it('maps name/id to the shared option shape', () => {
		expect(templateToOption({ id: 'uuid-1', name: 'Bevestigingsbrief' })).toEqual({
			label: 'Bevestigingsbrief',
			uuid: 'uuid-1',
			name: 'Bevestigingsbrief',
		})
	})

	it('falls back to title, then id, when name is absent', () => {
		expect(templateToOption({ id: 'uuid-2', title: 'Besluit' })).toEqual({
			label: 'Besluit',
			uuid: 'uuid-2',
			name: 'Besluit',
		})
		expect(templateToOption({ id: 'uuid-3' })).toEqual({
			label: 'uuid-3',
			uuid: 'uuid-3',
			name: '',
		})
	})

	it('falls back to uuid when id is absent', () => {
		expect(templateToOption({ uuid: 'uuid-4', name: 'X' })).toEqual({
			label: 'X',
			uuid: 'uuid-4',
			name: 'X',
		})
	})
})
