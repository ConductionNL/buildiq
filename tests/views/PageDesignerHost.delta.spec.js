// SPDX-License-Identifier: EUPL-1.2
/**
 * The page designer on a delta-mode version (app-delta-override 5.1 to 5.3,
 * 7.3): it edits the base with the stored delta merged over it, saves only
 * the minimal delta (never a blob), names the delta paths that no longer
 * match the base, and leaves a blob version exactly as before.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-7.3
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.fn()
const axiosPatch = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...a) => axiosGet(...a),
		patch: (...a) => axiosPatch(...a),
		put: vi.fn(),
		post: vi.fn(),
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))

const { default: Host } = await import('../../src/views/PageDesignerHost.vue')

const base = {
	version: '1.0.0',
	pages: [
		{ id: 'home', title: 'Home', type: 'dashboard' },
		{ id: 'list', title: 'List', type: 'index' },
	],
}

/**
 * A designer context with the host's methods bound.
 *
 * @param {object} state The data.
 * @return {object}
 */
function host(state) {
	const ctx = {
		routeSlug: 'derived',
		versionSlug: '',
		application: { slug: 'derived', appType: 'virtual' },
		manifest: {},
		deltaBase: null,
		orphanedDeltaPaths: [],
		...state,
	}
	for (const [name, fn] of Object.entries(Host.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

describe('PageDesignerHost on a delta-mode version', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPatch.mockReset()
	})

	it('edits the base with the stored delta merged over it, and names the orphans', async () => {
		axiosGet.mockResolvedValue({ data: { baseRef: { kind: 'template', id: 'crm' }, base } })
		const ctx = host({
			applicationVersion: {
				baseRef: { kind: 'template', id: 'crm' },
				manifestDelta: { pages: [{ id: 'list', title: 'Cases' }, { id: 'gone', $op: 'remove' }] },
			},
		})

		await ctx.seedDeltaManifest()

		expect(ctx.deltaBase).toEqual(base)
		expect(ctx.manifest.pages.map((p) => p.title)).toEqual(['Home', 'Cases'])
		expect(ctx.orphanedDeltaPaths.join(' ')).toContain('gone')
	})

	it('saves only the minimal delta onto the version, never a blob', async () => {
		axiosPatch.mockResolvedValue({ data: { '@self': { id: 'v-1' } } })
		const edited = JSON.parse(JSON.stringify(base))
		edited.pages[1].title = 'Cases'
		const ctx = host({ deltaBase: base, manifest: edited, applicationVersion: {} })

		await ctx.saveOntoVersion('v-1')

		expect(axiosPatch).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/buildiq/applicationVersion/v-1',
			{ manifestDelta: { pages: [{ id: 'list', title: 'Cases' }] } },
		)
	})

	it('falls back to the stored blob as the base when the base cannot be fetched', async () => {
		axiosGet.mockRejectedValue(new Error('down'))
		const ctx = host({
			applicationVersion: { manifest: base, baseRef: { kind: 'template', id: 'gone' }, manifestDelta: {} },
		})

		await ctx.seedDeltaManifest()

		expect(ctx.deltaBase).toEqual(base)
		expect(ctx.manifest).toEqual(base)
	})

	it('leaves a blob version and a hybrid app\'s version as before', async () => {
		const blob = host({ applicationVersion: { manifest: base }, manifest: base })
		await blob.seedDeltaManifest()
		const hybrid = host({
			application: { slug: 'derived', appType: 'hybrid' },
			applicationVersion: { manifest: {}, baseRef: { kind: 'fleet-app', id: 'pipelinq' }, manifestDelta: {} },
			manifest: {},
		})
		await hybrid.seedDeltaManifest()

		expect(axiosGet).not.toHaveBeenCalled()
		expect(blob.deltaBase).toBeNull()
		expect(hybrid.deltaBase).toBeNull()

		axiosPatch.mockResolvedValue({ data: {} })
		await blob.saveOntoVersion('v-2')
		expect(axiosPatch).toHaveBeenCalledWith('/apps/openregister/api/objects/buildiq/applicationVersion/v-2', { manifest: base })
	})
})
