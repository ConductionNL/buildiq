/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the Procest deep-link helper.
 *
 * Spec: procest-workflow-attachments (REQ-PWA-005).
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

import {
	buildProcestCaseUrl,
	caseUuidFromReference,
} from '../../src/services/procestLinks.js'

const UUID = '11111111-2222-3333-4444-555555555555'

describe('procestLinks', () => {
	it('builds a case URL by UUID', () => {
		expect(buildProcestCaseUrl(UUID)).toBe(`/apps/procest/cases/${UUID}`)
	})
	it('returns empty for no UUID', () => {
		expect(buildProcestCaseUrl('')).toBe('')
	})
	it('extracts a UUID from a bare UUID reference', () => {
		expect(caseUuidFromReference(UUID)).toBe(UUID)
	})
	it('extracts a UUID from a full zaak URL', () => {
		expect(
			caseUuidFromReference(
				`https://host/apps/procest/api/zgw/zaken/v1/zaken/${UUID}`,
			),
		).toBe(UUID)
	})
	it('returns empty for a reference with no UUID', () => {
		expect(caseUuidFromReference('not-a-uuid')).toBe('')
	})
})
