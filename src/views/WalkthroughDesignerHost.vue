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

const EMPTY_MANIFEST = {
	version: '1.0.0',
	menu: [],
	pages: [],
	walkthrough: { enabled: true, tours: [] },
}

export default {
	name: 'WalkthroughDesignerHost',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard, WalkthroughDesigner },

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

		/** The Application object's OR uuid (persist fallback target). */
		applicationUuid() {
			const self = this.application && this.application['@self']
			return (
				(self && self.id)
				|| (this.application && this.application.uuid)
				|| ''
			)
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
		 * Load the Application and seed the editor manifest from the resolved
		 * version's manifest (falling back to the Application's manifest).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.toast = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openbuild/application',
				)
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const apps =
					data && data.results
						? data.results
						: Array.isArray(data)
							? data
							: []
				this.application =
					apps.find((a) => a && a.slug === this.routeSlug) || null
				const versionManifest =
					this.applicationVersion
					&& typeof this.applicationVersion.manifest === 'object'
						? this.applicationVersion.manifest
						: null
				const seed =
					versionManifest
					|| (this.application
					&& typeof this.application.manifest === 'object'
						? this.application.manifest
						: null)
				this.manifest = seed
					? JSON.parse(JSON.stringify(seed))
					: { ...EMPTY_MANIFEST }
			} catch (e) {
				this.application = null
				this.error = t('buildiq', 'Failed to load the app: {error}', {
					error: (e && e.message) || String(e),
				})
			} finally {
				this.loading = false
			}
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
				// Persist through buildiq's ApplicationVersionsController#update
				// (PUT /api/applications/{slug}/versions/{versionSlug}) with just the
				// manifest. The controller allowlists MUTABLE_FIELDS and passes the
				// OR register/schema as parameters, so it avoids the raw-objects PUT
				// failure where the schema-declared `register` data property collides
				// with OpenRegister's reserved system field (a no-op round-trip PUT to
				// /api/objects/.../applicationVersion 400s "register missing").
				const version = this.applicationVersion
				const versionSlug =
					version
					&& (version.slug || (version['@self'] && version['@self'].slug))
				if (version && versionSlug && this.routeSlug) {
					const url = generateUrl(
						`/apps/buildiq/api/applications/${this.routeSlug}/versions/${versionSlug}`,
					)
					const { data } = await axios.put(url, {
						manifest: this.manifest,
					})
					const saved =
						data && typeof data === 'object'
							? data.version || data.data || data
							: null
					if (saved && typeof saved === 'object')
						this.applicationVersion = saved
					this.toast = t('buildiq', 'Walkthrough saved.')
					return
				}
				// Fallback for un-versioned apps: persist onto the Application object.
				if (!this.applicationUuid) {
					this.error = t('buildiq', 'No app to save to.')
					return
				}
				const url = generateUrl(
					`/apps/openregister/api/objects/openbuild/application/${this.applicationUuid}`,
				)
				const { data } = await axios.put(url, {
					...this.application,
					manifest: this.manifest,
				})
				if (data && typeof data === 'object') this.application = data
				this.toast = t('buildiq', 'Walkthrough saved.')
			} catch (e) {
				this.error = t('buildiq', 'Failed to save: {error}', {
					error: (e && e.message) || String(e),
				})
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.wt-host {
	padding: 8px 0;
}
</style>
