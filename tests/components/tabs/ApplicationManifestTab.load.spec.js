/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning ApplicationManifestTab.vue's manifest load (the active
 * version's manifest, the record's own as fallback) and its parse-and-validate
 * step (JSON errors, validator errors, a clean pass).
 */
import { shallowMount } from '@vue/test-utils'
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, validateManifest } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), put: vi.fn(), patch: vi.fn() },
	validateManifest: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	validateManifest,
}))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key) => key
})
afterAll(() => {
	globalThis.t = realT
})

import ApplicationManifestTab from '../../../src/components/tabs/ApplicationManifestTab.vue'

function tab() {
	return shallowMount(ApplicationManifestTab).vm
}

describe('ApplicationManifestTab loadManifest', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it.each([
		[null, '{}'],
		[{}, '{}'],
		[{ manifest: { a: 1 } }, '{\n  "a": 1\n}'],
	])('shows the record manifest of %j when there is no slug', async (app, text) => {
		const vm = tab()
		await vm.loadManifest(app)
		expect(vm.manifestText).toBe(text)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})

	it('reads the active version manifest by slug', async () => {
		axiosMock.get.mockResolvedValue({ data: { pages: [] } })
		const vm = tab()
		await vm.loadManifest({ slug: 'my app', manifest: { old: true } })
		expect(axiosMock.get).toHaveBeenCalledWith('/apps/buildiq/api/applications/my%20app/manifest')
		expect(vm.manifestText).toBe('{\n  "pages": []\n}')
	})

	it('shows {} for an empty body', async () => {
		axiosMock.get.mockResolvedValue({ data: null })
		const vm = tab()
		await vm.loadManifest({ slug: 's' })
		expect(vm.manifestText).toBe('{}')
	})

	it.each([
		[new Error('down'), { manifest: { k: 1 } }, '{\n  "k": 1\n}', 'Could not load the manifest: down'],
		['plain', {}, '{}', 'Could not load the manifest: plain'],
	])('falls back to the record and reports %s', async (thrown, extra, text, error) => {
		axiosMock.get.mockImplementation(async () => {
			throw thrown
		})
		const vm = tab()
		await vm.loadManifest({ slug: 's', ...extra })
		expect(vm.manifestText).toBe(text)
		expect(vm.error).toBe(error)
	})
})

describe('ApplicationManifestTab parseAndValidate', () => {
	beforeEach(() => {
		validateManifest.mockReset()
	})

	it('reports a JSON error', () => {
		const vm = tab()
		vm.manifestText = '{bad'
		expect(vm.parseAndValidate()).toBe(null)
		expect(vm.error).toMatch(/^JSON parse error: /)
		expect(validateManifest).not.toHaveBeenCalled()
	})

	it.each([
		[{ valid: false, errors: ['a', 'b'] }, 'a; b'],
		[{ valid: false }, 'unknown'],
	])('reports validator result %j', (result, error) => {
		validateManifest.mockReturnValue(result)
		const vm = tab()
		vm.manifestText = '{"x":1}'
		expect(vm.parseAndValidate()).toBe(null)
		expect(vm.error).toBe(error)
	})

	it.each([[{ valid: true }], [null], [{}]])('passes on validator result %j', (result) => {
		validateManifest.mockReturnValue(result)
		const vm = tab()
		vm.error = 'old'
		vm.manifestText = '{"x":1}'
		expect(vm.parseAndValidate()).toEqual({ x: 1 })
		expect(validateManifest).toHaveBeenCalledWith({ x: 1 })
		expect(vm.error).toBe('')
	})
})

describe('ApplicationManifestTab parseAndValidate with an empty error list', () => {
	it('still refuses an invalid manifest with no errors listed', () => {
		validateManifest.mockReturnValue({ valid: false, errors: [] })
		const vm = tab()
		vm.error = 'old'
		vm.manifestText = '{}'
		expect(vm.parseAndValidate()).toBe(null)
		expect(vm.error).toBe('')
	})
})
