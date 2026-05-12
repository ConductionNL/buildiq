/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `useLivePreview` composable.
 *
 * Covers REQ-OBPD-008 + tasks.md item 7.4:
 *  - Arity-1 `useAppManifest` (legacy) => `available` flips to false.
 *  - Arity-2 `useAppManifest` (chain spec #2) => `available` flips to true
 *    and `previewProps(slug, manifest)` returns the prop bag.
 *  - The returned `key` is stable for identical manifest content
 *    (mitigates the "preview re-mount thrashes on every keystroke" risk
 *    documented in design.md).
 *  - `previewProps` returns null when the overload is unavailable
 *    (graceful degradation).
 *
 * Each variant gets its own dynamic import + module reset so the
 * feature-detect (`useAppManifest.length` at module-load time) runs
 * against the current mock.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'

describe('useLivePreview — graceful degradation (chain spec #2 NOT installed)', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doMock('@conduction/nextcloud-vue', () => ({
			// Legacy arity-1 shape — feature-detect returns false.
			useAppManifest: function (_appId) { return { manifest: null } },
		}))
	})

	it('available === false when useAppManifest.length === 1', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		expect(lp.available.value).toBe(false)
	})

	it('previewProps returns null while degraded', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		expect(lp.previewProps('hello-world', { pages: [] })).toBeNull()
	})

	it('exposes a stable shape (available + previewProps)', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		expect(lp).toHaveProperty('available')
		expect(typeof lp.previewProps).toBe('function')
	})
})

describe('useLivePreview — chain spec #2 overload available', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doMock('@conduction/nextcloud-vue', () => ({
			// Arity-2 shape — bumped library export with manifestObject.
			useAppManifest: function (_appId, _manifestObject) { return { manifest: null } },
		}))
	})

	it('available === true when useAppManifest.length === 2', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		expect(lp.available.value).toBe(true)
	})

	it('previewProps returns the sandbox prop bag', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		const props = lp.previewProps('hello-world', { pages: [{ id: 'home' }] })
		expect(props).not.toBeNull()
		expect(props.appId).toBe('openbuilt-preview-hello-world')
		expect(props.manifest).toEqual({ pages: [{ id: 'home' }] })
		expect(typeof props.key).toBe('string')
	})

	it('key is stable for identical manifest content', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		const a = lp.previewProps('app', { pages: [{ id: 'home', config: { register: 'r' } }] })
		const b = lp.previewProps('app', { pages: [{ id: 'home', config: { register: 'r' } }] })
		expect(a.key).toBe(b.key)
	})

	it('key changes when manifest content changes', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		const a = lp.previewProps('app', { pages: [{ id: 'home' }] })
		const b = lp.previewProps('app', { pages: [{ id: 'about' }] })
		expect(a.key).not.toBe(b.key)
	})
})

describe('useLivePreview — useAppManifest missing entirely', () => {
	beforeEach(() => {
		vi.resetModules()
		vi.doMock('@conduction/nextcloud-vue', () => ({
			// Library doesn't export the helper at all.
			useAppManifest: undefined,
		}))
	})

	it('feature-detect handles undefined export gracefully', async () => {
		const { useLivePreview } = await import('../../src/composables/useLivePreview.js')
		const lp = useLivePreview()
		expect(lp.available.value).toBe(false)
		expect(lp.previewProps('any', {})).toBeNull()
	})
})
