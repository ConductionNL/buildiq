/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * The page is declared in JSON and resolves two formatters, one handler and
 * one icon by NAME. A misspelled name renders a raw enum, no glyph, or an Add
 * integration that does nothing, and none of them logs a thing. So this spec
 * reads the real fragment and checks every name against what has to answer it.
 * The two formatters are nextcloud-vue built-ins since 3.2.0.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import { BUILT_IN_FORMATTERS } from '@conduction/nextcloud-vue/src/utils/builtInFormatters.js'
import { shallowMount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/services/connectionRegistry.js'

vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => {},
}))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({ getIsAdmin: false }),
}))

import App from '../../src/App.vue'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const fragment = JSON.parse(read('src', 'manifest.d', '80-connection-registry.json'))
const page = fragment.pages.find((p) => p.id === 'Integrations')
const menu = fragment.menu.find((m) => m.id === 'IntegrationsMenu')

/**
 * The formatter registry the Integrations page renders with, built the way
 * CnAppRoot builds it: the app's own `formatters` prop spread OVER the
 * built-ins. A same-named local formatter shadows the built-in in silence.
 *
 * @return {object} The merged registry.
 */
function effectiveFormatters() {
	const wrapper = shallowMount(App, { props: { manifest: {} } })
	const root = wrapper.findComponent({ name: 'CnAppRoot' })
	const own = root.vm.$attrs.formatters ?? {}
	wrapper.unmount()
	return { ...BUILT_IN_FORMATTERS, ...own }
}

describe('connection formatters', () => {
	// A copy that predates `disabled` renders the raw word on a connection an
	// admin switched off. The built-in names it.
	it('come from the library, so a switched-off connection reads Switched off', () => {
		const formatters = effectiveFormatters()

		expect(formatters.connectionStatus('disabled')).toBe('Switched off')
		expect(formatters.connectionSettingsLabel('')).toBe('')
	})

	it('ships an English and a Dutch catalogue entry for every label the page shows', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
			page.title,
			menu.label,
			page.config.folderSidebar.allLabel,
			...page.config.headerActions.map((a) => a.label),
			...page.config.columns.map((c) => c.label),
		]
		for (const label of labels) {
			expect(en[label], `en: ${label}`).toBe(label)
			expect(nl[label], `nl: ${label}`).toBeTruthy()
		}
	})
})

describe('Add integration handler', () => {
	it('opens integriq on the link dialog, preset to buildiq', () => {
		const opened = []
		const handlers = createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe(
			'/apps/integriq/connections?app=buildiq&link=1',
		)
		expect(opened).toEqual([
			'/index.php/apps/integriq/connections?app=buildiq&link=1',
		])
	})
})

describe('the Integrations page declaration', () => {
	it('lists integriq app_connection rows, admin only, and requires integriq', () => {
		expect(page.type).toBe('index')
		expect(page.route).toBe('/settings/integrations')
		expect(page.permission).toBe('admin')
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
		expect(page.config.defaultSort).toEqual({ field: 'order', direction: 'asc' })
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button', () => {
		expect(page.config.showAdd).toBe(false)
	})

	// THE PRESET. integriq's schema holds every app's rows. Without the query
	// the page lists them all as though they were this app's.
	it('scopes the rows to buildiq through the menu preset, in the gear', () => {
		expect(menu.route).toBe(page.id)
		expect(menu.query).toEqual({ app: 'buildiq' })
		expect(menu.section).toBe('settings')
		expect(menu.permission).toBe('admin')
		expect(menu.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('names only formatters and handlers that exist, and wires both into the app', () => {
		const formatters = effectiveFormatters()
		const handlers = createConnectionHandlers({
			generateUrl: (p) => p,
			assign: () => {},
		})

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof formatters[column.formatter], column.formatter).toBe(
				'function',
			)
		}
		for (const action of page.config.headerActions) {
			expect(typeof handlers[action.handler], action.handler).toBe('function')
		}

		const app = read('src', 'App.vue')
		// Buildiq has no customComponents.js: App.vue's flatRegistry IS the
		// customComponents map CnIndexPage resolves a handler name against.
		expect(app).toContain(':customComponents="flatRegistry"')
		expect(app).toMatch(/^\t\t\t\t\.\.\.createConnectionHandlers\(\{$/m)
	})

	it('names an icon src/icons.js registers', () => {
		const icons = read('src', 'icons.js')
		for (const icon of [
			menu.icon,
			...page.config.headerActions.map((a) => a.icon),
		]) {
			expect(icons).toContain(`\n\t${icon},`)
		}
	})

	it('keeps its id and route apart from every page the base manifest declares', () => {
		const base = JSON.parse(read('src', 'manifest.json'))
		expect(base.pages.map((p) => p.id)).not.toContain(page.id)
		expect(base.pages.map((p) => p.route)).not.toContain(page.route)
		expect(base.menu.map((m) => m.id)).not.toContain(menu.id)
	})
})
