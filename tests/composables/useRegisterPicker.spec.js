/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useRegisterPicker` composable.
 *
 * Covers REQ-OBFFUI-001 (retrofit-2026-05-26-frontend-foundation):
 *  - resolveAppRegister: returns 'openbuild-{slug}' when slug set, '' otherwise.
 *  - fetchRegisters: returns array of registers; per-app register sorted first.
 *  - fetchRegisters: returns [] on HTTP error or network failure.
 *  - fetchSchemas: returns schemas for a given register.
 *  - fetchSchemas: returns [] when register is empty / request fails.
 *  - fetchSchemaProperties: returns properties map for a register+schema pair.
 *  - fetchSchemaProperties: returns {} when params empty / request fails.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

// Stub @nextcloud/router and @nextcloud/auth before importing the composable.
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

vi.mock('@nextcloud/auth', () => ({
	getRequestToken: () => 'test-token',
}))

// We patch global.fetch per test so the composable's raw fetch calls are
// intercepted without spinning up a server.
import { useRegisterPicker } from '../../src/composables/useRegisterPicker.js'

describe('useRegisterPicker — REQ-OBFFUI-001', () => {
	let originalFetch

	beforeEach(() => {
		originalFetch = global.fetch
	})

	afterEach(() => {
		global.fetch = originalFetch
	})

	// ------------------------------------------------------------------ //
	// resolveAppRegister                                                    //
	// ------------------------------------------------------------------ //

	describe('resolveAppRegister', () => {
		it("returns 'openbuild-{slug}' when appSlug is set", () => {
			const { resolveAppRegister } = useRegisterPicker({ appSlug: 'my-app' })
			expect(resolveAppRegister()).toBe('openbuild-my-app')
		})

		it("returns '' when appSlug is not provided", () => {
			const { resolveAppRegister } = useRegisterPicker()
			expect(resolveAppRegister()).toBe('')
		})

		it("returns '' when appSlug is an empty string", () => {
			const { resolveAppRegister } = useRegisterPicker({ appSlug: '' })
			expect(resolveAppRegister()).toBe('')
		})
	})

	// ------------------------------------------------------------------ //
	// fetchRegisters                                                        //
	// ------------------------------------------------------------------ //

	describe('fetchRegisters', () => {
		it('returns the registers list when the request succeeds', async () => {
			const registers = [{ id: 'r1', slug: 'common' }, { id: 'r2', slug: 'other' }]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker()
			const result = await fetchRegisters()
			expect(result).toEqual(registers)
		})

		it('hoists the per-app register to the first position', async () => {
			const registers = [
				{ id: 'r1', slug: 'other' },
				{ id: 'r2', slug: 'openbuild-my-app' },
			]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({ appSlug: 'my-app' })
			const result = await fetchRegisters()
			expect(result[0].slug).toBe('openbuild-my-app')
		})

		it('returns [] when the response is not ok', async () => {
			global.fetch = vi.fn().mockResolvedValue({ ok: false })

			const { fetchRegisters } = useRegisterPicker()
			const result = await fetchRegisters()
			expect(result).toEqual([])
		})

		it('returns [] on network failure', async () => {
			global.fetch = vi.fn().mockRejectedValue(new Error('network error'))

			const { fetchRegisters } = useRegisterPicker()
			const result = await fetchRegisters()
			expect(result).toEqual([])
		})

		it('handles a bare array response (not wrapped in results)', async () => {
			const registers = [{ id: 'r1', slug: 'bare' }]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => registers,
			})

			const { fetchRegisters } = useRegisterPicker()
			const result = await fetchRegisters()
			expect(result).toEqual(registers)
		})
	})

	// ------------------------------------------------------------------ //
	// fetchRegisters — dataRegisters labelling/hoisting                     //
	// (data-registers-runtime REQ: page-designer-ui)                       //
	// ------------------------------------------------------------------ //

	describe('fetchRegisters — dataRegisters', () => {
		it('labels a matching entry with binding.label when set', async () => {
			const registers = [
				{ id: 'r1', slug: 'spectr' },
				{ id: 'r2', slug: 'other' },
			]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({
				dataRegisters: [{ register: 'spectr', label: 'Spectr market intelligence data' }],
			})
			const result = await fetchRegisters()
			const spectr = result.find((r) => r.slug === 'spectr')
			expect(spectr.label).toBe('Spectr market intelligence data')
		})

		it('falls back to the raw slug when binding.label is absent', async () => {
			const registers = [{ id: 'r1', slug: 'spectr' }]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({
				dataRegisters: [{ register: 'spectr' }],
			})
			const result = await fetchRegisters()
			expect(result[0].label).toBe('spectr')
		})

		it('hoists in order: per-app register, then dataRegisters bindings (declaration order), then the rest', async () => {
			const registers = [
				{ id: 'r1', slug: 'zzz-unrelated' },
				{ id: 'r2', slug: 'bag-adressen' },
				{ id: 'r3', slug: 'openbuild-my-app' },
				{ id: 'r4', slug: 'brp-personen' },
			]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({
				appSlug: 'my-app',
				dataRegisters: [
					{ register: 'brp-personen', label: 'BRP personen' },
					{ register: 'bag-adressen', label: 'BAG adressen' },
				],
			})
			const result = await fetchRegisters()
			expect(result.map((r) => r.slug)).toEqual([
				'openbuild-my-app',
				'brp-personen',
				'bag-adressen',
				'zzz-unrelated',
			])
		})

		it('does not label or reorder entries when dataRegisters is not passed (regression)', async () => {
			const registers = [
				{ id: 'r1', slug: 'other' },
				{ id: 'r2', slug: 'openbuild-my-app' },
			]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({ appSlug: 'my-app' })
			const result = await fetchRegisters()
			expect(result).toEqual([
				{ id: 'r2', slug: 'openbuild-my-app' },
				{ id: 'r1', slug: 'other' },
			])
			expect(result.every((r) => !('label' in r))).toBe(true)
		})

		it('does not label or reorder entries when dataRegisters is an empty array (regression)', async () => {
			const registers = [{ id: 'r1', slug: 'other' }]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: registers }),
			})

			const { fetchRegisters } = useRegisterPicker({ dataRegisters: [] })
			const result = await fetchRegisters()
			expect(result).toEqual(registers)
		})
	})

	// ------------------------------------------------------------------ //
	// fetchSchemas                                                          //
	// ------------------------------------------------------------------ //

	describe('fetchSchemas', () => {
		it('returns schemas for a given register', async () => {
			const schemas = [{ id: 's1', slug: 'person' }]
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ results: schemas }),
			})

			const { fetchSchemas } = useRegisterPicker()
			const result = await fetchSchemas('my-register')
			expect(result).toEqual(schemas)
		})

		it('returns [] when register is empty', async () => {
			global.fetch = vi.fn()

			const { fetchSchemas } = useRegisterPicker()
			const result = await fetchSchemas('')
			expect(result).toEqual([])
			expect(global.fetch).not.toHaveBeenCalled()
		})

		it('returns [] on HTTP error', async () => {
			global.fetch = vi.fn().mockResolvedValue({ ok: false })

			const { fetchSchemas } = useRegisterPicker()
			const result = await fetchSchemas('r')
			expect(result).toEqual([])
		})

		it('returns [] on network failure', async () => {
			global.fetch = vi.fn().mockRejectedValue(new Error('timeout'))

			const { fetchSchemas } = useRegisterPicker()
			const result = await fetchSchemas('r')
			expect(result).toEqual([])
		})
	})

	// ------------------------------------------------------------------ //
	// fetchSchemaProperties                                                 //
	// ------------------------------------------------------------------ //

	describe('fetchSchemaProperties', () => {
		it('returns the properties map when the request succeeds', async () => {
			const properties = { name: { type: 'string' } }
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ properties }),
			})

			const { fetchSchemaProperties } = useRegisterPicker()
			const result = await fetchSchemaProperties('my-register', 'person')
			expect(result).toEqual(properties)
		})

		it('returns {} when register or schema is empty', async () => {
			global.fetch = vi.fn()

			const { fetchSchemaProperties } = useRegisterPicker()
			expect(await fetchSchemaProperties('', 'person')).toEqual({})
			expect(await fetchSchemaProperties('r', '')).toEqual({})
			expect(global.fetch).not.toHaveBeenCalled()
		})

		it('returns {} on HTTP error', async () => {
			global.fetch = vi.fn().mockResolvedValue({ ok: false })

			const { fetchSchemaProperties } = useRegisterPicker()
			const result = await fetchSchemaProperties('r', 's')
			expect(result).toEqual({})
		})

		it('returns {} on network failure', async () => {
			global.fetch = vi.fn().mockRejectedValue(new Error('offline'))

			const { fetchSchemaProperties } = useRegisterPicker()
			const result = await fetchSchemaProperties('r', 's')
			expect(result).toEqual({})
		})

		it('falls back to schema.properties when top-level properties absent', async () => {
			const properties = { age: { type: 'integer' } }
			global.fetch = vi.fn().mockResolvedValue({
				ok: true,
				json: async () => ({ schema: { properties } }),
			})

			const { fetchSchemaProperties } = useRegisterPicker()
			const result = await fetchSchemaProperties('r', 's')
			expect(result).toEqual(properties)
		})
	})
})
