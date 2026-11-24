// SPDX-License-Identifier: EUPL-1.2
/**
 * schemaExportable: buildiq marks the schemas it writes as exportable.
 *
 * nextcloud-vue's CnIndexPage draws its Export menu only when the page sets
 * `allowExport` AND the page's schema is flagged exportable, at the top level
 * (`exportable`) or in `configuration.exportable`. A list page's Export switch
 * is the maker's control (REQ-BQCV-004), so every schema buildiq creates or
 * saves carries `configuration.exportable: true`. A flag that is already set,
 * true or false, is the schema owner's choice and is left as it is.
 *
 * @module services/schemaExportable
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */

/**
 * Whether a value is a plain object (not null, not an array).
 *
 * @param {unknown} value The value to test.
 * @return {boolean} True for a plain object.
 */
function isPlainObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * Whether a schema already carries an exportable flag in either place
 * CnIndexPage reads.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 * @param {object|null|undefined} schema The schema as OpenRegister serves it.
 * @return {boolean} True when `exportable` or `configuration.exportable` is set.
 */
export function hasExportableFlag(schema) {
	if (!isPlainObject(schema)) {
		return false
	}
	if (schema.exportable !== undefined && schema.exportable !== null) {
		return true
	}
	const configuration = schema.configuration
	return isPlainObject(configuration)
		&& configuration.exportable !== undefined
		&& configuration.exportable !== null
}

/**
 * The `configuration` block to write so the schema is exportable, or null
 * when the schema already says whether it is.
 *
 * The schema's other configuration keys are kept: OpenRegister replaces the
 * whole block on a write, so the flag is added to the stored block, never
 * sent alone over it. OpenRegister serves an empty block as `[]`; that reads
 * as no keys.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 * @param {object|null|undefined} schema The stored schema, or nothing for a new one.
 * @return {object|null} The configuration to write, or null to leave it.
 */
export function exportableConfiguration(schema) {
	if (hasExportableFlag(schema)) {
		return null
	}
	const stored = isPlainObject(schema) && isPlainObject(schema.configuration)
		? schema.configuration
		: {}
	return { ...stored, exportable: true }
}
