/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * downloadFormExport saves the form-template envelope, without instance
 * identity, as a JSON file named after the form slug.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { downloadFormExport, exportFormPayload, FORM_EXPORT_KIND } from '../../src/services/formExport.js'

describe('downloadFormExport', () => {
	afterEach(() => vi.restoreAllMocks())

	it.each([
		[{ uuid: 'u', id: 3, createdBy: 'a', organisation: 'o', '@self': {}, slug: 'intake', name: 'Intake' }, 'intake.json'],
		[null, 'form-template.json'],
	])('downloads %#', async (form, filename) => {
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		let blob
		URL.createObjectURL = vi.fn((b) => {
			blob = b
			return 'blob:f'
		})
		URL.revokeObjectURL = vi.fn()

		downloadFormExport(form)

		const link = click.mock.contexts[0]
		expect(link.download).toBe(filename)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:f')
		expect(blob.type).toBe('application/json')
		const saved = JSON.parse(await blob.text())
		expect(saved).toEqual(exportFormPayload(form))
		expect(saved.kind).toBe(FORM_EXPORT_KIND)
		expect(Object.keys(saved.form)).not.toContain('uuid')
		expect(Object.keys(saved.form)).not.toContain('organisation')
	})
})
