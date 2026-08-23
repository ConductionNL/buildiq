<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - BuilderHost mounts a NESTED CnAppRoot for the virtual app addressed by
  - the :slug param. Per design.md Decision 4/5, this preserves the
  - Buildiq outer chrome and forwards path segments after the slug to
  - the inner router. The :key="slug" prop forces a clean remount when
  - the user navigates between virtual apps.
  -
  - Version routing (spec `buildiq-version-routing` REQ-OBVR-004):
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
	<div class="buildiq-builder-host" data-testid="buildiq-builder-host">
		<!-- REQ-OBVR-009: show version-not-found when useApplicationVersion resolved to 404 -->
		<div
			v-if="versionNotFound"
			class="buildiq-builder-host__version-not-found"
			role="alert"
			aria-live="polite">
			{{ t('buildiq', 'Version not found') }}
		</div>
		<CnAppRoot
			v-else
			:key="cacheKey"
			:appId="appId"
			:aiCompanion="true"
			:bundledManifest="placeholderManifest"
			:registry="runtimeRegistry"
			:data-sources-loader="dataSourcesLoader"
			:options="manifestOptions" />
	</div>
</template>

<script>
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import {
	registerScope,
	useRegisterPicker,
} from '../composables/useRegisterPicker.js'
import placeholderManifest from '../manifests/placeholder.json'
import { runtimeRegistry } from '../runtimeRegistry.js'
import { registerSlugForApp } from '../store/schemas.js'

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
			// Runtime registry passed to the nested CnAppRoot so virtual-app
			// manifests can resolve runtime widgets like `procest-case-status`
			// (spec procest-workflow-attachments REQ-PWA-004) and
			// `connector-data` (spec openconnector-api-sources REQ-OCAS-006).
			runtimeRegistry,
		}
	},

	computed: {
		/**
		 * Observed behaviour of `slug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		slug() {
			return this.$route.params.slug
		},

		/**
		 * REQ-OBVR-004: read `?_version=` from the URL query.
		 * Underscore-prefix to avoid colliding with user-defined `?version=` params.
		 *
		 * @return {string|undefined}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionSlug() {
			return this.$route.query._version || undefined
		},

		/**
		 * Observed behaviour of `appId` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		appId() {
			return `openbuild-${this.slug}`
		},

		/**
		 * Cache key forces CnAppRoot remount when slug OR version changes.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
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
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionNotFound() {
			return (
				!this.versionLoading
				&& this.versionError !== null
				&& this.applicationVersion === null
			)
		},

		/**
		 * Observed behaviour of `placeholderManifest` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		placeholderManifest() {
			return placeholderManifest
		},

		/**
		 * Observed behaviour of `manifestOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		manifestOptions() {
			// Forward `?_version=` to the manifest endpoint so the server resolves
			// the correct ApplicationVersion manifest (REQ-OBVR-001).
			const endpoint = generateUrl(
				`/apps/buildiq/api/applications/${this.slug}/manifest`,
			)
			return {
				endpoint: this.versionSlug
					? `${endpoint}?_version=${encodeURIComponent(this.versionSlug)}`
					: endpoint,
			}
		},
	},

	watch: {
		/**
		 * Observed behaviour of `slug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		slug() {
			this.resolveVersion()
		},

		/**
		 * Observed behaviour of `versionSlug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionSlug() {
			this.resolveVersion()
		},
	},

	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
	 */
	created() {
		// REQ-OBVR-004: resolve the active ApplicationVersion on mount.
		// NOTE: we do NOT call $router.replace() — that would strip ?_version=
		// and break bookmarkability (REQ-OBVR-008).
		this.resolveVersion()
	},

	// REQ-NTS-003: no beforeDestroy teardown needed — CnAppRoot owns its own
	// scoped-theme lifecycle (mount-apply/unmount-teardown) via `useScopedTheme`,
	// with zero Buildiq-side wiring (theme-picker-consumes-nldesign).
	methods: {
		/**
		 * Kick off useApplicationVersion and mirror reactive state into component data.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		resolveVersion() {
			this.versionError = null
			const { applicationVersion, loading, error } = useApplicationVersion(
				this.slug,
				this.versionSlug,
			)
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			// REQ-NTS-003/004: no app-side theme apply call here — CnAppRoot's own
			// `useScopedTheme` watcher re-applies `runtime.theme` whenever the
			// manifest it receives changes, including a version switch.
			// No data-source prefetch here: `dataSourcesLoader` reads the current
			// manifest when an editor modal actually opens.
			const unwatch = this.$watch(
				() => applicationVersion.value,
				(v) => {
					this.applicationVersion = v
				},
			)
			const unwatchLoading = this.$watch(
				() => loading.value,
				(v) => {
					this.versionLoading = v
					if (!v) {
						unwatch()
						unwatchLoading()
						this.versionError = error.value
					}
				},
			)
		},

		/**
		 * Load the `dataSources` for the nested CnAppRoot's in-app pages editor
		 * (ADR-041), so its Register / Schema / Columns pickers render as populated
		 * dropdowns instead of free-text fields.
		 *
		 * Passed to CnAppRoot as `dataSourcesLoader` and re-invoked every time an
		 * editor modal opens, so a schema created after the app booted appears
		 * without a reload. Reads the CURRENT manifest at call time, and shares
		 * `useRegisterPicker` with builder.js so the two hosts cannot drift apart.
		 *
		 * @return {Promise<object>} - the `{ registers: [...] }` data-sources map.
		 */
		async dataSourcesLoader() {
			const version = this.applicationVersion
			const manifest =
				version && version.manifest && typeof version.manifest === 'object'
					? version.manifest
					: null
			const scope = registerScope(
				registerSlugForApp(this.slug, this.versionSlug),
				manifest,
			)
			return useRegisterPicker({ appSlug: this.slug }).fetchDataSources(scope)
		},
	},
}
</script>

<style scoped>
.buildiq-builder-host {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
}
</style>
