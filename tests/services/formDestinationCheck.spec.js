/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for src/services/formDestinationCheck.js (decision 179/189):
 * the designer's call to buildiq's destination check, and how findings are
 * put on their page and their field.
 */
import { describe, expect, it, vi } from 'vitest'
import {
	checkFormDestinations,
	findingsByField,
	findingsForPage,
} from '../../src/services/formDestinationCheck.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))

describe('checkFormDestinations', () => {
	it('posts the manifest and reports findings as not accepted', async () => {
		const finding = {
			page: 'new',
			pageIndex: 0,
			field: 'a',
			property: 'a',
			code: 'type-mismatch',
			message: 'A',
		}
		const client = {
			post: vi.fn().mockResolvedValue({
				data: { accepted: false, findings: [finding] },
			}),
		}
		const manifest = { pages: [] }

		const answer = await checkFormDestinations(manifest, 'demo', client)

		expect(client.post).toHaveBeenCalledWith(
			'/index.php/apps/buildiq/api/forms/destination-check',
			{ appSlug: 'demo', manifest },
		)
		expect(answer).toEqual({ accepted: false, findings: [finding] })
	})

	it('reads a malformed answer as no findings only when it carries none', async () => {
		const client = { post: vi.fn().mockResolvedValue({ data: {} }) }

		expect(await checkFormDestinations({}, 'demo', client)).toEqual({
			accepted: true,
			findings: [],
		})
	})

	it('lets a failing check reject, so the caller can say it did not run', async () => {
		const client = { post: vi.fn().mockRejectedValue(new Error('down')) }

		await expect(checkFormDestinations({}, 'demo', client)).rejects.toThrow(
			'down',
		)
	})
})

describe('findingsForPage', () => {
	it('matches by page id, and by position when the page has no id', () => {
		const findings = [
			{ page: 'new', pageIndex: 2, code: 'a' },
			{ page: '', pageIndex: 3, code: 'b' },
		]

		expect(findingsForPage(findings, 'new', 2).map((f) => f.code)).toEqual(['a'])
		expect(findingsForPage(findings, '', 3).map((f) => f.code)).toEqual(['b'])
	})
})

describe('findingsByField', () => {
	it('puts each finding on its field, a fixed value on its property, and a required property on no field', () => {
		const grouped = findingsByField([
			{ field: 'naam', property: 'naam', code: 'type-mismatch' },
			{ property: 'intakeChannel', code: 'fixed-value-invalid' },
			{ property: 'caseType', code: 'required-unmapped' },
			{ property: '', code: 'destination-not-public' },
		])

		expect(Object.keys(grouped).sort()).toEqual(['', 'intakeChannel', 'naam'])
		expect(grouped[''].map((f) => f.code)).toEqual([
			'required-unmapped',
			'destination-not-public',
		])
	})
})
