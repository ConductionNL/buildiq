/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden output of the data-import client: the flattened summary of every
 * response shape, and the request (URL and form fields) or refusal of every
 * import parameter combination.
 *
 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => `/index.php${p}` }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn(), get: vi.fn() } }))

import {
	summarizeImport,
	useDataImport,
} from '../../src/composables/useDataImport.js'

const SHEETS = [
	{
		created: [1, 2],
		updated: [3],
		unchanged: [4, 5, 6],
		errors: [{ row: 2, error: 'x' }, 'plain', null, 7],
	},
	{ created: 'many', updated: null, errors: { row: 1 } },
	{},
	null,
	'sheet',
	[1, 2],
	{ unchanged: [], errors: [] },
]

const RESPONSES = [
	undefined,
	null,
	'text',
	7,
	{},
	{ summary: null },
	{ summary: 'x' },
	{ summary: { importJobId: 'job-1' } },
	{ summary: { importJobId: 42, A: SHEETS[0] } },
	{ summary: { importJobId: { created: [1], errors: ['e'] }, B: SHEETS[6] } },
	{
		summary: {
			A: SHEETS[0],
			B: SHEETS[1],
			C: SHEETS[2],
			D: SHEETS[3],
			E: SHEETS[4],
			F: SHEETS[5],
			G: SHEETS[6],
		},
	},
	{ summary: [SHEETS[0], SHEETS[6]] },
	{ summary: { A: SHEETS[0] }, errors_csv: 'a,b', errors_csv_filename: 'e.csv' },
	{ summary: { A: SHEETS[6] }, errors_csv: 'a,b', errors_csv_filename: 3 },
	{ summary: {}, errors_csv: '', errors_csv_filename: 'e.csv' },
	{ errors_csv: 5 },
	{ errors_csv: 'only' },
]

const PARAMS = [
	{},
	{ registerId: 'reg' },
	{ file: 'f' },
	{ registerId: '', file: 'f' },
	{ registerId: 'reg', file: 'f' },
	{ registerId: 'r g/1', file: 'f', schema: 'person', type: 'csv' },
	{ registerId: 'reg', file: 'f', includeObjects: false },
	{ registerId: 'reg', file: 'f', includeObjects: 0, schema: 7, type: 'xlsx' },
	{ registerId: 'reg', file: 'f', includeObjects: 'no', schema: '', type: '' },
	{ registerId: 5, file: 'f', includeObjects: undefined, schema: null },
]

describe('useDataImport golden', () => {
	it('summarizeImport flattens every response as recorded', () => {
		expect(RESPONSES.map((r) => summarizeImport(r))).toMatchSnapshot()
	})

	it('importFile sends or refuses every parameter set as recorded', async () => {
		const out = []
		for (const params of PARAMS) {
			for (const answer of [{ data: RESPONSES[11] }, null, {}]) {
				const post = vi.fn().mockResolvedValue(answer)
				const { importFile } = useDataImport({
					client: { post, get: vi.fn() },
				})
				try {
					const summary = await importFile(params)
					const [url, form, options] = post.mock.calls[0]
					out.push({ url, fields: [...form.entries()], options, summary })
				} catch (e) {
					out.push({ refused: e.message, posted: post.mock.calls.length })
				}
			}
		}
		expect(out).toMatchSnapshot()
	})
})
