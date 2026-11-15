// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// builtAppHost — mixin for the route-level designer hosts (PageDesignerHost,
// WalkthroughDesignerHost). Both resolve the Application object by the route
// slug, seed the editor manifest from the active ApplicationVersion (falling
// back to the Application's own manifest, then to the host's empty manifest),
// and, for apps that have not moved to the versioned model, persist the edited
// manifest back onto the Application object. The host supplies
// `emptyManifest()`, `routeSlug` and `applicationVersion`.

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

const BUILT_APPS = '/apps/openregister/api/objects/buildiq/built-app'

/**
 * The manifest a record carries, when it carries one as an object.
 *
 * @param {object|null} record An ApplicationVersion or Application object.
 * @return {object|null} The manifest, or null.
 */
export function manifestOf(record) {
	return record && record.manifest && typeof record.manifest === 'object'
		? record.manifest
		: null
}

/**
 * The message of a failure, for a sentence shown to the user.
 *
 * @param {Error|string} e The failure.
 * @return {string} Its message, or the failure itself as a string.
 */
export function failureText(e) {
	return (e && e.message) || String(e)
}

/**
 * The built-app objects in an objects-API answer.
 *
 * @param {object|Array|null} data The answer body.
 * @return {Array<object>} The objects, or [] when the answer holds none.
 */
function builtAppsIn(data) {
	if (data && data.results) {
		return data.results
	}
	return Array.isArray(data) ? data : []
}

/**
 * The id a record carries in its `@self` block.
 *
 * @param {?object} record An OpenRegister record.
 * @return {string|undefined}
 */
function selfIdOf(record) {
	const self = record && record['@self']
	return self && self.id
}

export default {
	computed: {
		/**
		 * The Application's canonical UUID (OR puts it at @self.id).
		 *
		 * @return {string}
		 */
		applicationUuid() {
			return (
				selfIdOf(this.application)
				|| (this.application && this.application.uuid)
				|| ''
			)
		},
	},

	methods: {
		/**
		 * Load the Application by the route slug and seed the editor manifest
		 * from the resolved version's manifest, falling back to the
		 * Application's manifest, then to the host's empty manifest.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.toast = ''
			try {
				this.application = await this.fetchRouteApplication()
				this.seedManifest()
			} catch (e) {
				this.application = null
				this.error = t('buildiq', 'Failed to load the app: {error}', {
					error: failureText(e),
				})
			} finally {
				this.loading = false
			}
		},

		/**
		 * The Application object whose slug the route names.
		 *
		 * @return {Promise<object|null>} The Application, or null when no app has that slug.
		 */
		async fetchRouteApplication() {
			const { data } = await axios.get(generateUrl(BUILT_APPS), {
				params: { _limit: 100 },
			})
			return (
				builtAppsIn(data).find((a) => a && a.slug === this.routeSlug) || null
			)
		},

		/**
		 * Seed the editor manifest with a copy of the version's or the
		 * Application's manifest, or with the host's empty manifest.
		 *
		 * @return {void}
		 */
		seedManifest() {
			const seed =
				manifestOf(this.applicationVersion) || manifestOf(this.application)
			this.manifest = seed
				? JSON.parse(JSON.stringify(seed))
				: this.emptyManifest()
		},

		/**
		 * Persist the edited manifest onto the Application object (the path
		 * for apps without an ApplicationVersion), keeping the stored object
		 * when the answer carries one.
		 *
		 * @return {Promise<void>}
		 */
		async saveOntoApplication() {
			const url = generateUrl(`${BUILT_APPS}/${this.applicationUuid}`)
			const { data } = await axios.put(url, {
				...this.application,
				manifest: this.manifest,
			})
			if (data && typeof data === 'object') {
				this.application = data
			}
		},
	},
}
