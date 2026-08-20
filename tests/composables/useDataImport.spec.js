/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the data-import client that delegates to OpenRegister's
 * register-import surface. Verifies OpenBuild adds NO import backend — it only
 * multipart-POSTs the file, flattens OR's sheet-keyed summary, offers rollback,
 * and builds the template URL.
 *
 * Spec: openbuild-data-import-wizard (tasks 1.1).
 */
import { describe, it, expect, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))

import {
	useDataImport,
	summarizeImport,
} from '../../src/composables/useDataImport.js'

describe('summarizeImport', () => {
	it('flattens a sheet-keyed OR summary into counts + importJobId + errors', () => {
		const responseData = {
			message: 'Import successful',
			summary: {
				Sheet1: {
					created: [{ id: 1 }, { id: 2 }],
					updated: [{ id: 3 }],
					unchanged: [{ id: 4 }],
					errors: [{ row: 5, message: 'bad date' }],
				},
				importJobId: 'job-uuid-123',
			},
			errors_csv: 'YmFzZTY0',
			errors_csv_filename: 'import_errors.csv',
		}
		const s = summarizeImport(responseData)
		expect(s.created).toBe(2)
		expect(s.updated).toBe(1)
		expect(s.skipped).toBe(1)
		expect(s.importJobId).toBe('job-uuid-123')
		expect(s.errors).toHaveLength(1)
		expect(s.errors[0]).toMatchObject({
			sheet: 'Sheet1',
			row: 5,
			message: 'bad date',
		})
		expect(s.errorsCsv).toBe('YmFzZTY0')
		expect(s.errorsCsvFilename).toBe('import_errors.csv')
	})

	it('sums across multiple sheets and ignores the importJobId key as a sheet', () => {
		const s = summarizeImport({
			summary: {
				A: { created: [{}, {}], updated: [], unchanged: [], errors: [] },
				B: { created: [{}], updated: [{}], unchanged: [{}, {}], errors: [] },
				importJobId: 'j',
			},
		})
		expect(s.created).toBe(3)
		expect(s.updated).toBe(1)
		expect(s.skipped).toBe(2)
	})

	it('returns zeroed summary for a malformed / empty response', () => {
		expect(summarizeImport(null)).toEqual({
			importJobId: null,
			created: 0,
			updated: 0,
			skipped: 0,
			errors: [],
			errorsCsv: null,
			errorsCsvFilename: null,
		})
		expect(summarizeImport({}).created).toBe(0)
		expect(summarizeImport({ summary: 'not-an-object' }).created).toBe(0)
	})
})

describe('useDataImport.importFile', () => {
	const okResponse = {
		data: {
			summary: {
				S: { created: [{}], updated: [], unchanged: [], errors: [] },
				importJobId: 'j1',
			},
		},
	}

	it('create-schema path: POSTs multipart file + includeObjects and NO schema param', async () => {
		const client = { post: vi.fn().mockResolvedValue(okResponse) }
		const { importFile } = useDataImport({ client })
		const file = new File(['a,b\n1,2\n'], 'people.csv', { type: 'text/csv' })

		const summary = await importFile({
			registerId: 'openbuild-app-staging',
			file,
		})

		expect(client.post).toHaveBeenCalledTimes(1)
		const [url, form, opts] = client.post.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/registers/openbuild-app-staging/import',
		)
		expect(form).toBeInstanceOf(FormData)
		expect(form.get('file')).toBe(file)
		expect(form.get('includeObjects')).toBe('true')
		expect(form.get('schema')).toBeNull() // create-from-file: OR infers the schema
		expect(opts.headers['OCS-APIREQUEST']).toBe('true')
		expect(summary.created).toBe(1)
		expect(summary.importJobId).toBe('j1')
	})

	it('existing-schema path: includes the schema param', async () => {
		const client = { post: vi.fn().mockResolvedValue(okResponse) }
		const { importFile } = useDataImport({ client })
		const file = new File(['x'], 'rows.xlsx')

		await importFile({ registerId: 'reg1', file, schema: 'permit' })

		const form = client.post.mock.calls[0][1]
		expect(form.get('schema')).toBe('permit')
	})

	it('throws when registerId or file is missing (no silent no-op)', async () => {
		const { importFile } = useDataImport({ client: { post: vi.fn() } })
		await expect(importFile({ file: new File(['x'], 'a.csv') })).rejects.toThrow(
			/registerId/,
		)
		await expect(importFile({ registerId: 'r' })).rejects.toThrow(/file/)
	})
})

describe('useDataImport.rollbackImport', () => {
	it('POSTs the importJobId to the OR rollback endpoint', async () => {
		const client = {
			post: vi
				.fn()
				.mockResolvedValue({ data: { importJobId: 'j1', deleted: 2 } }),
		}
		const { rollbackImport } = useDataImport({ client })

		const report = await rollbackImport('j1')

		const [url, body] = client.post.mock.calls[0]
		expect(url).toBe(
			'/index.php/apps/openregister/api/registers/import/rollback',
		)
		expect(body).toEqual({ importJobId: 'j1' })
		expect(report.deleted).toBe(2)
	})

	it('throws when importJobId is missing', async () => {
		const { rollbackImport } = useDataImport({ client: { post: vi.fn() } })
		await expect(rollbackImport('')).rejects.toThrow(/importJobId/)
	})
})

describe('useDataImport.templateUrl', () => {
	it('builds the schema import-template URL with the requested format', () => {
		const { templateUrl } = useDataImport({ client: {} })
		expect(
			templateUrl({ registerId: 'reg1', schema: 'permit', format: 'csv' }),
		).toBe(
			'/index.php/apps/openregister/api/registers/reg1/schemas/permit/import-template?format=csv',
		)
		expect(templateUrl({ registerId: 'reg1', schema: 'permit' })).toContain(
			'format=xlsx',
		)
	})

	it('returns empty string when register or schema is missing', () => {
		const { templateUrl } = useDataImport({ client: {} })
		expect(templateUrl({ registerId: '', schema: 'x' })).toBe('')
		expect(templateUrl({ registerId: 'r', schema: '' })).toBe('')
	})
})
