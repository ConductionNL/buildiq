/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the BuilderHost.vue controller paths the render spec does
 * not reach: the `slug` / `versionSlug` watchers that re-resolve the version,
 * and `dataSourcesLoader`, which scopes the register picker to the app's own
 * register and the CURRENT manifest at call time.
 */

import { describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
	fetchAppRegister: vi.fn(),
	registerScope: vi.fn(),
	fetchDataSources: vi.fn(),
	useRegisterPicker: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('../../src/services/appRegister.js', () => ({
	fetchAppRegister: mocks.fetchAppRegister,
}))
vi.mock('../../src/composables/useRegisterPicker.js', () => ({
	registerScope: mocks.registerScope,
	useRegisterPicker: mocks.useRegisterPicker,
}))
vi.mock('../../src/store/schemas.js', () => ({
	registerSlugForApp: (slug) => `buildiq-${slug}`,
}))

const BuilderHost = (await import('../../src/views/BuilderHost.vue')).default

describe('BuilderHost watchers', () => {
	it('a new slug or version slug resolves the version again', () => {
		const ctx = { resolveVersion: vi.fn() }
		BuilderHost.watch.slug.call(ctx)
		BuilderHost.watch.versionSlug.call(ctx)
		expect(ctx.resolveVersion).toHaveBeenCalledTimes(2)
	})
})

describe('BuilderHost dataSourcesLoader', () => {
	/**
	 * Wire the collaborators for one call.
	 *
	 * @return {void}
	 */
	function wire() {
		mocks.fetchAppRegister.mockReset().mockResolvedValue({ id: 9, slug: 'buildiq-petstore' })
		mocks.registerScope.mockReset().mockReturnValue({ registers: ['buildiq-petstore'] })
		mocks.fetchDataSources.mockReset().mockResolvedValue({ registers: [{ id: 9 }] })
		mocks.useRegisterPicker.mockReset().mockReturnValue({ fetchDataSources: mocks.fetchDataSources })
	}

	it('scopes the picker to the app register and the current manifest', async () => {
		wire()
		const manifest = { pages: [{ id: 'home' }] }
		const result = await BuilderHost.methods.dataSourcesLoader.call({
			slug: 'petstore',
			versionSlug: 'v2',
			applicationVersion: { manifest },
		})

		expect(mocks.fetchAppRegister).toHaveBeenCalledWith('petstore', 'v2')
		expect(mocks.registerScope).toHaveBeenCalledWith({ id: 9, slug: 'buildiq-petstore' }, manifest)
		expect(mocks.useRegisterPicker).toHaveBeenCalledWith({ appSlug: 'petstore' })
		expect(mocks.fetchDataSources).toHaveBeenCalledWith({ registers: ['buildiq-petstore'] })
		expect(result).toEqual({ registers: [{ id: 9 }] })
	})

	it('passes no manifest while the version or its manifest is missing', async () => {
		wire()
		await BuilderHost.methods.dataSourcesLoader.call({ slug: 'petstore', versionSlug: '', applicationVersion: null })
		await BuilderHost.methods.dataSourcesLoader.call({ slug: 'petstore', versionSlug: '', applicationVersion: { manifest: 'not an object' } })

		expect(mocks.registerScope.mock.calls.map((call) => call[1])).toEqual([null, null])
	})
})
