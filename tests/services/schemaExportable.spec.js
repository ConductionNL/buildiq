/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/schemaExportable.js`
 * (capability-requirements-backfill T04, REQ-BQCV-004): the flag CnIndexPage
 * needs before it draws Export is added to a schema buildiq writes, its other
 * configuration keys are kept, and a flag the owner set stays as it is.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-list-page-exports-its-records-to-csv-or-excel-req-bqcv-004
 */
import { describe, expect, it } from 'vitest'
import { exportableConfiguration, hasExportableFlag } from '../../src/services/schemaExportable.js'

describe('hasExportableFlag', () => {
	it('reads the top-level flag and the configuration flag, true or false', () => {
		expect(hasExportableFlag({ exportable: true })).toBe(true)
		expect(hasExportableFlag({ exportable: false })).toBe(true)
		expect(hasExportableFlag({ configuration: { exportable: false } })).toBe(true)
	})

	it('is false for no schema, no flag, a null flag and the empty [] block', () => {
		expect(hasExportableFlag(null)).toBe(false)
		expect(hasExportableFlag(undefined)).toBe(false)
		expect(hasExportableFlag({ slug: 'permit' })).toBe(false)
		expect(hasExportableFlag({ exportable: null, configuration: { exportable: null } })).toBe(false)
		expect(hasExportableFlag({ configuration: [] })).toBe(false)
	})
})

describe('exportableConfiguration', () => {
	it('a new schema gets only the flag', () => {
		expect(exportableConfiguration(null)).toEqual({ exportable: true })
	})

	it('keeps the stored configuration keys and adds the flag', () => {
		const stored = { configuration: { objectNameField: 'title', objectSummaryField: 'summary' } }
		expect(exportableConfiguration(stored)).toEqual({
			objectNameField: 'title',
			objectSummaryField: 'summary',
			exportable: true,
		})
		expect(stored.configuration.exportable).toBeUndefined()
	})

	it('reads OpenRegister\'s empty [] block as no keys', () => {
		expect(exportableConfiguration({ configuration: [] })).toEqual({ exportable: true })
	})

	it('leaves a schema that already says whether it is exportable', () => {
		expect(exportableConfiguration({ exportable: false })).toBeNull()
		expect(exportableConfiguration({ configuration: { exportable: false } })).toBeNull()
		expect(exportableConfiguration({ configuration: { exportable: true } })).toBeNull()
	})
})
