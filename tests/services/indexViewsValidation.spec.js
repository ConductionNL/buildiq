/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the index page view-mode findings (REQ-PEC-008, REQ-PEC-010).
 */
import { describe, expect, it } from 'vitest'
import { resolveValidationMessage } from '../../src/services/manifestValidation/connectorErrorMessages.js'
import {
	propertiesOfKind,
	validateIndexViewConfig,
	validateIndexViews,
	viewModesOf,
} from '../../src/services/manifestValidation/indexViews.js'

const properties = {
	titel: { type: 'string' },
	inspectiedatum: { type: 'string', format: 'date' },
	einde: { type: 'string', format: 'date-time' },
	status: { type: 'string', enum: ['open', 'gesloten'] },
	behandelaar: { type: 'string' },
}

describe('indexViews validation', () => {
	it('keeps table first and drops unknown modes', () => {
		expect(viewModesOf({ viewModes: ['calendar', 'nonsense', 'cards'] })).toEqual(['table', 'cards', 'calendar'])
		expect(viewModesOf({})).toEqual(['table'])
	})

	it('finds a calendar without a date field', () => {
		const manifest = { pages: [{ type: 'index', config: { viewModes: ['table', 'calendar'] } }] }
		const errors = validateIndexViews(manifest)
		expect(errors).toEqual(['/pages/0/config/calendar/dateField: buildiq.indexViews.error.calendar-dateField-required'])
		expect(resolveValidationMessage(errors[0])).toBe('/pages/0/config/calendar/dateField: The calendar needs a date field.')
	})

	it('finds a board without a status field and a date axis without its dates', () => {
		const errors = validateIndexViewConfig({ viewModes: ['table', 'board', 'dateAxis'], dateAxis: { startField: 'begin' } }, '/c')
		expect(errors).toEqual([
			'/c/board/statusField: buildiq.indexViews.error.board-statusField-required',
			'/c/dateAxis/endField: buildiq.indexViews.error.dateAxis-endField-required',
		])
	})

	it('finds a field the schema lacks only when the schema is given', () => {
		const config = { viewModes: ['table', 'calendar'], calendar: { dateField: 'inspectiedatum', titleField: 'naam' } }
		expect(validateIndexViewConfig(config, '/c')).toEqual([])
		expect(validateIndexViewConfig(config, '/c', properties)).toEqual(['/c/calendar/titleField: buildiq.indexViews.error.field-unknown'])
	})

	it('ignores blocks of modes the page does not offer, and non-index pages', () => {
		expect(validateIndexViewConfig({ board: {} }, '/c', properties)).toEqual([])
		expect(validateIndexViews({ pages: [{ type: 'detail', config: { viewModes: ['calendar'] } }] })).toEqual([])
	})

	it('offers date, status and text properties by kind', () => {
		expect(propertiesOfKind(properties, 'date')).toEqual(['inspectiedatum', 'einde'])
		expect(propertiesOfKind(properties, 'status')).toEqual(['status'])
		expect(propertiesOfKind(properties, 'text')).toEqual(['titel', 'status', 'behandelaar'])
		expect(propertiesOfKind(properties, 'any')).toHaveLength(5)
	})
})
