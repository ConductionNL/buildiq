/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for `src/services/pagePlacementChecks.js`
 * (v2-widget-placement-editor task 1.2).
 *
 * The exempt key list comes from the library's own `libraryWidgetKeys.js`,
 * imported by its published subpath rather than copied here. The spec asserts
 * a LIBRARY built-in key is exempt and a CUSTOM key is flagged, which is the
 * pair that goes wrong when the two lists drift: a legitimate full-page
 * library widget gets told to declare itself `type: "custom"`, which is the
 * wrong advice.
 */

import { describe, expect, it } from 'vitest'
import {
	customPageInDisguiseKey,
	isCustomPageInDisguise,
	isLibraryWidgetKey,
	pageRequiresPlacementNote,
	widgetSurfaceForPageType,
} from '../../src/services/pagePlacementChecks.js'

/**
 * A `type: "dashboard"` page with one body widget filling the grid.
 *
 * @param {string} widgetKey - the single placement's widget key.
 * @return {object} the page.
 */
function fullWidthDashboard(widgetKey) {
	return {
		id: 'home',
		type: 'dashboard',
		config: {},
		widgets: [
			{
				id: 'only',
				widgetKey,
				slot: 'body',
				gridX: 0,
				gridY: 0,
				gridWidth: 12,
				gridHeight: 12,
			},
		],
	}
}

describe('pagePlacementChecks', () => {
	describe('widgetSurfaceForPageType', () => {
		it('gives a detail page the detail surface', () => {
			expect(widgetSurfaceForPageType('detail')).toBe('detail-page')
		})

		it('gives every other page type the app dashboard surface', () => {
			for (const type of ['dashboard', 'index', 'custom', 'form', 'settings']) {
				expect(widgetSurfaceForPageType(type)).toBe('app-dashboard')
			}
			expect(widgetSurfaceForPageType(undefined)).toBe('app-dashboard')
		})
	})

	describe('isLibraryWidgetKey', () => {
		it('recognises a v2 built-in and a dashboard catalog key', () => {
			expect(isLibraryWidgetKey('object-table')).toBe(true)
			expect(isLibraryWidgetKey('stats-block')).toBe(true)
		})

		it('does not recognise an app-registered custom key', () => {
			expect(isLibraryWidgetKey('case-timeline')).toBe(false)
			expect(isLibraryWidgetKey('')).toBe(false)
			expect(isLibraryWidgetKey(null)).toBe(false)
		})
	})

	describe('customPageInDisguiseKey', () => {
		it('flags a lone full-width CUSTOM widget on a dashboard page', () => {
			expect(customPageInDisguiseKey(fullWidthDashboard('case-timeline'))).toBe(
				'case-timeline',
			)
			expect(isCustomPageInDisguise(fullWidthDashboard('case-timeline'))).toBe(
				true,
			)
		})

		it('exempts a lone full-width LIBRARY widget, which is a legitimate page', () => {
			expect(customPageInDisguiseKey(fullWidthDashboard('object-table'))).toBe(
				null,
			)
			expect(isCustomPageInDisguise(fullWidthDashboard('object-table'))).toBe(
				false,
			)
		})

		it('stops applying the moment a second placement joins, in any slot', () => {
			const page = fullWidthDashboard('case-timeline')
			page.widgets.push({
				id: 'aside',
				widgetKey: 'case-notes',
				slot: 'sidebar',
				gridX: 0,
				gridY: 0,
				gridWidth: 1,
				gridHeight: 4,
			})
			expect(customPageInDisguiseKey(page)).toBe(null)
		})

		it('does not fire on a widget that does not fill the grid', () => {
			const page = fullWidthDashboard('case-timeline')
			page.widgets[0].gridWidth = 6
			expect(customPageInDisguiseKey(page)).toBe(null)
		})

		it('does not fire outside the body slot', () => {
			const page = fullWidthDashboard('case-timeline')
			page.widgets[0].slot = 'footer'
			expect(customPageInDisguiseKey(page)).toBe(null)
		})

		it('does not fire on a page that is not a dashboard', () => {
			const page = fullWidthDashboard('case-timeline')
			page.type = 'custom'
			expect(customPageInDisguiseKey(page)).toBe(null)
		})

		it('normalises omitted coordinates the way the validator does', () => {
			const page = {
				type: 'dashboard',
				widgets: [{ widgetKey: 'case-timeline', slot: 'body' }],
			}
			expect(customPageInDisguiseKey(page)).toBe('case-timeline')
		})

		it('survives a page with no widgets at all', () => {
			expect(customPageInDisguiseKey({ type: 'dashboard' })).toBe(null)
			expect(customPageInDisguiseKey(null)).toBe(null)
		})
	})

	describe('pageRequiresPlacementNote', () => {
		it('requires a note on a custom page only', () => {
			expect(pageRequiresPlacementNote({ type: 'custom' })).toBe(true)
			expect(pageRequiresPlacementNote({ type: 'dashboard' })).toBe(false)
			expect(pageRequiresPlacementNote(null)).toBe(false)
		})
	})
})
