/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the inline-mark API of `useManifestValidator`
 * (task 5.5 / REQ-OBPD-011):
 *  - register(prefix) / unregister(prefix) populate `errorMap` with
 *    `{ hasError, message }` bags.
 *  - errorFor(prefix) is the convenience accessor.
 *  - prefix boundary: `/pages/1` does not swallow `/pages/10/...`.
 *  - "<pointer> is required" suffixes still match.
 *  - unregistered prefixes get the empty bag from errorFor.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

const validateSpy = vi.fn(() => ({ valid: true, errors: [] }))
vi.mock('@conduction/nextcloud-vue', () => ({
	validateManifest: (...args) => validateSpy(...args),
}))

const { useManifestValidator } = await import('../../src/composables/useManifestValidator.js')

function withErrors(v, errors) {
	validateSpy.mockImplementationOnce(() => ({ valid: errors.length === 0, errors }))
	v.validate({})
	vi.advanceTimersByTime(300)
}

describe('useManifestValidator — inline marks', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		validateSpy.mockReset()
		validateSpy.mockImplementation(() => ({ valid: true, errors: [] }))
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('exposes errorMap + errorFor on the public surface', () => {
		const v = useManifestValidator()
		expect(v).toHaveProperty('errorMap')
		expect(typeof v.errorFor).toBe('function')
	})

	it('register populates errorMap with a hasError/message bag', () => {
		const v = useManifestValidator()
		v.register('/pages/0/config/register')
		withErrors(v, ['/pages/0/config/register must be a non-empty string'])
		const bag = v.errorMap.value.get('/pages/0/config/register')
		expect(bag.hasError).toBe(true)
		expect(bag.message).toContain('must be a non-empty string')
	})

	it('errorFor returns the bag for a registered prefix', () => {
		const v = useManifestValidator()
		v.register('/pages/0/config/columns')
		withErrors(v, ['/pages/0/config/columns/0/key is invalid'])
		expect(v.errorFor('/pages/0/config/columns').hasError).toBe(true)
		expect(v.errorFor('/pages/0/config/columns').message).toContain('columns/0/key')
	})

	it('errorFor returns the empty bag for an unregistered prefix', () => {
		const v = useManifestValidator()
		withErrors(v, ['/pages/0/config/register bad'])
		expect(v.errorFor('/pages/0/config/register')).toEqual({ hasError: false, message: '' })
	})

	it('a registered prefix with no matching error has hasError:false', () => {
		const v = useManifestValidator()
		v.register('/pages/0/config/schema')
		withErrors(v, ['/pages/1/config/register bad'])
		const bag = v.errorMap.value.get('/pages/0/config/schema')
		expect(bag).toEqual({ hasError: false, message: '' })
	})

	it('prefix boundary: /pages/1 does not swallow /pages/10/...', () => {
		const v = useManifestValidator()
		v.register('/pages/1/config/folder')
		withErrors(v, ['/pages/10/config/folder is required'])
		expect(v.errorFor('/pages/1/config/folder').hasError).toBe(false)
	})

	it('matches a "<pointer> is required" suffix', () => {
		const v = useManifestValidator()
		v.register('/pages/2/config/folder')
		withErrors(v, ['/pages/2/config/folder is required'])
		expect(v.errorFor('/pages/2/config/folder').hasError).toBe(true)
	})

	it('matches a "<pointer>: <message>" colon form', () => {
		const v = useManifestValidator()
		v.register('/pages/0/id')
		withErrors(v, ['/pages/0/id: must match the route name pattern'])
		expect(v.errorFor('/pages/0/id').hasError).toBe(true)
	})

	it('unregister drops the prefix from the map after the next pass', () => {
		const v = useManifestValidator()
		v.register('/pages/0/config/source')
		withErrors(v, ['/pages/0/config/source bad'])
		expect(v.errorMap.value.has('/pages/0/config/source')).toBe(true)
		v.unregister('/pages/0/config/source')
		withErrors(v, ['/pages/0/config/source bad'])
		expect(v.errorMap.value.has('/pages/0/config/source')).toBe(false)
		expect(v.errorFor('/pages/0/config/source')).toEqual({ hasError: false, message: '' })
	})

	it('register(prefix, fieldRef) keeps backward-compatible two-arg form', () => {
		const v = useManifestValidator()
		const handle = { el: 'fake' }
		v.register('/pages/0/config/widgets', handle)
		withErrors(v, ['/pages/0/config/widgets/0/type bad'])
		expect(v.errorFor('/pages/0/config/widgets').hasError).toBe(true)
	})
})
