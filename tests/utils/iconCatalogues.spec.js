/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for resolveAppIcon SVG sanitization (harden-xss-dos-csrf,
 * app-icon-management). Author-supplied SVG must be DOMPurify-sanitized before
 * it is previewed or persisted, so an embedded <script> / event handler cannot
 * execute.
 */
import { describe, it, expect } from 'vitest'
import { resolveAppIcon } from '../../src/utils/iconCatalogues.js'

describe('resolveAppIcon — author SVG sanitization', () => {
	it('strips a <script> element from author SVG', () => {
		const malicious = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect width="1" height="1"/></svg>'
		const out = resolveAppIcon(malicious)
		expect(typeof out).toBe('string')
		expect(out.toLowerCase()).not.toContain('<script')
		expect(out.toLowerCase()).not.toContain('alert(1)')
	})

	it('strips event-handler attributes from author SVG', () => {
		const malicious = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><image href="x" onerror="alert(1)"/></svg>'
		const out = resolveAppIcon(malicious)
		expect(out.toLowerCase()).not.toContain('onload')
		expect(out.toLowerCase()).not.toContain('onerror')
	})

	it('preserves a benign author SVG', () => {
		const safe = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg>'
		const out = resolveAppIcon(safe)
		expect(out).toContain('<svg')
		expect(out).toContain('<path')
	})

	it('returns null for empty or non-string input', () => {
		expect(resolveAppIcon('')).toBeNull()
		expect(resolveAppIcon('   ')).toBeNull()
		expect(resolveAppIcon(null)).toBeNull()
	})
})
