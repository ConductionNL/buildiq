/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for useAppCustomTheme — CSS generation, injection, teardown,
 * and the nldesign-precedence integration test required by tasks.md 4.2.
 *
 * Spec: app-theming (requirements "Theme applies via the existing scoped
 * CSS-variable mechanism" and "An active nldesign theme takes precedence
 * over appTheme colors").
 */
import { describe, it, expect } from 'vitest'
import { useAppCustomTheme, buildAppThemeCss, SCOPE_ATTR } from '../../src/composables/useAppCustomTheme.js'
import { rewriteRootScope } from '../../src/composables/useAppTheme.js'

const theme = () => ({
	primaryColor: '#1D4ED8',
	secondaryColor: '#0F172A',
	accentColor: '#B45309',
	headerStyle: 'branded',
})

const manifest = (appTheme) => ({ runtime: { appTheme } })

/** Minimal fake DOM: head with appendChild + querySelectorAll (mirrors useAppTheme.spec.js's fakeDoc). */
function fakeDoc() {
	const head = { children: [] }
	const doc = {
		head,
		createElement() {
			return { _attrs: {}, setAttribute(k, v) { this._attrs[k] = v }, getAttribute(k) { return this._attrs[k] }, textContent: '', parentNode: null }
		},
		querySelectorAll(sel) {
			const m = /style\[data-openbuild-app-theme="([^"]+)"\]/.exec(sel)
			const slug = m && m[1]
			return head.children.filter((el) => el._attrs['data-openbuild-app-theme'] === slug)
		},
	}
	head.appendChild = (el) => { el.parentNode = { removeChild: (c) => { head.children = head.children.filter((x) => x !== c) } }; head.children.push(el) }
	return doc
}

/**
 * Minimal CSS custom-property "cascade + var()" resolver for one scope
 * selector's declaration block, used ONLY to prove the precedence
 * mechanism in a jsdom environment (jsdom's getComputedStyle does not
 * resolve custom properties / var(), so this is the closest honest proxy
 * to the real browser cascade for a unit test). Declarations from a
 * LATER block override an EARLIER block's declaration of the SAME name
 * (mirrors normal CSS cascade order); `var(--x, fallback)` resolves `--x`
 * against the merged map, falling back to the literal when `--x` is
 * undefined anywhere in the merge.
 *
 * @param {string[]} cssBlocks - style text, in DOM injection order (first = earliest).
 * @return {Map<string, string>} - resolved custom-property name → value.
 */
function resolveCascade(cssBlocks) {
	const raw = new Map()
	for (const css of cssBlocks) {
		const re = /(--[a-zA-Z0-9-]+)\s*:\s*([^;]+);/g
		let m
		while ((m = re.exec(css)) !== null) {
			raw.set(m[1], m[2].trim())
		}
	}
	const resolved = new Map()
	function resolve(name, seen = new Set()) {
		if (resolved.has(name)) {
			return resolved.get(name)
		}
		if (seen.has(name)) {
			return null // circular guard
		}
		seen.add(name)
		const value = raw.get(name)
		if (value === undefined) {
			return null
		}
		const varMatch = /^var\((--[a-zA-Z0-9-]+)\s*,\s*(.+)\)$/.exec(value)
		if (varMatch) {
			const inner = resolve(varMatch[1], seen)
			const result = inner !== null ? inner : varMatch[2].trim()
			resolved.set(name, result)
			return result
		}
		resolved.set(name, value)
		return value
	}
	for (const name of raw.keys()) {
		resolve(name)
	}
	return resolved
}

