/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Unit coverage for the builder-context Schemas navigation entry (REQ-OBR-007a).
 */

import { describe, it, expect } from 'vitest'
import { reactive } from 'vue'
import {
	BUILDER_SCHEMAS_MENU_ID,
	buildSchemasMenuEntry,
	showBuilderSchemasEntry,
	hideBuilderSchemasEntry,
} from '../../src/store/builderMenu.js'

/** Stand-in for `@nextcloud/router`'s generateUrl. */
const urlBuilder = (path) => `/index.php${path}`
/** Stand-in for the app's global `t`. */
const translate = (_app, key) => key

/**
 * A manifest shaped like the merged one main.js builds.
 *
 * @return {object} Reactive manifest with a menu array.
 */
const manifestFixture = () => reactive({
	menu: [
		{ id: 'Dashboard', label: 'Dashboard', route: 'Dashboard', order: 10 },
		{ id: 'VirtualApps', label: 'Apps', route: 'VirtualApps', order: 20 },
	],
})

describe('builderMenu (REQ-OBR-007a)', () => {
	it('addresses the app-scoped schemas route, not a generic shortcut', () => {
		const entry = buildSchemasMenuEntry('hello-world', urlBuilder, translate)
		expect(entry.id).toBe(BUILDER_SCHEMAS_MENU_ID)
		expect(entry.href).toBe('/index.php/apps/openbuild/builder/hello-world/schemas')
		// The requirement names this translation key explicitly.
		expect(entry.label).toBe('openbuild.builder.menu.schemas')
		// It must NOT carry a `route`: CnAppNav.itemTo() emits only {name, query}
		// and would drop the :slug, silently sending the user to a broken route.
		expect(entry.route).toBeUndefined()
	})

	it('appends the entry to the live menu', () => {
		const manifest = manifestFixture()
		showBuilderSchemasEntry(manifest, 'hello-world', urlBuilder, translate)
		const entries = manifest.menu.filter((m) => m.id === BUILDER_SCHEMAS_MENU_ID)
		expect(entries).toHaveLength(1)
		expect(entries[0].href).toContain('/builder/hello-world/schemas')
	})

	it('replaces rather than accumulates when the open app changes', () => {
		const manifest = manifestFixture()
		showBuilderSchemasEntry(manifest, 'hello-world', urlBuilder, translate)
		showBuilderSchemasEntry(manifest, 'hello-world', urlBuilder, translate)
		showBuilderSchemasEntry(manifest, 'other-app', urlBuilder, translate)

		const entries = manifest.menu.filter((m) => m.id === BUILDER_SCHEMAS_MENU_ID)
		expect(entries, 'exactly one entry regardless of how often it is published').toHaveLength(1)
		expect(entries[0].href).toContain('/builder/other-app/schemas')
		// The app's own entries are untouched.
		expect(manifest.menu.filter((m) => m.id === 'Dashboard')).toHaveLength(1)
	})

	it('removes the entry when the builder context is left', () => {
		const manifest = manifestFixture()
		showBuilderSchemasEntry(manifest, 'hello-world', urlBuilder, translate)
		hideBuilderSchemasEntry(manifest)
		expect(manifest.menu.some((m) => m.id === BUILDER_SCHEMAS_MENU_ID)).toBe(false)
		// …and leaves the manifest's own entries alone.
		expect(manifest.menu.map((m) => m.id)).toEqual(['Dashboard', 'VirtualApps'])
	})

	it('is a no-op on a manifest with no menu, rather than throwing', () => {
		expect(() => showBuilderSchemasEntry(null, 'x', urlBuilder, translate)).not.toThrow()
		expect(() => showBuilderSchemasEntry({}, 'x', urlBuilder, translate)).not.toThrow()
		expect(() => hideBuilderSchemasEntry(null)).not.toThrow()
	})

	it('does not publish an entry without a slug', () => {
		const manifest = manifestFixture()
		showBuilderSchemasEntry(manifest, '', urlBuilder, translate)
		expect(manifest.menu.some((m) => m.id === BUILDER_SCHEMAS_MENU_ID)).toBe(false)
	})
})
