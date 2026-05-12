<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  PageDesignerHost — route-level host for the visual page designer
  (/builder/:slug/pages). Resolves the slug to its Application via
  OpenRegister's objects API, hands the stored `manifest` to the
  controlled <PageDesigner> component, and persists edits back with a
  PUT. PageDesigner itself stays a pure controlled component (manifest
  prop in, update:manifest / save-and-preview events out) so it can also
  be embedded as a tab in ApplicationEditor later.

  Tracks issue #26 (PageDesigner used to render with an empty manifest).
-->
<template>
	<div class="page-designer-host">
		<header class="page-designer-host__header">
			<div class="page-designer-host__title">
				<h2>{{ application ? application.name : t('openbuilt', 'Page designer') }}</h2>
				<p v-if="application" class="page-designer-host__subtitle">
					{{ t('openbuilt', 'Design the pages and menu of this virtual app, then publish from Virtual apps.') }}
				</p>
			</div>
			<div class="page-designer-host__actions">
				<router-link class="page-designer-host__link" :to="{ name: 'VirtualApps' }">
					{{ t('openbuilt', 'Back to Virtual apps') }}
				</router-link>
				<a v-if="builderUrl" class="page-designer-host__link" :href="builderUrl">
					{{ t('openbuilt', 'Open virtual app') }}
				</a>
				<NcButton
					v-if="application"
					type="primary"
					:disabled="saving"
					@click="save">
					{{ saving ? t('openbuilt', 'Saving…') : t('openbuilt', 'Save pages') }}
				</NcButton>
			</div>
		</header>

		<div v-if="toast" class="page-designer-host__toast">
			{{ toast }}
		</div>
		<div v-if="error" class="page-designer-host__error">
			{{ error }}
		</div>

		<div v-if="loading" class="page-designer-host__loading">
			<NcLoadingIcon :size="44" />
		</div>
		<NcEmptyContent
			v-else-if="!application"
			:name="t('openbuilt', 'No virtual app found')"
			:description="t('openbuilt', 'No virtual app exists for the slug {slug}.', { slug: routeSlug })" />
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
		}
	},

	computed: {
		/**
		 * The virtual-app slug from the route (/builder/:slug/pages).
		 *
		 * @return {string}
		 */
		routeSlug() {
			return this.$route.params.slug || ''
		},

		/**
		 * The Application's canonical UUID (OR puts it at @self.id).
		 *
		 * @return {string}
		 */
		applicationUuid() {
			const self = this.application && this.application['@self']
			return (self && self.id) || (this.application && this.application.uuid) || ''
		},

		/**
		 * Full-page link into the virtual app, if it has ever been published.
		 *
		 * @return {string}
		 */
		builderUrl() {
			if (!this.application) {
				return ''
			}
			const published = this.application.currentVersion || this.application.status === 'published'
			return published ? generateUrl(`/apps/openbuilt/builder/${this.application.slug}`) : ''
		},
	},

	watch: {
		routeSlug() {
			this.load()
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Fetch the Application for the current slug and seed the editor manifest.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.toast = ''
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuilt/application')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				const apps = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				const app = apps.find(a => a && a.slug === this.routeSlug) || null
				this.application = app
				this.manifest = (app && app.manifest && typeof app.manifest === 'object')
					? JSON.parse(JSON.stringify(app.manifest))
					: { ...EMPTY_MANIFEST }
			} catch (e) {
				this.application = null
				this.error = t('openbuilt', 'Failed to load the virtual app: {error}', { error: (e && e.message) || String(e) })
			} finally {
				this.loading = false
			}
		},

		/**
		 * Receive an edited manifest from the controlled PageDesigner.
		 *
		 * @param {object} next The new manifest.
		 * @return {void}
		 */
		onManifestUpdate(next) {
			this.manifest = next
		},

		/**
		 * Persist the edited manifest onto the Application object.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.application || !this.applicationUuid || this.saving) {
				return
			}
			this.saving = true
			this.error = ''
			this.toast = ''
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.applicationUuid}`)
				const { data } = await axios.put(url, { ...this.application, manifest: this.manifest })
				if (data && typeof data === 'object') {
					this.application = data
				}
				this.toast = t('openbuilt', 'Pages saved.')
			} catch (e) {
				this.error = t('openbuilt', 'Failed to save: {error}', { error: (e && e.message) || String(e) })
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
