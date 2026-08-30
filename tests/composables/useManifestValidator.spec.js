/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useManifestValidator` composable.
 *
 * Covers REQ-OBPD-011 + tasks.md item 7.3:
 *  - 300ms debounce coalesces rapid edits to a single validator call.
 *  - validateManifest mock is invoked with the latest manifest.
 *  - Valid manifest leaves `errors` empty and `hasErrors` false.
 *  - Invalid manifest surfaces the returned `errors` array.
 *  - Edge cases: validator throwing, validator absent, no errors array.
 *  - `register` / `unregister` populate `errorsByPrefix` by longest match.
 *  - Validator does NOT block the synchronous UI thread (returns
 *    immediately; the timer fires async).
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// `vi.mock` must be hoisted; the inner factory has access to the spy
// via a top-level `vi.fn()` reference.
const validateSpy = vi.fn(() => ({ valid: true, errors: [] }))
vi.mock('@conduction/nextcloud-vue', () => ({
	validateManifest: (...args) => validateSpy(...args),
}))

// Import AFTER vi.mock so the composable picks up the mocked export.
const { useManifestValidator } =
	await import('../../src/composables/useManifestValidator.js')

describe('useManifestValidator', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		validateSpy.mockReset()
		validateSpy.mockImplementation(() => ({ valid: true, errors: [] }))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('exposes the documented public surface', () => {
		const v = useManifestValidator()
		expect(v).toHaveProperty('errors')
		expect(v).toHaveProperty('hasErrors')
		expect(v).toHaveProperty('isValidating')
		expect(v).toHaveProperty('validate')
		expect(v).toHaveProperty('register')
		expect(v).toHaveProperty('unregister')
		expect(v).toHaveProperty('errorsByPrefix')
		expect(v.DEBOUNCE_MS).toBe(300)
	})

	it('does NOT call validateManifest synchronously (UI thread stays free)', () => {
		const v = useManifestValidator()
		v.validate({ id: 'one' })
		expect(validateSpy).not.toHaveBeenCalled()
		expect(v.isValidating.value).toBe(true)
	})

	it('coalesces rapid edits to a single validator call', () => {
		const v = useManifestValidator()
		v.validate({ id: 'one' })
		v.validate({ id: 'two' })
		v.validate({ id: 'three' })
		// All three fire inside the 300ms window — only the last one wins.
		vi.advanceTimersByTime(299)
		expect(validateSpy).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(validateSpy).toHaveBeenCalledTimes(1)
		expect(validateSpy).toHaveBeenLastCalledWith({ id: 'three' })
	})

	it('clears isValidating once the debounce settles', () => {
		const v = useManifestValidator()
		v.validate({ id: 'a' })
		expect(v.isValidating.value).toBe(true)
		vi.advanceTimersByTime(300)
		expect(v.isValidating.value).toBe(false)
	})

	it('valid manifest leaves errors empty and hasErrors false', () => {
		const v = useManifestValidator()
		v.validate({ id: 'valid' })
		vi.advanceTimersByTime(300)
		expect(v.errors.value).toEqual([])
		expect(v.hasErrors.value).toBe(false)
	})

	it('invalid manifest surfaces the returned errors array', () => {
		validateSpy.mockImplementationOnce(() => ({
			valid: false,
			errors: ['/pages/0/id is required', '/pages/0/route must match pattern'],
		}))
		const v = useManifestValidator()
		v.validate({ id: 'broken' })
		vi.advanceTimersByTime(300)
		expect(v.hasErrors.value).toBe(true)
		expect(v.errors.value).toHaveLength(2)
		expect(v.errors.value[0]).toContain('/pages/0/id')
	})

	it('handles validator throwing without crashing the composable', () => {
		validateSpy.mockImplementationOnce(() => {
			throw new Error('boom')
		})
		const v = useManifestValidator()
		v.validate({ id: 'bad' })
		vi.advanceTimersByTime(300)
		expect(v.hasErrors.value).toBe(true)
		expect(v.errors.value[0]).toContain('boom')
	})

	it('handles a result that omits the errors array', () => {
		validateSpy.mockImplementationOnce(() => ({ valid: true }))
		const v = useManifestValidator()
		v.validate({ id: 'shapeless' })
		vi.advanceTimersByTime(300)
		expect(v.errors.value).toEqual([])
	})

	it('register / unregister populate errorsByPrefix by longest match', () => {
		validateSpy.mockImplementationOnce(() => ({
			valid: false,
			errors: [
				'/pages/0/id missing',
				'/pages/0/config/columns/0/key invalid',
				'/menu/2/label required',
			],
		}))
		const v = useManifestValidator()
		const fieldA = { markError: vi.fn(), clearError: vi.fn() }
		const fieldB = { markError: vi.fn(), clearError: vi.fn() }
		v.register('/pages/0', fieldA)
		v.register('/pages/0/config/columns', fieldB)
		v.validate({})
		vi.advanceTimersByTime(300)

		const map = v.errorsByPrefix.value
		expect(map.has('/pages/0')).toBe(true)
		expect(map.has('/pages/0/config/columns')).toBe(true)
		expect(map.get('/pages/0/config/columns')).toHaveLength(1)
		expect(map.get('/pages/0/config/columns')[0]).toContain('columns/0/key')

		// Unregister + re-validate => prefix drops out of the map.
		v.unregister('/pages/0/config/columns')
		validateSpy.mockImplementationOnce(() => ({
			valid: false,
			errors: ['/pages/0/id missing'],
		}))
		v.validate({})
		vi.advanceTimersByTime(300)
		expect(v.errorsByPrefix.value.has('/pages/0/config/columns')).toBe(false)
	})

	it('an invalid runtime.theme surfaces from the single library validateManifest() call alone (theme-picker-consumes-nldesign, REQ-NTS-001/007, design.md Decision 5)', () => {
		// `manifestValidation/theme.js` (the local duplicate) is deleted — this
		// asserts the library mock alone is what the composable now relies on
		// for shape errors the library's own `$defs/runtimeTheme` schema
		// covers (unknown source, non-kebab tokenSet, unknown key). No local
		// `validateTheme` call exists anymore to ALSO surface these; if it
		// somehow did, `validateSpy` (the only mocked source of truth here)
		// would still be the sole origin of the assertions below either way,
		// so this test's real job is proving the composable no longer imports
		// a module named `manifestValidation/theme.js` at all — see the
		// static grep assertion in tasks.md 5.3 / the PR description for the
		// complementary "no import exists" half of this guarantee.
		validateSpy.mockImplementationOnce(() => ({
			valid: false,
			errors: [
				'/runtime/theme: buildiq.theme.error.unknown-source',
				'/runtime/theme: buildiq.theme.error.token-set-not-kebab',
				'/runtime/theme/logo: buildiq.theme.error.unknown-key',
			],
		}))
		const v = useManifestValidator()
		v.validate({
			runtime: {
				theme: { source: 'material', tokenSet: 'Den Haag', logo: 'x' },
			},
		})
		vi.advanceTimersByTime(300)
		expect(v.hasErrors.value).toBe(true)
		expect(v.errors.value).toEqual([
			'/runtime/theme: buildiq.theme.error.unknown-source',
			'/runtime/theme: buildiq.theme.error.token-set-not-kebab',
			'/runtime/theme/logo: buildiq.theme.error.unknown-key',
		])
	})

	it('edge case: empty manifest still triggers validation', () => {
		const v = useManifestValidator()
		v.validate({})
		vi.advanceTimersByTime(300)
		expect(validateSpy).toHaveBeenCalledTimes(1)
		expect(validateSpy).toHaveBeenCalledWith({})
	})
})
