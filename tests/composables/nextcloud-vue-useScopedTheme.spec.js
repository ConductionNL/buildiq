/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Proof-of-load regression: imports `useScopedTheme` from the INSTALLED
 * `@conduction/nextcloud-vue@1.0.0-beta.221` package via its real dist
 * subpath (bypassing vitest's `@conduction/nextcloud-vue` alias — see
 * vitest.config.js, whose alias regex only matches the bare specifier).
 * This is the same real-leaf pattern `tests/vitest/stubs/conduction-nextcloud-vue.js`
 * already uses for `createManifestEditHistory`/`mergeManifestDelta`.
 *
 * Task 0.2 (theme-picker-consumes-nldesign) requires more than a static
 * import to succeed — it requires the published composable to actually
 * RUN: fetch, cache, apply, teardown, list, evaluate. This suite exercises
 * every one of those against a mocked `@nextcloud/axios` HTTP boundary, so
 * a crash anywhere in the published beta.221 bundle (not just at import
 * time) fails CI here rather than surfacing later inside ThemePickerDialog.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (p) => p,
	generateFilePath: (app, prefix, file) => `/${app}/${prefix}/${file}`,
}))

import {
	useScopedTheme,
	SCOPE_ATTR,
	rewriteRootScope,
} from '@conduction/nextcloud-vue/dist/esm/composables/useScopedTheme.js'
import pkg from '@conduction/nextcloud-vue/package.json' with { type: 'json' }

/** Minimal fake DOM: head with appendChild + querySelectorAll. */
function fakeDoc() {
	const head = { children: [] }
	const doc = {
		head,
		createElement() {
			return {
				_attrs: {},
				setAttribute(k, v) {
					this._attrs[k] = v
				},
				getAttribute(k) {
					return this._attrs[k]
				},
				textContent: '',
				parentNode: null,
			}
		},
		querySelectorAll(sel) {
			const m = /style\[data-nldesign-theme="([^"]+)"\]/.exec(sel)
			const slug = m && m[1]
			return head.children.filter(
				(el) => el._attrs['data-nldesign-theme'] === slug,
			)
		},
	}
	head.appendChild = (el) => {
		el.parentNode = {
			removeChild: (c) => {
				head.children = head.children.filter((x) => x !== c)
			},
		}
		head.children.push(el)
	}
	return doc
}

describe('published @conduction/nextcloud-vue useScopedTheme (real dist, not the vitest stub)', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('is published at 1.0.0-beta.221 or later', () => {
		expect(pkg.version).toMatch(
			/^1\.0\.0-beta\.(22[1-9]|2[3-9]\d|[3-9]\d\d)$|^[2-9]\./,
		)
	})

	it('exports the documented scope attribute and rewriter', () => {
		expect(SCOPE_ATTR).toBe('data-nldesign-theme-scope')
		expect(rewriteRootScope(':root { --a: 1; }', '[x]')).toContain('[x] {')
	})

	it('apply() fetches, rewrites, and injects exactly one scoped style element', async () => {
		const doc = fakeDoc()
		axiosMock.get.mockResolvedValue({
			data: ':root {\n  --nldesign-color-primary: #004699;\n}\n',
		})
		const scopedTheme = useScopedTheme({ doc })
		const manifest = {
			runtime: { theme: { source: 'nldesign', tokenSet: 'amsterdam' } },
		}
		const injected = await scopedTheme.apply(manifest, 'petstore')
		expect(injected).toBe(true)
		expect(doc.head.children).toHaveLength(1)
		expect(doc.head.children[0].textContent).toContain(
			'[data-nldesign-theme-scope="petstore"]',
		)
	})

	it('apply() degrades to default styling with a warning when the token asset is unreachable (REQ-NTS-003 "Missing token asset degrades to default styling")', async () => {
		const doc = fakeDoc()
		const warn = vi.fn()
		axiosMock.get.mockRejectedValue({ response: { status: 404 } })
		const scopedTheme = useScopedTheme({ doc, warn })
		const manifest = {
			runtime: { theme: { source: 'nldesign', tokenSet: 'ghost' } },
		}
		const injected = await scopedTheme.apply(manifest, 'petstore-ghost')
		expect(injected).toBe(false)
		expect(doc.head.children).toHaveLength(0)
		expect(warn).toHaveBeenCalledTimes(1)
	})

	it('teardown() removes the managed element', async () => {
		const doc = fakeDoc()
		axiosMock.get.mockResolvedValue({ data: ':root { --x: 1; }' })
		const scopedTheme = useScopedTheme({ doc })
		await scopedTheme.apply(
			{ runtime: { theme: { source: 'nldesign', tokenSet: 'amsterdam-2' } } },
			'petstore-2',
		)
		scopedTheme.teardown('petstore-2')
		expect(doc.head.children).toHaveLength(0)
	})

	it('listTokenSets() resolves the real endpoint shape and degrades to [] on failure', async () => {
		const scopedTheme = useScopedTheme()
		axiosMock.get.mockResolvedValueOnce({
			data: { tokenSets: [{ id: 'amsterdam', name: 'Amsterdam' }] },
		})
		expect(await scopedTheme.listTokenSets()).toEqual([
			{ id: 'amsterdam', name: 'Amsterdam' },
		])
		axiosMock.get.mockRejectedValueOnce(new Error('boom'))
		expect(await scopedTheme.listTokenSets()).toEqual([])
	})

	it('evaluateContrast() resolves results and null (not []) on failure', async () => {
		const scopedTheme = useScopedTheme()
		axiosMock.post.mockResolvedValueOnce({
			data: {
				results: [{ name: 'Primary', ratio: 4.6, level: 'AA', pass: true }],
			},
		})
		expect(
			await scopedTheme.evaluateContrast(
				[{ name: 'Primary', value: '#004699', role: 'ui' }],
				'#FFFFFF',
			),
		).toEqual([{ name: 'Primary', ratio: 4.6, level: 'AA', pass: true }])
		axiosMock.post.mockRejectedValueOnce(new Error('boom'))
		expect(await scopedTheme.evaluateContrast([], '#FFFFFF')).toBeNull()
	})
})
