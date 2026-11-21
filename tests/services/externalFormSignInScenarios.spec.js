/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The externally provisioned form page half of REQ-BQCV-019: a form with a
 * sign-in level reaches the portal page as that level (the portal then asks
 * the visitor to sign in, portaliq#725) and grants no anonymous create on its
 * schema. The dialog's choice of grant is asserted in
 * tests/dialogs/ExternalFormAccessDialog.spec.js; this spec runs the real
 * provisioning service against a recording client.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/external-form-provisioning/spec.md#requirement-a-form-can-require-sign-in-at-a-trust-level-req-bqcv-019
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import {
	provisionPortalPage,
	revokePublicCreate,
} from '../../src/services/externalFormProvisioningService.js'

describe('REQ-BQCV-019: a form can require sign-in at a trust level', () => {
	it('a substantial form reaches the portal page as substantial, never anonymous, and touches no schema grant', async () => {
		const client = {
			get: vi.fn(),
			patch: vi.fn(),
			put: vi.fn(),
			post: vi.fn().mockResolvedValue({ data: { id: 'pp-1' } }),
		}

		const result = await provisionPortalPage(
			{ register: 'intake', schema: 'request', minTrust: 'substantial' },
			client,
		)

		expect(result).toEqual({ objectId: 'pp-1', portalPath: '/portal', unavailable: false })
		const [, payload] = client.post.mock.calls[0]
		expect(payload.actions).toEqual([
			{ type: 'create', register: 'intake', schema: 'request', minTrust: 'substantial' },
		])
		expect(payload.collections).toEqual([
			{ register: 'intake', schema: 'request', minTrust: 'substantial' },
		])
		expect(client.patch).not.toHaveBeenCalled()
	})

	it('a form raised from anonymous to a level loses its public create and keeps every other group', async () => {
		const client = {
			get: vi.fn().mockResolvedValue({
				data: { authorization: { create: ['public', 'staff'], read: ['public'] } },
			}),
			patch: vi.fn().mockResolvedValue({ data: {} }),
		}

		await revokePublicCreate({ schema: 'request', removeRead: false }, client)

		expect(client.patch.mock.calls[0][1]).toEqual({
			authorization: { create: ['staff'], read: ['public'] },
		})
	})
})
