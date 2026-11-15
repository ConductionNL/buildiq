/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec pinning the edges of BackToVirtualApps' two visibility rules:
 * an owner flag that is truthy but not `true`, a runtime without a user, a
 * `pages` that is not a list, empty page entries, and a nav without a
 * primary action.
 */

import { describe, expect, it } from 'vitest'
import {
	declaresPrimaryAction,
	showBackToVirtualApps,
} from '../../../src/components/runtime/BackToVirtualApps.vue'

describe('showBackToVirtualApps edges', () => {
	it.each([
		[{ manifest: { runtime: { user: { isOwner: 'yes' } } } }, false],
		[{ manifest: { runtime: { user: { isOwner: 1 } } } }, false],
		[{ manifest: { runtime: {} } }, false],
		[{ manifest: { runtime: null } }, false],
		[{ versionSlug: '', manifest: { runtime: { user: { isOwner: true } } } }, true],
		[{ versionSlug: 'beta', manifest: null }, true],
		[{}, false],
	])('for %j is %s', (context, expected) => {
		expect(showBackToVirtualApps(context)).toBe(expected)
	})
})

describe('declaresPrimaryAction edges', () => {
	it.each([
		[{ pages: 'nope', nav: { primaryAction: { label: 'x' } } }, 'home', true],
		[{ pages: 'nope' }, 'home', false],
		[{ pages: [null, { id: 'home' }] }, 'home', false],
		[{ pages: [null, { id: 'home', primaryAction: {} }] }, 'home', true],
		[{ pages: [{ id: 'home', primaryAction: null }], nav: {} }, 'home', false],
		[{ pages: [{ id: 'other', primaryAction: {} }] }, 'home', false],
	])('for %j on %s is %s', (manifest, route, expected) => {
		expect(declaresPrimaryAction(manifest, route)).toBe(expected)
	})
})
