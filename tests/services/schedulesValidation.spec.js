/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for app-side top-level schedules[] validation.
 *
 * Spec: schedules-editor / openbuild-schedules-authoring (REQ-OBSA-006).
 */
import { describe, it, expect } from 'vitest'
import {
	validateSchedules,
	validateScheduleEntry,
	isValidCron,
	SCHEDULE_ACTIONS,
} from '../../src/services/manifestValidation/schedules.js'

const validEntry = {
	id: 'nightly-brp-sync',
	enabled: true,
	interval: 86400,
	action: 'openconnector:synchronization',
	arguments: { synchronizationId: '00000000-0000-0000-0000-000000000000' },
}

const withSchedules = (schedules) => ({ schedules })

describe('isValidCron', () => {
	it('accepts a 5-field expression', () => {
		expect(isValidCron('0 3 * * 1')).toBe(true)
		expect(isValidCron('*/15 * * * *')).toBe(true)
		expect(isValidCron('0 0 1-15 * 1,3,5')).toBe(true)
	})
	it('rejects the wrong field count', () => {
		expect(isValidCron('0 2 * *')).toBe(false)
		expect(isValidCron('0 2 * * * *')).toBe(false)
	})
	it('rejects malformed tokens', () => {
		expect(isValidCron('0 2 * * abc')).toBe(false)
		expect(isValidCron('')).toBe(false)
	})
})

describe('validateSchedules', () => {
	it('passes a valid schedules array', () => {
		expect(validateSchedules(withSchedules([validEntry]))).toEqual([])
	})

	it('passes a valid cron entry', () => {
		const cronEntry = {
			id: 'weekly-report',
			enabled: true,
			cron: '0 3 * * 1',
			action: 'openconnector:synchronization',
			arguments: { synchronizationId: 'abc' },
		}
		expect(validateSchedules(withSchedules([cronEntry]))).toEqual([])
	})

	it('returns nothing when schedules is absent', () => {
		expect(validateSchedules({})).toEqual([])
		expect(validateSchedules({ pages: [] })).toEqual([])
	})

	it('rejects a non-array schedules key', () => {
		const errs = validateSchedules(withSchedules({}))
		expect(errs.some((e) => e.includes('not-array'))).toBe(true)
	})

	it('rejects both interval and cron (one-of)', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, cron: '0 3 * * 1' }]),
		)
		expect(errs.some((e) => e.includes('cadence-both'))).toBe(true)
	})

	it('rejects neither interval nor cron', () => {
		const { interval, ...rest } = validEntry // eslint-disable-line no-unused-vars
		const errs = validateSchedules(withSchedules([rest]))
		expect(errs.some((e) => e.includes('cadence-required'))).toBe(true)
	})

	it('rejects a non-positive interval', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, interval: 0 }]),
		)
		expect(errs.some((e) => e.includes('interval-invalid'))).toBe(true)
	})

	it('rejects a non-integer interval', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, interval: 1.5 }]),
		)
		expect(errs.some((e) => e.includes('interval-invalid'))).toBe(true)
	})

	it('rejects a malformed cron', () => {
		const { interval, ...rest } = validEntry // eslint-disable-line no-unused-vars
		const errs = validateSchedules(withSchedules([{ ...rest, cron: '0 2 * *' }]))
		expect(errs.some((e) => e.includes('cron-invalid'))).toBe(true)
	})

	it('rejects a non-allow-listed action', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, action: 'openconnector:job' }]),
		)
		expect(errs.some((e) => e.includes('action-unsupported'))).toBe(true)
	})

	it('rejects a missing synchronization id for the sync action', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, arguments: {} }]),
		)
		expect(errs.some((e) => e.includes('synchronization-required'))).toBe(true)
	})

	it('rejects an empty synchronization id', () => {
		const errs = validateSchedules(
			withSchedules([
				{ ...validEntry, arguments: { synchronizationId: '  ' } },
			]),
		)
		expect(errs.some((e) => e.includes('synchronization-required'))).toBe(true)
	})

	it('rejects a missing id', () => {
		const { id, ...rest } = validEntry // eslint-disable-line no-unused-vars
		const errs = validateSchedules(withSchedules([rest]))
		expect(errs.some((e) => e.includes('id-required'))).toBe(true)
	})

	it('rejects a non-slug id', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, id: 'Not A Slug' }]),
		)
		expect(errs.some((e) => e.includes('id-not-slug'))).toBe(true)
	})

	it('rejects duplicate ids', () => {
		const errs = validateSchedules(
			withSchedules([validEntry, { ...validEntry }]),
		)
		expect(errs.some((e) => e.includes('duplicate-id'))).toBe(true)
	})

	it('rejects an unknown key', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, foo: 'bar' }]),
		)
		expect(errs.some((e) => e.includes('unknown-key'))).toBe(true)
	})

	it('rejects a non-boolean enabled', () => {
		const errs = validateSchedules(
			withSchedules([{ ...validEntry, enabled: 'yes' }]),
		)
		expect(errs.some((e) => e.includes('enabled-invalid'))).toBe(true)
	})

	it('rejects an entry that is not an object', () => {
		const errs = validateSchedules(withSchedules(['nope']))
		expect(errs.some((e) => e.includes('invalid-shape'))).toBe(true)
	})
})

describe('validateScheduleEntry', () => {
	it('validates a single entry without uniqueness', () => {
		expect(validateScheduleEntry(validEntry)).toEqual([])
	})
	it('only allows the synchronization action in v1', () => {
		expect(SCHEDULE_ACTIONS).toEqual(['openconnector:synchronization'])
	})
})
