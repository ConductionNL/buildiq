// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { describe, it, expect, beforeEach } from 'vitest'
import {
	resolveTargetFromElement,
	cssPath,
} from '../../src/components/walkthrough-editor/recorderTargetResolver.js'

/**
 * Spec: buildiq-walkthrough-editor recorder (ADR-043). Clicking an element on
 * the running virtual app resolves to the most stable target descriptor.
 */
describe('recorderTargetResolver', () => {
	beforeEach(() => {
		document.body.innerHTML = ''
	})

	it('prefers data-walkthrough-id (nearest stable id wins)', () => {
		document.body.innerHTML =
			'<nav data-cn-route="Products"><button data-walkthrough-id="index-add"><span id="icon">+</span></button></nav>'
		const span = document.getElementById('icon')
		expect(resolveTargetFromElement(span)).toEqual({
			kind: 'element',
			ref: 'index-add',
		})
	})

	it('resolves a nav item via data-cn-route', () => {
		document.body.innerHTML =
			'<li data-cn-route="Leads"><a><span id="lbl">Leads</span></a></li>'
		expect(resolveTargetFromElement(document.getElementById('lbl'))).toEqual({
			kind: 'nav-item',
			ref: 'Leads',
		})
	})

	it('resolves a widget via data-widget-key and an action via data-action-id', () => {
		document.body.innerHTML =
			'<div data-widget-key="revenue"><i id="w"></i></div><button data-action-id="export"><i id="a"></i></button>'
		expect(resolveTargetFromElement(document.getElementById('w'))).toEqual({
			kind: 'widget',
			ref: 'revenue',
		})
		expect(resolveTargetFromElement(document.getElementById('a'))).toEqual({
			kind: 'action',
			ref: 'export',
		})
	})

	it('falls back to data-testid, then to a CSS selector', () => {
		document.body.innerHTML =
			'<div data-testid="thing"><b id="t"></b></div><section><p id="plain">x</p></section>'
		expect(resolveTargetFromElement(document.getElementById('t'))).toEqual({
			kind: 'element',
			ref: 'thing',
		})
		const sel = resolveTargetFromElement(document.getElementById('plain'))
		expect(sel.kind).toBe('selector')
		expect(sel.selector).toContain('#plain')
	})

	it('cssPath uses an id when present and a tag/class chain otherwise', () => {
		document.body.innerHTML =
			'<div class="wrap"><ul><li class="row">a</li><li class="row" id="second">b</li></ul></div>'
		expect(cssPath(document.getElementById('second'))).toBe('#second')
		document.body.innerHTML =
			'<div class="wrap"><span class="pill">a</span><span class="pill" >b</span></div>'
		const second = document.querySelectorAll('.pill')[1]
		expect(cssPath(second)).toContain('span.pill:nth-of-type(2)')
	})

	it('returns null for a non-element', () => {
		expect(resolveTargetFromElement(null)).toBeNull()
	})
})
