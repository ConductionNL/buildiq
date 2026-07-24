<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - BuilderHost mounts a NESTED CnAppRoot for the virtual app addressed by
  - the :slug param. Per design.md Decision 4/5, this preserves the
  - OpenBuild outer chrome and forwards path segments after the slug to
  - the inner router. The :key="slug" prop forces a clean remount when
  - the user navigates between virtual apps.
  -
  - Version routing (spec `openbuild-version-routing` REQ-OBVR-004):
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
	<div
		class="openbuild-builder-host"
		data-testid="openbuild-builder-host"
		:data-openbuild-theme-scope="slug">
		<!-- REQ-OBVR-009: show version-not-found when useApplicationVersion resolved to 404 -->
		<div
			v-if="versionNotFound"
			class="openbuild-builder-host__version-not-found"
			role="alert"
			aria-live="polite">
			{{ t('openbuild', 'Version not found') }}
		</div>
		<template v-else>
			<!-- app-theming: OpenBuild-side branded-header binding — CnAppRoot
			     exposes no dedicated logo/branding slot (see
			     AppBrandedHeader.vue docblock for the full rationale).
			     Rendered inside the same [data-openbuild-theme-scope]
			     wrapper, above CnAppRoot, so it inherits
			     --ob-theme-secondary/--ob-theme-accent via plain CSS
			     inheritance and applies regardless of an active nldesign
			     theme (REQ "appTheme header style still applies alongside
			     an nldesign theme"). -->
			<AppBrandedHeader
				v-if="appThemeHeaderStyle === 'branded'"
				:app-slug="slug"
				:app-name="appName"
				:logo-ref="appThemeLogoRef"
				:application-uuid="applicationUuid" />
			<CnAppRoot
				:key="cacheKey"
				:app-id="appId"
				:ai-companion="true"
				:bundled-manifest="placeholderManifest"
				:registry="runtimeRegistry"
				:data-sources-loader="dataSourcesLoader"
				:options="manifestOptions" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'

import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import { useAppTheme } from '../composables/useAppTheme.js'
import { useAppCustomTheme } from '../composables/useAppCustomTheme.js'
import { useRegisterPicker, registerScope } from '../composables/useRegisterPicker.js'
import { runtimeRegistry } from '../runtimeRegistry.js'
import { registerSlugForApp } from '../store/schemas.js'
import placeholderManifest from '../manifests/placeholder.json'
import AppBrandedHeader from '../components/AppBrandedHeader.vue'

export default {
	name: 'BuilderHost',
	components: {
		CnAppRoot,
		AppBrandedHeader,
	},
	data() {
		return {
			// REQ-NTS-003: scoped NL Design theme applier. Bound once; apply()
			// runs against the resolved version manifest, teardown() on leave.
			appTheme: useAppTheme(),
			// app-theming: scoped appTheme applier, applied BEFORE appTheme
			// (nldesign) in applyTheme() — design.md Decision D3.
			appCustomTheme: useAppCustomTheme(),
			// REQ-OBVR-004: reactive version state from useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
			// Runtime registry passed to the nested CnAppRoot so virtual-app
			// manifests can resolve runtime widgets like `procest-case-status`
			// (spec procest-workflow-attachments REQ-PWA-004) and
			// `connector-data` (spec openconnector-api-sources REQ-OCAS-006).
			runtimeRegistry,
			// app-theming: the Application record, fetched lazily — only when
			// `headerStyle: "branded"` is resolved — so the common (unthemed)
			// case never pays for an extra request (see applyTheme()).
			application: null,
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
			return !this.versionLoading && this.versionError !== null && this.applicationVersion === null
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
			const endpoint = generateUrl(`/apps/openbuild/api/applications/${this.slug}/manifest`)
			return {
				endpoint: this.versionSlug
					? `${endpoint}?_version=${encodeURIComponent(this.versionSlug)}`
					: endpoint,
			}
		},
		/**
		 * The resolved `runtime.appTheme` block, or null when the app has no
		 * app-theming set.
		 *
		 * @return {?object}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
		 */
		appThemeBlock() {
			const manifest = this.applicationVersion && this.applicationVersion.manifest
			return (manifest && manifest.runtime && manifest.runtime.appTheme) || null
		},
		/**
		 * `appTheme.headerStyle` — drives whether `AppBrandedHeader` renders.
		 *
		 * @return {string}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-theme-applies-via-the-existing-scoped-css-variable-mechanism
		 */
		appThemeHeaderStyle() {
			return (this.appThemeBlock && this.appThemeBlock.headerStyle) || 'default'
		},
		/**
		 * `appTheme.logoRef` — null defaults `AppBrandedHeader` to the app icon.
		 *
		 * @return {?object}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		appThemeLogoRef() {
			return (this.appThemeBlock && this.appThemeBlock.logoRef) || null
		},
		/**
		 * Display name for the branded header — the Application's own name,
		 * falling back to the slug while the lazy Application fetch resolves.
		 *
		 * @return {string}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		appName() {
			return (this.application && (this.application.name || this.application.slug)) || this.slug
		},
		/**
		 * The Application's OR uuid, needed to resolve a dedicated theme logo.
		 *
		 * @return {string}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		applicationUuid() {
			const self = this.application && this.application['@self']
			return (self && self.id) || (this.application && this.application.uuid) || ''
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
	/**
	 * REQ-NTS-003: remove the managed scoped-theme style element when leaving
	 * the app, so the previous app's theme never bleeds into the next one.
	 *
	 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
	 */
	beforeDestroy() {
		this.appTheme.teardown(this.slug)
		this.appCustomTheme.teardown(this.slug)
	},
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
			this.applyTheme(applicationVersion.value)
			// No data-source prefetch here: `dataSourcesLoader` reads the current
			// manifest when an editor modal actually opens.
			const unwatch = this.$watch(() => applicationVersion.value, (v) => {
				this.applicationVersion = v
				this.applyTheme(v)
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

		/**
		 * REQ-NTS-003 / REQ-NTS-004: apply the resolved version's NL Design
		 * theme to this app's scoped render root. The version object carries
		 * the resolved (possibly `?_version=`-routed) manifest, so version
		 * preview renders the previewed version's theme. nldesign absent ⇒ the
		 * applier's fetch fails and it degrades to default styling (no gate).
		 *
		 * app-theming / design.md Decision D3: `appCustomTheme` is applied
		 * FIRST, then `appTheme` (nldesign) SECOND, so an active nldesign
		 * theme's colors resolve via the `var(--nldesign-color-*, …)`
		 * fallback chain `useAppCustomTheme.js` builds (see its docblock for
		 * why injection order alone is not sufficient against the real
		 * fetched nldesign token CSS).
		 *
		 * @param {?object} version - the resolved ApplicationVersion.
		 * @return {void}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-003
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-an-active-nldesign-theme-takes-precedence-over-apptheme-colors
		 */
		applyTheme(version) {
			const manifest = version && version.manifest && typeof version.manifest === 'object'
				? version.manifest
				: null
			if (!manifest) {
				this.appTheme.teardown(this.slug)
				this.appCustomTheme.teardown(this.slug)
				return
			}
			this.appCustomTheme.apply(manifest, this.slug)
			this.appTheme.apply(manifest, this.slug)
			this.loadApplicationIfBranded(manifest)
		},
		/**
		 * Lazily fetch the Application record (name + uuid, for
		 * `AppBrandedHeader`) — only when the resolved manifest actually
		 * declares `headerStyle: "branded"`, so the common (unthemed) case
		 * never pays for the extra request. Same GET+filter-by-slug pattern
		 * `PageDesignerHost.load()` already uses.
		 *
		 * @param {object} manifest - the resolved manifest.
		 * @return {Promise<void>}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		async loadApplicationIfBranded(manifest) {
			const headerStyle = manifest && manifest.runtime && manifest.runtime.appTheme && manifest.runtime.appTheme.headerStyle
			if (headerStyle !== 'branded' || this.application) {
				return
			}
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuild/application')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const apps = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				this.application = apps.find((a) => a && a.slug === this.slug) || null
			} catch {
				this.application = null
			}
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
			const manifest = version && version.manifest && typeof version.manifest === 'object'
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
.openbuild-builder-host {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-height: 0;
}
</style>
