/**
 * SPDX-FileCopyrightText: 2026 ConductionNL / Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin-settings entry.
 *
 * `src/settings.js` waits for the app's translations, then mounts the admin
 * root on `#buildiq-settings` with `t`/`n` available to every component.
 * Nothing is mounted before the translations have loaded.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const entry = vi.hoisted(() => ({ loaded: null }))

vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	loadTranslations: vi.fn((app, callback) => {
		entry.loaded = { app, callback }
	}),
}))

vi.mock('../../src/views/settings/AdminRoot.vue', () => ({
	default: {
		name: 'SpecAdminRoot',
		render() {
			return h('p', { class: 'spec-admin-root' }, this.t('buildiq', 'Settings'))
		},
	},
}))

// A fresh import re-transforms the whole library stub graph, which takes
// longer than the default 5s when the full suite loads the runner.
const IMPORT_TIMEOUT = 30000

describe('settings entry', () => {
	beforeEach(() => {
		vi.resetModules()
		entry.loaded = null
		document.body.innerHTML = '<div id="buildiq-settings"></div>'
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('mounts the admin root once the buildiq translations have loaded', async () => {
		await import('../../src/settings.js')

		expect(entry.loaded?.app).toBe('buildiq')
		expect(document.querySelector('.spec-admin-root')).toBeNull()

		entry.loaded.callback()

		const root = document.querySelector('#buildiq-settings .spec-admin-root')
		expect(root).not.toBeNull()
		expect(root.textContent).toBe('Settings')
	}, IMPORT_TIMEOUT)
})
