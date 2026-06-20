<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuild', 'Template gallery') }}</h1>
			<p class="template-gallery__subtitle">
				{{ t('openbuild', 'Start from a recognisable use case. Every template clones into an editable draft application.') }}
			</p>
		</header>

		<!-- PRIMARY surface: remote store search (only when a registry is configured) -->
		<section v-if="storeConfigured" class="template-gallery__store">
			<div class="template-gallery__filters">
				<NcTextField
					:value="storeSearch"
					:label="t('openbuild', 'Search the template store')"
					:placeholder="t('openbuild', 'Search by name, use case, or description')"
					@update:value="onStoreSearch" />
			</div>

			<div v-if="storeLoading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('openbuild', 'Searching the store…') }}</span>
			</div>

			<div v-else-if="storeError" class="template-gallery__store-error" role="alert">
				<NcEmptyContent :name="t('openbuild', 'Store unavailable')"
					:description="t('openbuild', 'The template store could not be reached. You can still use the built-in templates below.')" />
			</div>

			<div v-else-if="storeCards.length === 0" class="template-gallery__empty">
				<NcEmptyContent :name="t('openbuild', 'No store templates match your search')" />
			</div>

			<ul v-else class="template-gallery__grid">
				<li v-for="card in storeCards" :key="card.slug" class="template-card">
					<img
						v-if="card.screenshotUrl"
						:src="card.screenshotUrl"
						:alt="card.title || card.slug"
						class="template-card__screenshot">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ card.title || card.slug }}
						</h2>
						<span class="template-card__category">{{ categoryLabel(card.category) }}<template v-if="card.version"> · v{{ card.version }}</template></span>
						<p class="template-card__usecase">
							{{ card.useCase || '' }}
						</p>
						<p class="template-card__description">
							{{ card.description || '' }}
						</p>
					</div>
					<div class="template-card__actions">
						<NcButton type="primary" @click="openInstall(card)">
							{{ t('openbuild', 'Install') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<!-- Hint for admins when no registry is configured -->
		<NcNoteCard v-else-if="isAdmin" type="info" class="template-gallery__hint">
			{{ t('openbuild', 'Connect a template registry to browse and install shared templates from a store.') }}
			<a :href="adminSettingsUrl">{{ t('openbuild', 'Configure a registry') }}</a>
		</NcNoteCard>

		<!-- Built-in templates: PRIMARY when no store, SECONDARY when store configured -->
		<section class="template-gallery__local">
			<h2 v-if="storeConfigured" class="template-gallery__local-title">
				{{ t('openbuild', 'Built-in templates') }}
			</h2>

			<div v-if="!storeConfigured" class="template-gallery__filters">
				<NcTextField
					:value="search"
					:label="t('openbuild', 'Search templates')"
					:placeholder="t('openbuild', 'Search by name, use case, or description')"
					@update:value="search = $event" />
				<NcSelect
					v-model="categoryFilter"
					:input-label="t('openbuild', 'Category')"
					:options="categoryOptions"
					:placeholder="t('openbuild', 'All categories')"
					:clearable="true" />
			</div>

			<div v-if="loading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('openbuild', 'Loading templates…') }}</span>
			</div>

			<div v-else-if="filteredTemplates.length === 0" class="template-gallery__empty">
				<NcEmptyContent :name="t('openbuild', 'No templates match your filters')" />
			</div>

			<ul v-else class="template-gallery__grid">
				<li v-for="tpl in filteredTemplates" :key="tpl.slug || tpl.uuid" class="template-card">
					<img
						v-if="tpl.screenshotUrl"
						:src="resolveScreenshot(tpl.screenshotUrl)"
						:alt="tpl.title || tpl.slug"
						class="template-card__screenshot">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ tpl.title || tpl.slug }}
						</h2>
						<span class="template-card__category">{{ categoryLabel(tpl.category) }}</span>
						<p class="template-card__usecase">
							{{ tpl.useCase || '' }}
						</p>
						<p class="template-card__description">
							{{ tpl.description || '' }}
						</p>
					</div>
					<div class="template-card__actions">
						<NcButton type="primary" @click="openClone(tpl)">
							{{ t('openbuild', 'Use this template') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<!-- Local clone dialog -->
		<CloneTemplateDialog
			ref="cloneDialog"
			:open="cloneOpen"
			:template="cloneTarget"
			@close="cloneOpen = false"
			@submit="onCloneSubmit" />

		<!-- Remote install dialog -->
		<CloneTemplateDialog
			ref="installDialog"
			:open="installOpen"
			:template="installTarget"
			:remote="true"
			:remote-slug="installTarget && installTarget.slug ? installTarget.slug : ''"
			@close="installOpen = false"
			@installed="onInstalled" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import CloneTemplateDialog from '../modals/CloneTemplateDialog.vue'
import { useSettingsStore } from '../store/modules/settings.js'

const CATEGORY_LABELS = {
	'government-services': 'Government services',
	'internal-operations': 'Internal operations',
	'citizen-engagement': 'Citizen engagement',
	'field-work': 'Field work',
}

export default {
	name: 'TemplateGallery',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		CloneTemplateDialog,
	},
	data() {
		return {
			templates: [],
			loading: true,
			search: '',
			categoryFilter: null,
			cloneOpen: false,
			cloneTarget: null,
			// Remote store state.
			storeConfigured: false,
			isAdmin: false,
			storeSearch: '',
			storeCards: [],
			storeLoading: false,
			storeError: false,
			storeDebounce: null,
			installOpen: false,
			installTarget: null,
		}
	},
	computed: {
		/**
		 * Observed behaviour of `categoryOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		categoryOptions() {
			return Object.entries(CATEGORY_LABELS).map(([value, label]) => ({
				id: value,
				label: t('openbuild', label),
			}))
		},
		/**
		 * Observed behaviour of `filteredTemplates` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		filteredTemplates() {
			const needle = this.search.trim().toLowerCase()
			const cat = this.categoryFilter?.id ?? this.categoryFilter ?? null
			return this.templates.filter((tpl) => {
				if (cat && tpl.category !== cat) {
					return false
				}
				if (!needle) {
					return true
				}
				const haystack = [tpl.title, tpl.useCase, tpl.description, tpl.slug]
					.map((s) => (s ? String(s).toLowerCase() : ''))
					.join(' ')
				return haystack.includes(needle)
			})
		},
		/**
		 * Deep link to the OpenBuild admin settings page where the registry
		 * connection is configured.
		 *
		 * @return {string} The settings URL.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		adminSettingsUrl() {
			return generateUrl('/settings/admin/openbuild')
		},
	},
	mounted() {
		this.loadStoreState()
		this.fetchTemplates()
	},
	methods: {
		/**
		 * Read the store configuration from the settings store and, when a
		 * registry is configured, perform an initial store search.
		 *
		 * @return {Promise<void>} Resolves once settings are loaded.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		async loadStoreState() {
			const settingsStore = useSettingsStore()
			let settings = settingsStore.getSettings
			if (!settings || Object.keys(settings).length === 0) {
				settings = await settingsStore.fetchSettings() || {}
			}
			this.storeConfigured = !!settings?.storeConfigured
			this.isAdmin = !!settings?.isAdmin
			if (this.storeConfigured) {
				this.searchStore('')
			}
		},
		/**
		 * Observed behaviour of `fetchTemplates` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		async fetchTemplates() {
			this.loading = true
			try {
				// Read templates directly from OpenRegister by register+schema slug.
				// Per hybrid register model: ApplicationTemplate lives in the shared `openbuild` register.
				const url = generateUrl('/apps/openregister/api/objects/openbuild/application-template')
				const resp = await axios.get(url)
				const data = resp.data
				this.templates = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
			} catch (e) {
				console.error('Failed to load templates:', e)
				this.templates = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Handle debounced typing in the store search box.
		 *
		 * @param {string} value The new search term.
		 * @return {void}
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		onStoreSearch(value) {
			this.storeSearch = value
			if (this.storeDebounce) {
				clearTimeout(this.storeDebounce)
			}
			this.storeDebounce = setTimeout(() => {
				this.searchStore(this.storeSearch)
			}, 350)
		},
		/**
		 * Query the remote template store. Non-`ok` outcomes surface a generic
		 * "store unavailable" message without disturbing the built-in list.
		 *
		 * @param {string} term The search term.
		 * @return {Promise<void>} Resolves once the request settles.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		async searchStore(term) {
			this.storeLoading = true
			this.storeError = false
			try {
				const url = generateUrl('/apps/openbuild/api/store/templates')
				const resp = await axios.get(url, { params: { q: term || '' } })
				const data = resp.data
				if (data?.outcome === 'ok') {
					this.storeCards = Array.isArray(data.cards) ? data.cards : []
				} else {
					this.storeCards = []
					this.storeError = true
				}
			} catch (e) {
				console.error('Store search failed:', e)
				this.storeCards = []
				this.storeError = true
			} finally {
				this.storeLoading = false
			}
		},
		/**
		 * Observed behaviour of `resolveScreenshot` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		resolveScreenshot(url) {
			if (!url) {
				return ''
			}
			if (url.startsWith('http') || url.startsWith('/')) {
				return url
			}
			return generateUrl(`/apps/openbuild/${url}`)
		},
		/**
		 * Observed behaviour of `categoryLabel` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		categoryLabel(category) {
			return t('openbuild', CATEGORY_LABELS[category] || category || '')
		},
		/**
		 * Observed behaviour of `openClone` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		openClone(template) {
			this.cloneTarget = template
			this.cloneOpen = true
		},
		/**
		 * Open the install dialog seeded with a remote store card.
		 *
		 * @param {object} card The remote store card.
		 * @return {void}
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		openInstall(card) {
			this.installTarget = card
			this.installOpen = true
		},
		/**
		 * Handle a successful remote install — close and redirect to the editor.
		 *
		 * @param {object} created The created application descriptor.
		 * @return {void}
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		onInstalled(created) {
			this.installOpen = false
			this.redirectAfterClone(created)
		},
		/**
		 * Observed behaviour of `onCloneSubmit` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		async onCloneSubmit(payload) {
			const slug = this.cloneTarget?.slug
			if (!slug) {
				return
			}
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/from-template/${encodeURIComponent(slug)}`)
				const resp = await axios.post(url, payload)
				this.cloneOpen = false
				this.redirectAfterClone(resp.data)
			} catch (e) {
				const data = e?.response?.data
				const message = data?.detail || data?.error || e?.message || t('openbuild', 'Clone failed.')
				this.$refs.cloneDialog?.setError(message)
			}
		},
		/**
		 * Observed behaviour of `redirectAfterClone` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		redirectAfterClone(created) {
			const slug = created?.slug
			if (!slug) {
				return
			}
			// Feature-detect chain #5 page editor; fall back to the manifest-driven
			// virtual-app manager, then the dashboard.
			const editorRoute = this.$router.resolve({ name: 'PageEditor', params: { slug } })
			if (editorRoute?.resolved?.matched?.length > 0) {
				this.$router.push(editorRoute.resolved.fullPath)
				return
			}
			const fallback = this.$router.resolve({ name: 'VirtualApps', params: { slug } })
			if (fallback?.resolved?.matched?.length > 0) {
				this.$router.push(fallback.resolved.fullPath)
				return
			}
			this.$router.push({ name: 'Dashboard' })
		},
	},
}
</script>

<style scoped>
.template-gallery {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 20px;
	color: var(--color-main-text);
}

.template-gallery__header h1 {
	margin: 0 0 4px 0;
}

.template-gallery__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.template-gallery__store,
.template-gallery__local {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.template-gallery__local-title {
	margin: 8px 0 0 0;
	font-size: 1.2rem;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.template-gallery__hint a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.template-gallery__filters {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.template-gallery__loading {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 24px;
	color: var(--color-text-maxcontrast);
}

.template-gallery__empty,
.template-gallery__store-error {
	padding: 32px;
}

.template-gallery__grid {
	list-style: none;
	padding: 0;
	margin: 0;
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 20px;
}

.template-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	overflow: hidden;
}

.template-card__screenshot {
	width: 100%;
	height: 160px;
	object-fit: cover;
	display: block;
	background: var(--color-background-dark);
}

.template-card__body {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 6px;
	flex: 1 1 auto;
}

.template-card__title {
	margin: 0;
	font-size: 1.05rem;
}

.template-card__category {
	font-size: 0.8rem;
	color: var(--color-primary-element);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.template-card__usecase {
	margin: 0;
	font-weight: 500;
}

.template-card__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.template-card__actions {
	padding: 0 16px 16px 16px;
	display: flex;
	justify-content: flex-end;
}
</style>
