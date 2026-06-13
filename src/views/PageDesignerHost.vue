<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  PageDesignerHost — route-level host for the visual page designer
  (/builder/:slug/pages). Resolves the slug to its Application via
  OpenRegister's objects API, hands the stored `manifest` to the
  controlled <PageDesigner> component, and persists edits back with a
  PUT. PageDesigner itself stays a pure controlled component (manifest
  prop in, update:manifest / save-and-preview events out) so it can also
  be embedded as a tab in ApplicationEditor later.

  Version routing (spec `openbuild-version-routing` REQ-OBVR-004):
  Reads `?_version=<versionSlug>` from `$route.query._version`. The
  useApplicationVersion composable resolves the active version. On 404
  (unknown or unauthorised version), the "version not found" UI state is
  rendered (REQ-OBVR-009).

  Tracks issue #26 (PageDesigner used to render with an empty manifest).
-->
<template>
	<div class="page-designer-host">
		<header class="page-designer-host__header">
			<div class="page-designer-host__title">
				<h2>{{ application ? application.name : t('openbuild', 'Page designer') }}</h2>
				<p v-if="application" class="page-designer-host__subtitle">
					{{ t('openbuild', 'Design the pages and menu of this virtual app, then publish from Virtual apps.') }}
				</p>
			</div>
			<div class="page-designer-host__actions">
				<router-link class="page-designer-host__link" :to="{ name: 'VirtualApps' }">
					{{ t('openbuild', 'Back to Virtual apps') }}
				</router-link>
				<a v-if="builderUrl" class="page-designer-host__link" :href="builderUrl">
					{{ t('openbuild', 'Open virtual app') }}
				</a>
				<NcButton
					v-if="application"
					type="primary"
					:disabled="saving"
					@click="save">
					{{ saving ? t('openbuild', 'Saving…') : t('openbuild', 'Save pages') }}
				</NcButton>
			</div>
		</header>

		<div v-if="toast" class="page-designer-host__toast">
			{{ toast }}
		</div>
		<div v-if="error" class="page-designer-host__error">
			{{ error }}
		</div>

		<!-- REQ-OBVR-009: version-not-found state — identical for both "doesn't exist" and "you can't see it" -->
		<NcEmptyContent
			v-if="versionNotFound"
			:name="t('openbuild', 'Version not found')"
			:description="t('openbuild', 'The requested version does not exist or you do not have access to it.')" />
		<div v-else-if="loading" class="page-designer-host__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent
			v-else-if="!application"
			:name="t('openbuild', 'No virtual app found')"
			:description="t('openbuild', 'No virtual app exists for the slug {slug}.', { slug: routeSlug })" />
		<PageDesigner
			v-else
			:manifest="manifest"
			:slug="routeSlug"
			@update:manifest="onManifestUpdate"
			@save-and-preview="save" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import PageDesigner from './PageDesigner.vue'

const EMPTY_MANIFEST = { version: '1.0.0', menu: [], pages: [] }

