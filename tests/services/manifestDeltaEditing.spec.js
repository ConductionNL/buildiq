// SPDX-License-Identifier: EUPL-1.2
/**
 * The page designer's side of a delta-mode app (app-delta-override 7.3): a
 * version with a baseRef is edited as base + delta through the canonical
 * mergeManifestDelta, and saved as the minimal diffManifest delta, so the
 * preview and the server-resolved manifest agree.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-7.3
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...a) => axiosGet(...a) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

const { mergeManifestDelta } = await import('@conduction/nextcloud-vue')
const { deltaToSave, editableManifest, editingBase, fetchManifestBase, isDeltaMode } = await import('../../src/services/manifestDeltaEditing.js')

const base = {
	version: '1.0.0',
	pages: [
		{ id: 'home', title: 'Home', type: 'dashboard' },
		{ id: 'list', title: 'List', type: 'index' },
	],
}

describe('manifestDeltaEditing', () => {
	beforeEach(() => {
		axiosGet.mockReset()
	})

	it('treats a version with a base as delta mode, unless it is a hybrid app\'s', () => {
		const version = { baseRef: { kind: 'template', id: 'crm' } }

		expect(isDeltaMode(version, { appType: 'virtual' })).toBe(true)
		expect(isDeltaMode(version, { appType: 'hybrid' })).toBe(false)
		expect(isDeltaMode({ manifest: base }, {})).toBe(false)
		expect(isDeltaMode({ baseRef: { kind: ' ' } }, {})).toBe(false)
		expect(isDeltaMode(null, null)).toBe(false)
	})

	it('saves the minimal delta, and the delta merged over the base is the edit', () => {
		const edited = JSON.parse(JSON.stringify(base))
		edited.pages[0].title = 'Start'

		const delta = deltaToSave(base, edited)

		expect(delta).toEqual({ pages: [{ id: 'home', title: 'Start' }] })
		expect(mergeManifestDelta(base, delta).manifest).toEqual(edited)
	})

	it('saves an empty delta when nothing changed', () => {
		expect(deltaToSave(base, JSON.parse(JSON.stringify(base)))).toEqual({})
	})

	it('edits the base with the stored delta merged over it, and reports orphans', () => {
		const { manifest, orphanedDeltaPaths } = editableManifest(base, {
			pages: [{ id: 'list', title: 'Cases' }, { id: 'gone', $op: 'remove' }],
		})

		expect(manifest.pages.map((p) => p.title)).toEqual(['Home', 'Cases'])
		expect(orphanedDeltaPaths.join(' ')).toContain('gone')
		expect(editableManifest(base, null).manifest).toEqual(base)
	})

	it('falls back to the stored blob when the base cannot be resolved', () => {
		expect(editingBase(base, { manifest: { pages: [] } })).toBe(base)
		expect(editingBase(null, { manifest: base })).toBe(base)
		expect(editingBase(null, {})).toEqual({})
	})

	it('asks the server for the base of the version on screen', async () => {
		axiosGet.mockResolvedValue({ data: { baseRef: { kind: 'template', id: 'crm' }, base } })

		const found = await fetchManifestBase('hello-world', 'development')

		expect(axiosGet).toHaveBeenCalledWith('/apps/buildiq/api/applications/hello-world/manifest-base', { params: { _version: 'development' } })
		expect(found).toEqual({ baseRef: { kind: 'template', id: 'crm' }, base })
	})

	it('reads a missing base as null', async () => {
		axiosGet.mockResolvedValue({ data: { baseRef: null, base: null } })

		expect(await fetchManifestBase('hello-world')).toEqual({ baseRef: null, base: null })
		expect(axiosGet).toHaveBeenCalledWith('/apps/buildiq/api/applications/hello-world/manifest-base', undefined)
	})
})