describe('buildAppThemeCss', () => {
	it('scopes declarations to the given selector and never emits :root', () => {
		const css = buildAppThemeCss(theme(), '[data-openbuild-theme-scope="kap"]')
		expect(css).toContain('[data-openbuild-theme-scope="kap"] {')
		expect(css).not.toContain(':root')
	})

	it('maps primaryColor onto --color-primary/--color-primary-element via an --nldesign-color-primary fallback chain', () => {
		const css = buildAppThemeCss(theme(), '[s]')
		expect(css).toContain('--color-primary: var(--nldesign-color-primary, #1D4ED8);')
		expect(css).toContain('--color-primary-element: var(--nldesign-color-primary, #1D4ED8);')
	})

	it('maps secondary/accent onto app-scoped --ob-theme-* custom properties', () => {
		const css = buildAppThemeCss(theme(), '[s]')
		expect(css).toContain('--ob-theme-secondary: #0F172A;')
		expect(css).toContain('--ob-theme-accent: #B45309;')
	})

	it('derives an accessible primary-element-text color', () => {
		const css = buildAppThemeCss({ ...theme(), primaryColor: '#0F172A' }, '[s]')
		expect(css).toMatch(/--color-primary-element-text: var\(--nldesign-color-primary-text, #ffffff\);/)
	})
})

describe('useAppCustomTheme', () => {
	it('injects exactly one scoped style element', () => {
		const doc = fakeDoc()
		const applier = useAppCustomTheme({ doc })
		const injected = applier.apply(manifest(theme()), 'kap')

		expect(injected).toBe(true)
		expect(doc.head.children).toHaveLength(1)
		const el = doc.head.children[0]
		expect(el._attrs['data-openbuild-app-theme']).toBe('kap')
		expect(el.textContent).toContain(`[${SCOPE_ATTR}="kap"]`)
	})

	it('is idempotent — re-apply replaces, never duplicates', () => {
		const doc = fakeDoc()
		const applier = useAppCustomTheme({ doc })
		applier.apply(manifest(theme()), 'kap')
		applier.apply(manifest(theme()), 'kap')
		expect(doc.head.children).toHaveLength(1)
	})

	it('teardown removes the managed element', () => {
		const doc = fakeDoc()
		const applier = useAppCustomTheme({ doc })
		applier.apply(manifest(theme()), 'kap')
		applier.teardown('kap')
		expect(doc.head.children).toHaveLength(0)
	})

	it('no-ops (and clears) a themeless manifest', () => {
		const doc = fakeDoc()
		const applier = useAppCustomTheme({ doc })
		const injected = applier.apply({ runtime: {} }, 'kap')
		expect(injected).toBe(false)
		expect(doc.head.children).toHaveLength(0)
	})

	it('no-ops when a required color is missing (shape validation catches it separately)', () => {
		const doc = fakeDoc()
		const applier = useAppCustomTheme({ doc })
		const injected = applier.apply(manifest({ primaryColor: '#1D4ED8' }), 'kap')
		expect(injected).toBe(false)
	})
})

describe('nldesign precedence (tasks.md 4.2 acceptance)', () => {
	it('nldesign wins for --color-primary when both appliers are active in the same scope', () => {
		const doc = fakeDoc()
		const customTheme = useAppCustomTheme({ doc })

		// 1. appCustomTheme injected FIRST (design.md Decision D3).
		customTheme.apply(manifest(theme()), 'kap')
		const appThemeCss = doc.head.children[0].textContent

		// 2. nldesign's scoped applier injected SECOND — same rewriter
		//    useAppTheme.js uses on a real fetched token CSS sample
		//    (amsterdam.css sets ONLY --nldesign-* names, never --color-*).
		const nldesignTokenCss = ':root {\n  --nldesign-color-primary: #004699;\n}\n'
		const nldesignCss = rewriteRootScope(nldesignTokenCss, '[data-openbuild-theme-scope="kap"]')

		const resolved = resolveCascade([appThemeCss, nldesignCss])
		expect(resolved.get('--color-primary')).toBe('#004699')
		expect(resolved.get('--color-primary-element')).toBe('#004699')
	})

	it('falls back to the appTheme color when nldesign is not active in this scope', () => {
		const doc = fakeDoc()
		const customTheme = useAppCustomTheme({ doc })
		customTheme.apply(manifest(theme()), 'kap')
		const appThemeCss = doc.head.children[0].textContent

		const resolved = resolveCascade([appThemeCss])
		expect(resolved.get('--color-primary')).toBe('#1D4ED8')
	})
})