export default {
	name: 'PageDesignerHost',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PageDesigner,
	},

	data() {
		return {
			loading: true,
			saving: false,
			application: null,
			manifest: { ...EMPTY_MANIFEST },
			toast: '',
			error: '',
			// REQ-OBVR-004: reactive version state from useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
		}
	},

	computed: {
		/**
		 * The virtual-app slug from the route (/builder/:slug/pages).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		routeSlug() {
			return this.$route.params.slug || ''
		},

		/**
		 * REQ-OBVR-004: read `?_version=` from the URL query.
		 * The underscore-prefix form avoids colliding with user-defined `?version=` params.
		 *
		 * @return {string|undefined}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionSlug() {
			return this.$route.query._version || undefined
		},

		/**
		 * REQ-OBVR-009: true when the version fetch completed with an error (404 etc.)
		 * and no applicationVersion was resolved. Renders the version-not-found UI state —
		 * identical for both "doesn't exist" and "you can't see it" cases.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		versionNotFound() {
			return !this.versionLoading && this.versionError !== null && this.applicationVersion === null
		},

		/**
		 * The Application's canonical UUID (OR puts it at @self.id).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		applicationUuid() {
			const self = this.application && this.application['@self']
			return (self && self.id) || (this.application && this.application.uuid) || ''
		},

		/**
		 * Full-page link into the virtual app, if it has ever been published.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		builderUrl() {
			if (!this.application) {
				return ''
			}
			const published = this.application.currentVersion || this.application.status === 'published'
			return published ? generateUrl(`/apps/openbuild/builder/${this.application.slug}`) : ''
		},
	},

	watch: {
		/**
		 * Observed behaviour of `routeSlug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		routeSlug() {
			this.resolveVersion()
			this.load()
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
		// REQ-OBVR-004: resolve the active ApplicationVersion on created.
		// NOTE: we do NOT call $router.replace() or $router.push() here — that
		// would strip ?_version= and break bookmarkability (REQ-OBVR-008).
		this.resolveVersion()
		this.load()
	},

	methods: {
		/**
		 * Resolve the active ApplicationVersion via useApplicationVersion composable
		 * (REQ-OBVR-004 / REQ-OBVR-005). Called on created and when slug/versionSlug change.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		resolveVersion() {
			this.versionError = null
			const { applicationVersion, loading, error } = useApplicationVersion(
				this.routeSlug,
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

		/**
		 * Fetch the Application for the current slug and seed the editor manifest.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.toast = ''
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuild/application')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const apps = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				const app = apps.find(a => a && a.slug === this.routeSlug) || null
				this.application = app
				// ADR-002 / REQ-OBPD-009: the manifest now lives on the active
				// ApplicationVersion. Seed from the resolved version's manifest when
				// available, falling back to the Application's manifest for apps that
				// have not yet been migrated to the versioned model.
				const versionManifest = this.applicationVersion
					&& this.applicationVersion.manifest
					&& typeof this.applicationVersion.manifest === 'object'
					? this.applicationVersion.manifest
					: null
				const seed = versionManifest
					|| (app && app.manifest && typeof app.manifest === 'object' ? app.manifest : null)
				this.manifest = seed
					? JSON.parse(JSON.stringify(seed))
					: { ...EMPTY_MANIFEST }
			} catch (e) {
				this.application = null
				this.error = t('openbuild', 'Failed to load the virtual app: {error}', { error: (e && e.message) || String(e) })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Receive an edited manifest from the controlled PageDesigner.
		 *
		 * @param {object} next The new manifest.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		onManifestUpdate(next) {
			this.manifest = next
		},

		/**
		 * Persist the edited manifest onto the Application object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-2
		 */
		async save() {
			if (!this.application || !this.applicationUuid || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			this.toast = ''
			try {
				// ADR-002 / REQ-OBPD-009 (design.md Decision 6): persist the manifest
				// onto the active ApplicationVersion when one is resolved — surgical-merge
				// the UI-controlled `manifest` field back into the original record so any
				// version fields the designer does not touch round-trip losslessly
				// (design.md Risk 2). Fall back to the Application object for apps that
				// predate the versioned model.
				const version = this.applicationVersion
				const versionUuid = version
					&& ((version['@self'] && version['@self'].id) || version.uuid || version.id)
				if (version && versionUuid) {
					const url = generateUrl(`/apps/openregister/api/objects/openbuild/applicationVersion/${versionUuid}`)
					const { data } = await axios.put(url, { ...version, manifest: this.manifest })
					if (data && typeof data === 'object') {
						this.applicationVersion = data
					}
					this.toast = t('openbuild', 'Pages saved.')
					return
				}
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${this.applicationUuid}`)
				const { data } = await axios.put(url, { ...this.application, manifest: this.manifest })
				if (data && typeof data === 'object') {
					this.application = data
				}
				this.toast = t('openbuild', 'Pages saved.')
			} catch (e) {
				this.error = t('openbuild', 'Failed to save: {error}', { error: (e && e.message) || String(e) })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.page-designer-host {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.page-designer-host__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
}

.page-designer-host__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.page-designer-host__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.page-designer-host__link {
	text-decoration: underline;
}

.page-designer-host__toast {
	background: var(--color-success);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	padding: 6px 12px;
	width: fit-content;
}

.page-designer-host__error {
	background: var(--color-error);
	color: var(--color-primary-text);
	border-radius: var(--border-radius);
	padding: 6px 12px;
	width: fit-content;
}

.page-designer-host__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}
</style>
