/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boot half of the Nextcloud-dashboard entry.
 *
 * Importing `src/ncDashboard.js` on a page that has `OCA.Dashboard` registers
 * the library's icons, widgets and translations, then hands every promoted
 * descriptor from initial state to the dashboard. A translation registration
 * that throws is logged and does not stop the widgets from registering. A page
 * without a dashboard registers nothing.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const boot = vi.hoisted(() => ({
	registerIcons: vi.fn(),
	registerBuiltinDashboardWidgets: vi.fn(),
	registerTranslations: vi.fn(),
	loadState: vi.fn(),
}))

vi.mock('@conduction/nextcloud-vue', async (importOriginal) => ({
	...(await importOriginal()),
	registerIcons: boot.registerIcons,
	registerBuiltinDashboardWidgets: boot.registerBuiltinDashboardWidgets,
	registerTranslations: boot.registerTranslations,
}))

vi.mock('@nextcloud/initial-state', () => ({ loadState: boot.loadState }))

// A fresh import re-transforms the whole library stub graph, which takes
// longer than the default 5s when the full suite loads the runner.
const IMPORT_TIMEOUT = 30000

describe('ncDashboard boot', () => {
	beforeEach(() => {
		vi.resetModules()
		Object.values(boot).forEach((mock) => mock.mockReset())
		boot.loadState.mockReturnValue([
			{ id: 'buildiq-app-open-cases', widgetKey: 'spec-tile', title: 'Open cases' },
			{ id: '', widgetKey: 'spec-tile' },
		])
	})

	afterEach(() => {
		delete globalThis.OCA
		vi.restoreAllMocks()
	})

	it('registers the library and every promoted widget with the dashboard', async () => {
		const register = vi.fn()
		globalThis.OCA = { Dashboard: { register } }

		await import('../../src/ncDashboard.js')

		expect(boot.registerIcons).toHaveBeenCalledTimes(1)
		expect(boot.registerBuiltinDashboardWidgets).toHaveBeenCalledTimes(1)
		expect(boot.registerTranslations).toHaveBeenCalledTimes(1)
		expect(boot.loadState).toHaveBeenCalledWith('buildiq', 'ncDashboardWidgets', [])
		expect(register.mock.calls.map((call) => call[0])).toEqual(['buildiq-app-open-cases'])
	}, IMPORT_TIMEOUT)

	it('logs a translation registration that throws and still registers the widgets', async () => {
		const register = vi.fn()
		globalThis.OCA = { Dashboard: { register } }
		const failure = new Error('no l10n bundle')
		boot.registerTranslations.mockImplementation(() => {
			throw failure
		})
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})

		await import('../../src/ncDashboard.js')

		expect(warn).toHaveBeenCalledWith(
			'[buildiq:ncDashboard] registerTranslations failed; lib strings fall back to English source',
			failure,
		)
		expect(register).toHaveBeenCalledTimes(1)
	}, IMPORT_TIMEOUT)

	it('boots nothing on a page without a dashboard', async () => {
		globalThis.OCA = {}

		await import('../../src/ncDashboard.js')

		expect(boot.registerIcons).not.toHaveBeenCalled()
		expect(boot.loadState).not.toHaveBeenCalled()
	}, IMPORT_TIMEOUT)
})
