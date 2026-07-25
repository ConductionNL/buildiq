/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for useTrackLinkAction (REQ-EFP-006).
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import { useTrackLinkAction } from '../../src/composables/useTrackLinkAction.js'

describe('useTrackLinkAction', () => {
	it('POSTs the shares integration route with type: public-token', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: { token: 'tok-1', url: '/apps/openregister/api/public/case-tokens/tok-1' } }) }
		const { mintTrackLink } = useTrackLinkAction(client)
		const result = await mintTrackLink('intake', 'report', 'obj-1')
		const [url, body] = client.post.mock.calls[0]
		expect(url).toContain('/apps/openregister/api/objects/intake/report/obj-1/integrations/shares')
		expect(body).toEqual({ type: 'public-token' })
		expect(result.token).toBe('tok-1')
		expect(result.url).toBe('/apps/openregister/api/public/case-tokens/tok-1')
	})

	it('forwards optional label/ttlSeconds', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: { token: 't' } }) }
		const { mintTrackLink } = useTrackLinkAction(client)
		await mintTrackLink('intake', 'report', 'obj-1', { label: 'Citizen copy', ttlSeconds: 3600 })
		const body = client.post.mock.calls[0][1]
		expect(body).toEqual({ type: 'public-token', label: 'Citizen copy', ttlSeconds: 3600 })
	})

	it('builds the public case-token URL from the token when no url is returned', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: { token: 'tok-2' } }) }
		const { mintTrackLink } = useTrackLinkAction(client)
		const result = await mintTrackLink('intake', 'report', 'obj-1')
		expect(result.url).toBe('/apps/openregister/api/public/case-tokens/tok-2')
	})

	it('rejects when required identifiers are missing', async () => {
		const client = { post: vi.fn() }
		const { mintTrackLink } = useTrackLinkAction(client)
		await expect(mintTrackLink('', 'report', 'obj-1')).rejects.toThrow()
		expect(client.post).not.toHaveBeenCalled()
	})

	it('propagates a request failure', async () => {
		const client = { post: vi.fn().mockRejectedValue(new Error('403')) }
		const { mintTrackLink } = useTrackLinkAction(client)
		await expect(mintTrackLink('intake', 'report', 'obj-1')).rejects.toThrow('403')
	})
})
