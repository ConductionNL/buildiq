<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - BuilderHost mounts a NESTED CnAppRoot for the virtual app addressed by
  - the :slug param. Per design.md Decision 4/5, this preserves the
  - OpenBuilt outer chrome and forwards path segments after the slug to
  - the inner router. The :key="slug" prop forces a clean remount when
  - the user navigates between virtual apps.
  -
  - Version routing (spec `openbuilt-version-routing` REQ-OBVR-004):
  - Reads `?_version=<versionSlug>` from `$route.query._version` (the
  - underscore-prefix form to avoid colliding with user-defined `?version=`
  - params). The CnAppRoot endpoint URL includes the `_version` param when
  - present, so the server-side ManifestResolverService resolves the correct
  - ApplicationVersion manifest. On 404 (unknown or unauthorised version),
  - the view renders the "version not found" UI state (REQ-OBVR-009).
  -
  - Loader workaround (design.md Decision 4): until the in-memory
  - useAppManifest overload ships in nextcloud-vue (chain spec #2), we
  - point the library's backend fetch at our per-slug manifest endpoint
  - via options.endpoint. The bundled-manifest argument is a placeholder
  - skeleton; the real manifest arrives from the backend merge.
  -->
<template>
	<div class="openbuilt-builder-host" data-testid="openbuilt-builder-host">
		<!-- REQ-OBVR-009: show version-not-found when useApplicationVersion resolved to 404 -->
		<div
			v-if="versionNotFound"
			class="openbuilt-builder-host__version-not-found"
			role="alert"
			aria-live="polite">
			{{ t('openbuilt', 'Version not found') }}
		</div>
		<CnAppRoot
			v-else
			:key="cacheKey"
			:app-id="appId"
			:bundled-manifest="placeholderManifest"
			:options="manifestOptions" />
	</div>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import placeholderManifest from '../manifests/placeholder.json'

export default {
	name: 'BuilderHost',
	components: {
		CnAppRoot,
	},
	data() {
		return {
			// REQ-OBVR-004: reactive version state from useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
		}
	},
	computed: {
		slug() {
			return this.$route.params.slug
		},
		/**
		 * REQ-OBVR-004: read `?_version=` from the URL query.
		 * Underscore-prefix to avoid colliding with user-defined `?version=` params.
		 *
		 * @return {string|undefined}
		 */
		versionSlug() {
			return this.$route.query._version || undefined
		},
		appId() {
			return `openbuilt-${this.slug}`
		},
		/**
		 * Cache key forces CnAppRoot remount when slug OR version changes.
		 *
		 * @return {string}
		 */
		cacheKey() {
			return `${this.slug}:${this.versionSlug || 'default'}`
		},
		/**
		 * REQ-OBVR-009: true when the version fetch completed with an error
		 * (e.g. 404 for unknown or unauthorised version). The view renders a
		 * "version not found" state identical for both "doesn't exist" and
		 * "you can't see it" cases — no auth cue exposed to the caller.
		 *
		 * @return {boolean}
		 */
		versionNotFound() {
			return !this.versionLoading && this.versionError !== null && this.applicationVersion === null
		},
		placeholderManifest() {
			return placeholderManifest
		},
		manifestOptions() {
			// Forward `?_version=` to the manifest endpoint so the server resolves
			// the correct ApplicationVersion manifest (REQ-OBVR-001).
			const endpoint = generateUrl(`/apps/openbuilt/api/applications/${this.slug}/manifest`)
			return {
				endpoint: this.versionSlug
					? `${endpoint}?_version=${encodeURIComponent(this.versionSlug)}`
					: endpoint,
			}
		},
	},
	watch: {
		slug() {
			this.resolveVersion()
		},
		versionSlug() {
			this.resolveVersion()
		},
	},
	created() {
		// REQ-OBVR-004: resolve the active ApplicationVersion on mount.
		// NOTE: we do NOT call $router.replace() — that would strip ?_version=
		// and break bookmarkability (REQ-OBVR-008).
		this.resolveVersion()
	},
	methods: {
		/**
		 * Kick off useApplicationVersion and mirror reactive state into component data.
		 *
		 * @return {void}
		 */
		resolveVersion() {
			this.versionError = null
			const { applicationVersion, loading, error } = useApplicationVersion(
				this.slug,
				this.versionSlug,
			)
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			const unwatch = this.$watch(() => applicationVersion.value, (v) => {
				this.applicationVersion = v
			})
			const unwatchLoading = this.$watch(() => loading.value, (v) => {
				this.versionLoading = v
				if (!v) {
					unwatch()
					unwatchLoading()
					this.versionError = error.value
				}
			})
		},
	},
}
</script>

<style scoped>
.openbuilt-builder-host {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
}
</style>
