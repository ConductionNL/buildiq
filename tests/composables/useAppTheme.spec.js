/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for useAppTheme — fetch, rewrite, inject, teardown.
 *
 * Spec: nldesign-theme-selection (REQ-NTS-003).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useAppTheme, rewriteRootScope, clearThemeCache } from '../../src/composables/useAppTheme.js'

const TOKEN_CSS = ':root {\n  --nldesign-color-primary: #004699;\n  --nldesign-color-bg: #FFFFFF;\n}\n'

const manifest = (tokenSet) => ({ runtime: { theme: { source: 'nldesign', tokenSet, tokenSetName: 'X' } } })

/** Minimal fake DOM: head with appendChild + querySelectorAll. */
function fakeDoc() {
	const head = { children: [] }
	const doc = {
		head,
		createElement() {
			return { _attrs: {}, setAttribute(k, v) { this._attrs[k] = v }, getAttribute(k) { return this._attrs[k] }, textContent: '', parentNode: null }
		},
		querySelectorAll(sel) {
			const m = /style\[data-openbuild-theme="([^"]+)"\]/.exec(sel)
			const slug = m && m[1]
			return head.children.filter((el) => el._attrs['data-openbuild-theme'] === slug)
		},
	}
	head.appendChild = (el) => { el.parentNode = { removeChild: (c) => { head.children = head.children.filter((x) => x !== c) } }; head.children.push(el) }
	return doc
}

describe('rewriteRootScope', () => {
	it('rewrites :root to the scoped attribute selector', () => {
		const out = rewriteRootScope(TOKEN_CSS, '[data-openbuild-theme-scope="kap"]')
		expect(out).toContain('[data-openbuild-theme-scope="kap"] {')
		expect(out).toContain('--nldesign-color-primary: #004699')
		expect(out).not.toContain(':root')
	})

	it('bails out (null) on any at-rule', () => {
		expect(rewriteRootScope(':root { --x: 1; } @media (max-width: 1px) { :root { --y: 2; } }', '[x]')).toBeNull()
	})

	it('bails out (null) on a non-:root selector', () => {
		expect(rewriteRootScope('.foo { color: red; }', '[x]')).toBeNull()
	})

	it('tolerates comments', () => {
		const out = rewriteRootScope('/* c */ :root { --a: 1; }', '[s]')
		expect(out).toContain('[s] {')
	})

	it('returns null for empty input', () => {
		expect(rewriteRootScope('', '[x]')).toBeNull()
	})
})

describe('useAppTheme', () => {
	beforeEach(() => clearThemeCache())

	it('fetches, rewrites and injects exactly one scoped style element', async () => {
		const doc = fakeDoc()
		const client = { get: vi.fn().mockResolvedValue({ data: TOKEN_CSS }) }
		const theme = useAppTheme({ doc, client })
		const injected = await theme.apply(manifest('amsterdam'), 'kap')

		expect(injected).toBe(true)
		expect(doc.head.children).toHaveLength(1)
		const el = doc.head.children[0]
		expect(el._attrs['data-openbuild-theme']).toBe('kap')
		expect(el.textContent).toContain('[data-openbuild-theme-scope="kap"]')
		expect(el.textContent).not.toContain(':root')
	})

	it('is idempotent — re-apply replaces, never duplicates', async () => {
		const doc = fakeDoc()
		const client = { get: vi.fn().mockResolvedValue({ data: TOKEN_CSS }) }
		const theme = useAppTheme({ doc, client })
		await theme.apply(manifest('amsterdam'), 'kap')
		await theme.apply(manifest('amsterdam'), 'kap')
		expect(doc.head.children).toHaveLength(1)
	})

	it('teardown removes the managed element', async () => {
		const doc = fakeDoc()
		const client = { get: vi.fn().mockResolvedValue({ data: TOKEN_CSS }) }
		const theme = useAppTheme({ doc, client })
		await theme.apply(manifest('amsterdam'), 'kap')
		theme.teardown('kap')
		expect(doc.head.children).toHaveLength(0)
	})

	it('caches per token set (one fetch for repeated applies)', async () => {
		const doc = fakeDoc()
		const client = { get: vi.fn().mockResolvedValue({ data: TOKEN_CSS }) }
		const theme = useAppTheme({ doc, client })
		await theme.apply(manifest('amsterdam'), 'a')
		await theme.apply(manifest('amsterdam'), 'b')
		expect(client.get).toHaveBeenCalledTimes(1)
	})

	it('degrades to default styling with a warning on 404', async () => {
		const doc = fakeDoc()
		const warn = vi.fn()
		const client = { get: vi.fn().mockRejectedValue({ response: { status: 404 } }) }
		const theme = useAppTheme({ doc, client, warn })
		const injected = await theme.apply(manifest('ghost'), 'kap')
		expect(injected).toBe(false)
		expect(doc.head.children).toHaveLength(0)
		expect(warn).toHaveBeenCalledTimes(1)
	})

	it('injects nothing when the stylesheet has at-rules (bail-out)', async () => {
		const doc = fakeDoc()
		const warn = vi.fn()
		const client = { get: vi.fn().mockResolvedValue({ data: '@media all { :root { --x: 1; } }' }) }
		const theme = useAppTheme({ doc, client, warn })
		const injected = await theme.apply(manifest('weird'), 'kap')
		expect(injected).toBe(false)
		expect(doc.head.children).toHaveLength(0)
		expect(warn).toHaveBeenCalledTimes(1)
	})

	it('no-ops (and clears) a themeless manifest', async () => {
		const doc = fakeDoc()
		const client = { get: vi.fn() }
		const theme = useAppTheme({ doc, client })
		const injected = await theme.apply({ runtime: {} }, 'kap')
		expect(injected).toBe(false)
		expect(client.get).not.toHaveBeenCalled()
	})
})
