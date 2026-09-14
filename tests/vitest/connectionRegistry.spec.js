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
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'
import {
	CONNECTION_STATUS_LABELS,
	createConnectionFormatters,
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/services/connectionRegistry.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
const fragment = JSON.parse(read('src', 'manifest.d', '80-connection-registry.json'))
const page = fragment.pages.find((p) => p.id === 'Integrations')
const menu = fragment.menu.find((m) => m.id === 'IntegrationsMenu')

/** A translator that marks what it translated, so a missing call shows. */
const translate = (source) => `t:${source}`

describe('connection formatters', () => {
	const formatters = createConnectionFormatters(translate)

	it('labels all six statuses, limited included', () => {
		expect(Object.keys(CONNECTION_STATUS_LABELS).sort()).toEqual(
			['configured', 'error', 'limited', 'simulated', 'unavailable', 'unconfigured'],
		)
		expect(formatters.connectionStatus('configured')).toBe('t:Configured')
		expect(formatters.connectionStatus('limited')).toBe('t:Limited')
		expect(formatters.connectionStatus('unconfigured')).toBe('t:Not configured')
		expect(formatters.connectionStatus('simulated')).toBe('t:Simulated')
		expect(formatters.connectionStatus('unavailable')).toBe('t:Not available')
		expect(formatters.connectionStatus('error')).toBe('t:Error')
	})

	// A connection that works in part is neither working nor broken, so it must
	// not borrow either label.
	it('keeps limited apart from configured, not available and error', () => {
		const limited = formatters.connectionStatus('limited')
		expect(limited).not.toBe(formatters.connectionStatus('configured'))
		expect(limited).not.toBe(formatters.connectionStatus('unavailable'))
		expect(limited).not.toBe(formatters.connectionStatus('error'))
	})

	it('renders an unknown status as itself and a missing one as empty', () => {
		expect(formatters.connectionStatus('degraded')).toBe('degraded')
		expect(formatters.connectionStatus('toString')).toBe('toString')
		expect(formatters.connectionStatus(null)).toBe('')
		expect(formatters.connectionStatus(undefined)).toBe('')
	})

	it('offers Open settings only when the row has a settings link', () => {
		expect(formatters.connectionSettingsLabel('/settings/admin/buildiq')).toBe('t:Open settings')
		expect(formatters.connectionSettingsLabel('')).toBe('')
		expect(formatters.connectionSettingsLabel(undefined)).toBe('')
		expect(formatters.connectionSettingsLabel(null)).toBe('')
	})

	it('ships an English and a Dutch catalogue entry for every label the page shows', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
			...Object.values(CONNECTION_STATUS_LABELS),
			'Open settings',
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
		expect(nl.Limited).toBe('Beperkt')
		// The browser reads the .js catalogue, never the .json one.
		expect(read('l10n', 'nl.js')).toContain('"Limited": "Beperkt"')
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

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe('/apps/integriq/connections?app=buildiq&link=1')
		expect(opened).toEqual(['/index.php/apps/integriq/connections?app=buildiq&link=1'])
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
		const formatters = createConnectionFormatters(translate)
		const handlers = createConnectionHandlers({ generateUrl: (p) => p, assign: () => {} })

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			expect(typeof formatters[column.formatter], column.formatter).toBe('function')
		}
		for (const action of page.config.headerActions) {
			expect(typeof handlers[action.handler], action.handler).toBe('function')
		}

		const app = read('src', 'App.vue')
		expect(app).toContain(':formatters="formatters"')
		expect(app).toContain('formatters: createConnectionFormatters(')
		// Buildiq has no customComponents.js: App.vue's flatRegistry IS the
		// customComponents map CnIndexPage resolves a handler name against.
		expect(app).toContain(':customComponents="flatRegistry"')
		expect(app).toMatch(/^\t\t\t\t\.\.\.createConnectionHandlers\(\{$/m)
	})

	it('names an icon src/icons.js registers', () => {
		const icons = read('src', 'icons.js')
		for (const icon of [menu.icon, ...page.config.headerActions.map((a) => a.icon)]) {
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
