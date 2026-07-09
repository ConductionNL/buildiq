// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useDataImport — thin client that delegates ALL file parsing, schema
// inference, and object creation to OpenRegister's shipped register-import
// capability. OpenBuild adds NO import backend and does NO parsing/inference/
// writing of its own (ADR-022, one write path). The composable only:
//
//   1. multipart-POSTs the file the maker chose to
//      `POST /apps/openregister/api/registers/{registerId}/import`
//      (type auto-detected by OR from the extension; `includeObjects: true`),
//   2. flattens OpenRegister's sheet-keyed import summary into
//      created / updated / skipped counts + per-row errors + the rollback
//      `importJobId`,
//   3. offers rollback via
//      `POST /apps/openregister/api/registers/import/rollback`, and
//   4. builds the offline-template download URL for
//      `GET /apps/openregister/api/registers/{registerId}/schemas/{schema}/import-template`.
//
// The `registerId` is ALWAYS the active `ApplicationVersion`'s own per-version
// register (`openbuild-{slug}-{versionSlug}`, ADR-002); shared bound
// `dataRegisters` are never passed here — the caller excludes them from the
// target list. The write is independently re-gated server-side by
// OpenRegister's own register manage-permission (default-secure), so OpenBuild
// never becomes a permission-bypass path.
//
// @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1

import axiosDefault from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Flatten OpenRegister's sheet-keyed import response into a single summary.
 *
 * OR returns `{ message, summary: { <sheet>: { created, updated, unchanged,
 * errors }, ..., importJobId }, errors_csv?, errors_csv_filename? }`. The
 * `importJobId` lives at the top level of `summary` alongside one entry per
 * imported sheet; every other key is a sheet whose value carries the
 * created/updated/unchanged/errors arrays. This helper sums those arrays
 * across all sheets so the wizard can show one set of counts, and surfaces
 * the rollback token + any per-row error CSV.
 *
 * This is pure aggregation of what OpenRegister returned — no parsing,
 * inference, or transformation of the imported data itself.
 *
 * @param {object} responseData The raw JSON body from OR's import endpoint.
 * @return {{importJobId: (string|null), created: number, updated: number, skipped: number, errors: Array<object>, errorsCsv: (string|null), errorsCsvFilename: (string|null)}}
 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
 */
export function summarizeImport(responseData) {
	const empty = {
		importJobId: null,
		created: 0,
		updated: 0,
		skipped: 0,
		errors: [],
		errorsCsv: null,
		errorsCsvFilename: null,
	}
	if (!responseData || typeof responseData !== 'object') {
		return empty
	}

	const summary = responseData.summary && typeof responseData.summary === 'object'
		? responseData.summary
		: {}

	const result = { ...empty }
	result.importJobId = typeof summary.importJobId === 'string' ? summary.importJobId : null

	Object.keys(summary).forEach((key) => {
		if (key === 'importJobId') {
			return
		}
		const sheet = summary[key]
		if (!sheet || typeof sheet !== 'object') {
			return
		}
		if (Array.isArray(sheet.created)) {
			result.created += sheet.created.length
		}
		if (Array.isArray(sheet.updated)) {
			result.updated += sheet.updated.length
		}
		// OR labels unimported rows "unchanged"; the wizard calls them "skipped".
		if (Array.isArray(sheet.unchanged)) {
			result.skipped += sheet.unchanged.length
		}
		if (Array.isArray(sheet.errors)) {
			sheet.errors.forEach((e) => result.errors.push({ sheet: key, ...(e && typeof e === 'object' ? e : { message: String(e) }) }))
		}
	})

	if (typeof responseData.errors_csv === 'string' && responseData.errors_csv !== '') {
		result.errorsCsv = responseData.errors_csv
		result.errorsCsvFilename = typeof responseData.errors_csv_filename === 'string'
			? responseData.errors_csv_filename
			: 'import_errors.csv'
	}

	return result
}

/**
 * Data-import client bound to OpenRegister's register-import surface.
 *
 * @param {object} [opts] Options.
 * @param {object} [opts.client] Axios-like client (`post`/`get`) — injectable
 *   for tests; defaults to `@nextcloud/axios` (carries the NC session +
 *   request token, so the import rides the caller's own permissions).
 * @return {{importFile: Function, rollbackImport: Function, templateUrl: Function}}
 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
 */
export function useDataImport(opts = {}) {
	const client = opts.client || axiosDefault

	/**
	 * Import a file into a register via OpenRegister. OpenBuild uploads the
	 * raw file bytes only — OR parses, infers the schema (when none is given),
	 * and writes the objects.
	 *
	 * @param {object} params Import parameters.
	 * @param {string} params.registerId The target register slug/id (always the
	 *   active version's own per-version register).
	 * @param {File|Blob} params.file The file the maker chose.
	 * @param {string} [params.schema] Target schema slug/id. Omit for the
	 *   "create schema from file" path (OR infers it from the header row for
	 *   Excel/JSON; CSV requires a schema and OR returns a clear 400 otherwise).
	 * @param {boolean} [params.includeObjects] Whether OR should write the rows
	 *   (default true — the whole point of the wizard).
	 * @param {string} [params.type] Import type override; omitted lets OR
	 *   auto-detect from the file extension.
	 * @return {Promise<object>} The flattened summary from {@link summarizeImport}.
	 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
	 */
	async function importFile({ registerId, file, schema, includeObjects = true, type }) {
		if (!registerId) {
			throw new Error('registerId is required')
		}
		if (!file) {
			throw new Error('file is required')
		}
		const form = new FormData()
		form.append('file', file)
		form.append('includeObjects', includeObjects ? 'true' : 'false')
		if (schema) {
			form.append('schema', String(schema))
		}
		if (type) {
			form.append('type', String(type))
		}
		const url = generateUrl(`/apps/openregister/api/registers/${encodeURIComponent(registerId)}/import`)
		const response = await client.post(url, form, {
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		return summarizeImport(response && response.data)
	}

	/**
	 * Undo the most recent import by its `importJobId` (soft-deletes every
	 * object the job created). Delegates to OR's rollback endpoint, which
	 * independently re-checks that the caller is the importer or an admin.
	 *
	 * @param {string} importJobId The rollback token from the import summary.
	 * @return {Promise<object>} OR's rollback report.
	 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
	 */
	async function rollbackImport(importJobId) {
		if (!importJobId) {
			throw new Error('importJobId is required')
		}
		const url = generateUrl('/apps/openregister/api/registers/import/rollback')
		const response = await client.post(url, { importJobId }, {
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		return response && response.data
	}

	/**
	 * Build the offline-template download URL for an existing schema so the
	 * maker downloads exactly the columns OR expects, fills them offline, and
	 * re-uploads. The download is a plain GET the browser follows directly.
	 *
	 * @param {object} params Template parameters.
	 * @param {string} params.registerId The target register slug/id.
	 * @param {string} params.schema The schema slug/id.
	 * @param {string} [params.format] `xlsx` (default) or `csv`.
	 * @return {string} The template download URL.
	 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#1.1
	 */
	function templateUrl({ registerId, schema, format = 'xlsx' }) {
		if (!registerId || !schema) {
			return ''
		}
		const fmt = format === 'csv' ? 'csv' : 'xlsx'
		return generateUrl(
			`/apps/openregister/api/registers/${encodeURIComponent(registerId)}/schemas/${encodeURIComponent(schema)}/import-template?format=${fmt}`,
		)
	}

	return { importFile, rollbackImport, templateUrl }
}
