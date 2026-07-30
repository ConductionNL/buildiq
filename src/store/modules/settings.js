// SPDX-License-Identifier: EUPL-1.2
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		settings: {},
		loading: false,
		hasOpenRegisters: false,
		isAdmin: false,
	}),

	getters: {
		getSettings: (state) => state.settings,
		getIsAdmin: (state) => state.isAdmin,
	},

	actions: {
		/**
		 * Observed behaviour of `fetchSettings` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
		 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/openbuild/api/settings'), {
					headers: { requesttoken: getRequestToken() },
				})
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					this.hasOpenRegisters = !!data?.openregisters
					this.isAdmin = !!data?.isAdmin
					return data
				}
			} catch (error) {
				console.error('Failed to fetch settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},

		/**
		 * Observed behaviour of `saveSettings` (retrofit annotation).
		 *
		 * POSTs the given settings to the app's settings endpoint and replaces the
		 * cached `settings` state with whatever the server echoes back. Never throws:
		 * a failure is logged and `null` is returned, which the settings view reads as
		 * "not saved".
		 *
		 * @param {{register: string, registry_url: string, registry_register: string,
		 *   registry_token?: string}} settings - Admin settings to persist, as built by
		 *   `src/views/settings/Settings.vue#save`. `registry_token` is omitted rather
		 *   than sent empty when the admin left the token field blank, which the
		 *   backend treats as "leave the stored token unchanged".
		 * @return {Promise<object|null>} The saved settings as returned by the server
		 *   (including the `registry_token_set` flag), or `null` when the request
		 *   failed.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-3
		 */
		async saveSettings(settings) {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/openbuild/api/settings'), {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: getRequestToken(),
					},
					body: JSON.stringify(settings),
				})
				if (response.ok) {
					const data = await response.json()
					this.settings = data
					return data
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
			} finally {
				this.loading = false
			}
			return null
		},
	},
})
