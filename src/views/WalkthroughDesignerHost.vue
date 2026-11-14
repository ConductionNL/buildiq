<!--
  WalkthroughDesignerHost — route-level host for the visual walkthrough designer
  (ADR-043). Resolves the active app + ApplicationVersion, seeds the editor with
  that version's manifest, renders the controlled <WalkthroughDesigner>, and
  persists edits back onto the ApplicationVersion (falling back to the
  Application object for un-migrated apps) — the exact load/persist plumbing
  PageDesignerHost uses, so walkthrough edits round-trip through the versioned
  manifest delta like every other designer.

  Reads ?_version=<versionSlug> from $route.query._version.

  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->
<template>
	<div class="wt-host">
		<NcEmptyContent v-if="loading" :name="t('buildiq', 'Loading…')">
			<template #icon>
				<NcLoadingIcon :size="32" />
			</template>
		</NcEmptyContent>
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="toast" type="success">
				{{ toast }}
			</NcNoteCard>
			<WalkthroughDesigner
				:manifest="manifest"
				:appSlug="routeSlug"
				:versionSlug="versionSlug || ''"
				@update:manifest="onManifestUpdate"
				@saveAndPreview="save" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import WalkthroughDesigner from '../components/walkthrough-editor/WalkthroughDesigner.vue'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import builtAppHost, { failureText } from '../mixins/builtAppHost.js'

const EMPTY_MANIFEST = {
	version: '1.0.0',
	menu: [],
	pages: [],
	walkthrough: { enabled: true, tours: [] },
}

/**
 * The slug of an ApplicationVersion, from its own field or its `@self` block.
 *
 * @param {object|null} version The version.
 * @return {string|undefined|null} The slug, or a falsy value without one.
 */
function versionSlugOf(version) {
	return version && (version.slug || (version['@self'] && version['@self'].slug))
}

/**
 * The version a versions-endpoint answer carries.
 *
 * @param {object|null} data The answer body.
 * @return {object|null} The saved version, or null when the body is not an object.
 */
function savedVersionIn(data) {
	return data && typeof data === 'object'
		? data.version || data.data || data
		: null
}

export default {
	name: 'WalkthroughDesignerHost',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard, WalkthroughDesigner },

	mixins: [builtAppHost],

	data() {
		return {
			application: null,
			applicationVersion: null,
			manifest: { ...EMPTY_MANIFEST },
			loading: false,
			saving: false,
			error: '',
			toast: '',
		}
	},

	computed: {
		/** The app slug from the route. */
		routeSlug() {
			return this.$route.params.slug || ''
		},

		/** The optional version slug from `?_version=`. */
		versionSlug() {
			return this.$route.query._version || undefined
		},
	},

	watch: {
		routeSlug() {
			this.resolveVersion()
			this.load()
		},

		versionSlug() {
			this.resolveVersion()
			this.load()
		},
	},

	created() {
		this.resolveVersion()
		this.load()
	},

	methods: {
		t,
		/**
		 * Resolve the active ApplicationVersion for the slug + version query and
		 * keep it reactive (mirrors PageDesignerHost).
		 *
		 * @return {void}
		 */
		resolveVersion() {
			if (!this.routeSlug) return
			const { applicationVersion } = useApplicationVersion(
				this.routeSlug,
				this.versionSlug,
			)
			this.applicationVersion = applicationVersion.value
			this.$watch(
				() => applicationVersion.value,
				(v) => {
					this.applicationVersion = v
				},
			)
		},

		/**
		 * A fresh empty manifest, seeded when neither the version nor the
		 * Application carries one.
		 *
		 * @return {object}
		 */
		emptyManifest() {
			return { ...EMPTY_MANIFEST }
		},

		/**
		 * Receive the edited manifest from the controlled designer.
		 *
		 * @param {object} next The new manifest.
		 * @return {void}
		 */
		onManifestUpdate(next) {
			this.manifest = next
		},

		/**
		 * Persist the manifest onto the active ApplicationVersion (or the
		 * Application object for un-migrated apps) — surgical-merge so version
		 * fields the designer never touches round-trip losslessly.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async save() {
			if (this.saving) return
			this.saving = true
			this.error = ''
			this.toast = ''
			try {
				await this.persistManifest()
			} catch (e) {
				this.error = t('buildiq', 'Failed to save: {error}', {
					error: failureText(e),
				})
			} finally {
				this.saving = false
			}
		},

		/**
		 * Persist onto the active ApplicationVersion when one is resolved, else
		 * onto the Application object, else say there is nothing to save to.
		 *
		 * @return {Promise<void>}
		 */
		async persistManifest() {
			const versionSlug = versionSlugOf(this.applicationVersion)
			if (versionSlug && this.routeSlug) {
				await this.saveOntoVersion(versionSlug)
				this.toast = t('buildiq', 'Walkthrough saved.')
				return
			}
			// Fallback for un-versioned apps: persist onto the Application object.
			if (!this.applicationUuid) {
				this.error = t('buildiq', 'No app to save to.')
				return
			}
			await this.saveOntoApplication()
			this.toast = t('buildiq', 'Walkthrough saved.')
		},

		/**
		 * Persist through buildiq's ApplicationVersionsController#update
		 * (PUT /api/applications/{slug}/versions/{versionSlug}) with just the
		 * manifest. The controller allowlists MUTABLE_FIELDS and passes the
		 * OR register/schema as parameters, so it avoids the raw-objects PUT
		 * failure where the schema-declared `register` data property collides
		 * with OpenRegister's reserved system field (a no-op round-trip PUT to
		 * /api/objects/.../applicationVersion 400s "register missing").
		 *
		 * @param {string} versionSlug The active version's slug.
		 * @return {Promise<void>}
		 */
		async saveOntoVersion(versionSlug) {
			const url = generateUrl(
				`/apps/buildiq/api/applications/${this.routeSlug}/versions/${versionSlug}`,
			)
			const { data } = await axios.put(url, {
				manifest: this.manifest,
			})
			const saved = savedVersionIn(data)
			if (saved && typeof saved === 'object') this.applicationVersion = saved
		},
	},
}
</script>

<style scoped>
.wt-host {
	padding: 8px 0;
}
</style>
