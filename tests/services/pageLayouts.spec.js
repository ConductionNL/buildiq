/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for src/services/pageLayouts.js.
 *
 * Spec: screen-override-layers (REQ-OBSO-002, REQ-OBSO-003).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), put: vi.fn(), post: vi.fn() },
}))

import axios from '@nextcloud/axios'
import {
	fetchPageLayouts,
	recutOverride,
	savePageLayout,
} from '../../src/services/pageLayouts.js'

describe('pageLayouts', () => {
	beforeEach(() => {
		axios.get.mockReset()
		axios.put.mockReset()
		axios.post.mockReset()
	})

	it('reads the layouts for one schema', async () => {
		axios.get.mockResolvedValue({ data: { items: [{ id: 'pl-1' }] } })

		const layouts = await fetchPageLayouts({
			register: 'dossiq',
			schema: 'Zaak',
		})

		expect(layouts).toEqual([{ id: 'pl-1' }])
		expect(axios.get).toHaveBeenCalledWith('/apps/buildiq/api/page-layouts', {
			params: { register: 'dossiq', schema: 'Zaak' },
		})
	})

	it('never sends a base fingerprint back to the server', async () => {
		// The server stamps it from the layouts actually published. Sending one
		// would be a claim about a base this client never checked, and the two
		// halves would look like they disagree.
		axios.put.mockResolvedValue({ data: { layout: {}, warnings: [] } })

		await savePageLayout({
			id: 'pl-1',
			name: 'Behandelaarsscherm',
			baseFingerprint: 'wat-de-client-zei',
			baseCutAt: '2026-01-01T00:00:00Z',
			drifted: true,
			orphanedPaths: ['tabs.documenten'],
		})

		const sent = axios.put.mock.calls[0][1]
		expect(sent).not.toHaveProperty('baseFingerprint')
		expect(sent).not.toHaveProperty('baseCutAt')
		expect(sent).not.toHaveProperty('drifted')
		expect(sent).not.toHaveProperty('orphanedPaths')
		expect(sent.name).toBe('Behandelaarsscherm')
	})

	it('keeps the sentence a refusal wrote', async () => {
		// Every rule on this endpoint refuses for a reason an administrator can
		// act on. Replacing it with "something went wrong" would hide the fix.
		axios.put.mockRejectedValue({
			response: {
				status: 422,
				data: {
					error: 'refused',
					message: 'There is no published layout for this schema to patch.',
				},
			},
		})

		await expect(savePageLayout({ id: 'pl-1' })).rejects.toMatchObject({
			status: 422,
			message: 'There is no published layout for this schema to patch.',
		})
	})

	it('says what a re-cut dropped', async () => {
		axios.post.mockResolvedValue({
			data: { layout: { id: 'pl-1' }, dropped: ['tabs.documenten'] },
		})

		const result = await recutOverride('pl-1')

		expect(result.dropped).toEqual(['tabs.documenten'])
		expect(axios.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/page-layouts/pl-1/recut',
		)
	})

	it('normalises a network failure rather than throwing a raw axios error', async () => {
		axios.get.mockRejectedValue(new Error('Network Error'))

		await expect(
			fetchPageLayouts({ register: 'dossiq', schema: 'Zaak' }),
		).rejects.toMatchObject({ status: 0, error: 'network_error' })
	})
})
