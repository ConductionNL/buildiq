<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuild', 'Template gallery') }}</h1>
			<p class="template-gallery__subtitle">
				{{ t('openbuild', 'Start from a recognisable use case. Every template clones into an editable draft application.') }}
			</p>
		</header>

		<div class="template-gallery__tabs" role="tablist">
			<NcButton
				role="tab"
				:aria-selected="source === 'local'"
				:type="source === 'local' ? 'primary' : 'tertiary'"
				@click="setSource('local')">
				{{ t('openbuild', 'Local') }}
			</NcButton>
			<NcButton
				v-if="storeConfigured"
				role="tab"
				:aria-selected="source === 'registry'"
				:type="source === 'registry' ? 'primary' : 'tertiary'"
				@click="setSource('registry')">
				{{ t('openbuild', 'Registry') }}
			</NcButton>
			<NcButton
				role="tab"
				:aria-selected="source === 'github'"
				:type="source === 'github' ? 'primary' : 'tertiary'"
				@click="setSource('github')">
				{{ t('openbuild', 'GitHub') }}
			</NcButton>
		</div>

		<!-- Local + Registry: the recognisable template grid (unchanged card
		     layout); GitHub has its own search box below. -->
		<template v-if="source !== 'github'">
			<div class="template-gallery__filters">
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

			<div v-if="activeLoading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('openbuild', 'Loading templates…') }}</span>
			</div>

			<div v-else-if="visibleCards.length === 0" class="template-gallery__empty">
				<NcEmptyContent :name="t('openbuild', 'No templates match your filters')" />
			</div>

			<ul v-else class="template-gallery__grid" data-walkthrough-id="templates-grid">
				<li v-for="tpl in visibleCards" :key="tpl.slug || tpl.uuid" class="template-card">
					<img
						v-if="tpl.screenshotUrl"
						:src="resolveScreenshot(tpl.screenshotUrl)"
						:alt="tpl.title || tpl.slug"
						class="template-card__screenshot">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ tpl.title || tpl.slug }}
						</h2>
						<span v-if="source === 'local' && isOrgLocal(tpl)" class="template-card__badge">{{ t('openbuild', 'Organisation template') }}</span>
						<span class="template-card__category">{{ categoryLabel(tpl.category) }}</span>
						<p class="template-card__usecase">
							{{ tpl.useCase || '' }}
						</p>
						<p class="template-card__description">
							{{ tpl.description || '' }}
						</p>
					</div>
					<div class="template-card__actions">
						<template v-if="source === 'local'">
							<NcButton
								v-if="canManage(tpl)"
								@click="openEdit(tpl)">
								{{ t('openbuild', 'Edit') }}
							</NcButton>
							<NcButton
								v-if="canManage(tpl)"
								type="error"
								@click="openDelete(tpl)">
								{{ t('openbuild', 'Delete') }}
							</NcButton>
							<NcButton type="primary" @click="openClone(tpl)">
								{{ t('openbuild', 'Use this template') }}
							</NcButton>
						</template>
						<NcButton v-else type="primary" @click="openRegistryInstall(tpl)">
							{{ t('openbuild', 'Install') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</template>

		<!-- GitHub source: server-backed search against topic:openbuild-app. -->
		<template v-else>
			<div class="template-gallery__filters">
				<NcTextField
					:value="githubQuery"
					:label="t('openbuild', 'Search GitHub')"
					:placeholder="t('openbuild', 'Search apps published to GitHub (topic: openbuild-app)')"
					@update:value="onGithubQuery" />
			</div>

			<NcNoteCard v-if="githubUnavailable" type="warning" class="template-gallery__github-hint">
				{{ githubRateLimited
					? t('openbuild', 'GitHub is rate-limiting anonymous browsing right now. Try again shortly.')
					: t('openbuild', 'GitHub could not be reached right now. Try again shortly.') }}
				<span v-if="githubRateLimited && !hasGithubCredential">
					{{ t('openbuild', 'Add a GitHub credential in your OpenRegister credentials settings to raise the rate limit and browse private repositories.') }}
				</span>
			</NcNoteCard>

			<div v-if="githubLoading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('openbuild', 'Searching GitHub…') }}</span>
			</div>

			<div v-else-if="githubCards.length === 0 && githubSearched" class="template-gallery__empty">
				<NcEmptyContent :name="t('openbuild', 'No GitHub apps match your search')" />
			</div>

			<ul v-else class="template-gallery__grid">
				<li v-for="card in githubCards" :key="card.owner + '/' + card.repo" class="template-card">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ card.name || card.slug || card.repo }}
						</h2>
						<span v-if="card.unparseable || !card.installable" class="template-card__badge template-card__badge--warn">
							{{ t('openbuild', 'Unreadable app descriptor') }}
						</span>
						<span v-if="card.category" class="template-card__category">{{ categoryLabel(card.category) }}</span>
						<p class="template-card__description">
							{{ card.description || '' }}
						</p>
						<div class="template-card__github-meta">
							<span class="template-card__chip">{{ card.owner }}/{{ card.repo }}</span>
							<span v-if="card.appType" class="template-card__chip">{{ card.appType }}</span>
							<span v-if="card.version" class="template-card__chip">v{{ card.version }}</span>
							<span v-if="card.stars" class="template-card__chip">★ {{ card.stars }}</span>
						</div>
						<div v-if="card.credentials && card.credentials.length" class="template-card__github-meta">
							<span v-for="cred in card.credentials" :key="cred" class="template-card__chip template-card__chip--muted">
								{{ t('openbuild', 'Needs credential: {name}', { name: cred }) }}
							</span>
						</div>
					</div>
					<div class="template-card__actions">
						<NcButton
							v-if="card.installable && !card.unparseable"
							type="primary"
							@click="openGithubInstall(card)">
							{{ t('openbuild', 'Install') }}
						</NcButton>
						<span v-else class="template-card__disabled-hint">
							{{ t('openbuild', 'This repository has no readable OpenBuild descriptor and cannot be installed.') }}
						</span>
					</div>
				</li>
			</ul>
		</template>

		<CloneTemplateDialog
			ref="cloneDialog"
			:open="cloneOpen"
			:template="cloneTarget"
			:remote="cloneMode === 'remote'"
			:remote-slug="cloneRemoteSlug"
			:github="cloneMode === 'github'"
			:github-repo="cloneGithubRepo"
			@close="cloneOpen = false"
			@submit="onCloneSubmit"
			@installed="onInstalled" />

		<EditTemplateMetadataDialog
			:open="editOpen"
			:template="editTarget"
			@update:open="editOpen = $event"
			@saved="onTemplateChanged" />

		<NcDialog
			:open="deleteOpen"
			:name="t('openbuild', 'Delete template')"
			@update:open="deleteOpen = $event">
			<p class="template-gallery__delete-confirm">
				{{ t('openbuild', 'Delete the template "{title}"? Applications previously cloned from it are not affected — only the template record is removed.', { title: (deleteTarget && (deleteTarget.title || deleteTarget.slug)) || '' }) }}
			</p>
			<template #actions>
				<NcButton @click="deleteOpen = false">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="deleting" @click="confirmDelete">
					{{ deleting ? t('openbuild', 'Deleting…') : t('openbuild', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl, imagePath } from '@nextcloud/router'
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import CloneTemplateDialog from '../modals/CloneTemplateDialog.vue'
import EditTemplateMetadataDialog from '../dialogs/EditTemplateMetadataDialog.vue'

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
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		CloneTemplateDialog,
		EditTemplateMetadataDialog,
	},
	data() {
		return {
			templates: [],
			loading: true,
			search: '',
			categoryFilter: null,
			cloneOpen: false,
			cloneTarget: null,
			// Install routing for the shared CloneTemplateDialog:
			// 'local' (clone), 'remote' (registry store), or 'github' (shop).
			cloneMode: 'local',
			cloneRemoteSlug: '',
			cloneGithubRepo: null,
			editOpen: false,
			editTarget: null,
			deleteOpen: false,
			deleteTarget: null,
			deleting: false,
			// Source tabs (github-shop-catalogue): 'local' | 'registry' | 'github'.
			source: 'local',
			// Registry (remote store) source.
			storeConfigured: false,
			registryCards: [],
			registryLoading: false,
			// GitHub source.
			githubQuery: '',
			githubCards: [],
			githubLoading: false,
			githubSearched: false,
			githubOutcome: '',
			githubRateLimited: false,
			githubBrokerAvailable: false,
			githubCredentialId: null,
			hasGithubCredential: false,
			githubDebounce: null,
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
			return this.filterCards(this.templates)
		},
		/**
		 * Whether the active (non-GitHub) source is still loading.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		activeLoading() {
			return this.source === 'registry' ? this.registryLoading : this.loading
		},
		/**
		 * The template cards shown in the Local or Registry grid, filtered by the
		 * shared search + category filters. GitHub has its own grid.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		visibleCards() {
			return this.source === 'registry' ? this.filterCards(this.registryCards) : this.filteredTemplates
		},
		/**
		 * Whether GitHub browsing is currently degraded (rate-limited or
		 * unreachable) — drives the non-blocking hint.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		githubUnavailable() {
			return this.githubRateLimited || (this.githubOutcome !== '' && this.githubOutcome !== 'ok')
		},
	},
	mounted() {
		this.fetchTemplates()
		this.probeRegistry()
		this.fetchGithubCredentials()
	},
	methods: {
		/**
		 * Shared search + category filter used by the Local and Registry grids.
		 *
		 * @param {Array<object>} list The cards to filter.
		 * @return {Array<object>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		filterCards(list) {
			const needle = this.search.trim().toLowerCase()
			const cat = this.categoryFilter?.id ?? this.categoryFilter ?? null
			return (Array.isArray(list) ? list : []).filter((tpl) => {
				if (cat && tpl.category !== cat) {
					return false
				}
				if (!needle) {
					return true
				}
				const haystack = [tpl.title, tpl.name, tpl.useCase, tpl.description, tpl.slug]
					.map((s) => (s ? String(s).toLowerCase() : ''))
					.join(' ')
				return haystack.includes(needle)
			})
		},
		/**
		 * Switch the active source tab. Entering the GitHub tab for the first time
		 * runs the initial (empty-query) search so the topic-listed apps appear.
		 *
		 * @param {string} next The source id ('local' | 'registry' | 'github').
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		setSource(next) {
			this.source = next
			if (next === 'registry' && this.registryCards.length === 0) {
				this.fetchRegistry()
			}
			if (next === 'github' && !this.githubSearched) {
				this.searchGithub()
			}
		},
		/**
		 * Probe the remote store search endpoint once to decide whether to offer
		 * the Registry tab. A `not_configured` outcome hides it (no registry set).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		async probeRegistry() {
			try {
				const { data } = await axios.get(generateUrl('/apps/openbuild/api/store/templates'))
				if (data && data.outcome && data.outcome !== 'not_configured') {
					this.storeConfigured = true
					this.registryCards = Array.isArray(data.cards) ? data.cards : []
				}
			} catch (e) {
				// Store unreachable/misconfigured — simply omit the Registry tab.
				this.storeConfigured = false
			}
		},
		/**
		 * (Re)fetch the remote store template cards for the Registry tab.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		async fetchRegistry() {
			this.registryLoading = true
			try {
				const url = generateUrl('/apps/openbuild/api/store/templates')
				const params = {}
				const needle = this.search.trim()
				if (needle) {
					params.q = needle
				}
				const { data } = await axios.get(url, { params })
				this.registryCards = Array.isArray(data?.cards) ? data.cards : []
			} catch (e) {
				this.registryCards = []
			} finally {
				this.registryLoading = false
			}
		},
		/**
		 * Debounced handler for the GitHub search box.
		 *
		 * @param {string} value The new query.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		onGithubQuery(value) {
			this.githubQuery = value
			if (this.githubDebounce) {
				clearTimeout(this.githubDebounce)
			}
			this.githubDebounce = setTimeout(() => {
				this.searchGithub()
			}, 350)
		},
		/**
		 * Call the GitHub shop search endpoint and render the result cards.
		 * Passes the user's advisory github credential id (when present) so
		 * private repos + the raised rate limit apply; anonymous otherwise.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		async searchGithub() {
			this.githubLoading = true
			try {
				const url = generateUrl('/apps/openbuild/api/shop/github/search')
				const params = { q: this.githubQuery.trim() }
				if (this.githubCredentialId) {
					params.credentialId = this.githubCredentialId
				}
				const { data } = await axios.get(url, { params })
				this.githubCards = Array.isArray(data?.cards) ? data.cards : []
				this.githubOutcome = data?.outcome || 'ok'
				this.githubRateLimited = !!data?.rateLimited
				this.githubBrokerAvailable = !!data?.brokerCredentialAvailable
			} catch (e) {
				this.githubCards = []
				this.githubOutcome = 'github_unreachable'
				this.githubRateLimited = false
			} finally {
				this.githubSearched = true
				this.githubLoading = false
			}
		},
		/**
		 * Feature-detect an allowed github credential via OpenRegister's
		 * credentials API (advisory only — the server-side broker is the
		 * authoritative gate). Populates the search credential id + the
		 * add-a-credential pointer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		async fetchGithubCredentials() {
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/credentials'))
				const list = Array.isArray(data) ? data : (Array.isArray(data?.results) ? data.results : [])
				const github = list.filter((c) => c && c.provider === 'github')
				this.hasGithubCredential = github.length > 0
				this.githubCredentialId = github.length ? (github[0].id || null) : null
			} catch (e) {
				this.hasGithubCredential = false
				this.githubCredentialId = null
			}
		},
		/**
		 * Open the clone dialog to install a Registry (remote store) template.
		 *
		 * @param {object} card The remote store card.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		openRegistryInstall(card) {
			this.cloneTarget = card
			this.cloneMode = 'remote'
			this.cloneRemoteSlug = card.slug || ''
			this.cloneGithubRepo = null
			this.cloneOpen = true
		},
		/**
		 * Open the clone dialog to install a GitHub app, seeded with the card.
		 *
		 * @param {object} card The GitHub result card.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		openGithubInstall(card) {
			this.cloneTarget = { title: card.name || card.slug || card.repo, slug: card.slug || card.repo, description: card.description }
			this.cloneMode = 'github'
			this.cloneRemoteSlug = ''
			this.cloneGithubRepo = { owner: card.owner, repo: card.repo }
			this.cloneOpen = true
		},
		/**
		 * Redirect to the new application after a Registry/GitHub install
		 * (the dialog owns the POST and emits `installed`).
		 *
		 * @param {object} created The created application payload.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		onInstalled(created) {
			this.cloneOpen = false
			this.redirectAfterClone(created)
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
		 * Observed behaviour of `resolveScreenshot` (retrofit annotation).
		 *
		 * @param url
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		resolveScreenshot(url) {
			if (!url) {
				return ''
			}
			// Absolute URLs and root-relative paths are used verbatim.
			if (url.startsWith('http') || url.startsWith('/')) {
				return url
			}
			// App-relative screenshots (e.g. "img/templates/permit-tracker.svg")
			// are static app assets. Resolve them via imagePath — which yields the
			// web-root path /apps/openbuild/img/… served directly by the web
			// server. generateUrl would prefix /index.php and route through the PHP
			// app router, which has no route for img/* and returns the app HTML
			// shell (200 text/html) instead of the image.
			return imagePath('openbuild', url.replace(/^img\//, ''))
		},
		/**
		 * Observed behaviour of `categoryLabel` (retrofit annotation).
		 *
		 * @param category
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		categoryLabel(category) {
			return t('openbuild', CATEGORY_LABELS[category] || category || '')
		},
		/**
		 * Observed behaviour of `openClone` (retrofit annotation).
		 *
		 * @param template
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		openClone(template) {
			this.cloneTarget = template
			this.cloneMode = 'local'
			this.cloneRemoteSlug = ''
			this.cloneGithubRepo = null
			this.cloneOpen = true
		},
		/**
		 * Whether a template is org-local (user-submitted, REQ-SAT-005).
		 * Seeded templates render the read-only REQ-OBTC-008 card unchanged.
		 *
		 * @param {object} tpl A template record.
		 * @return {boolean}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		isOrgLocal(tpl) {
			return tpl && tpl.isSeeded === false
		},
		/**
		 * Whether Edit/Delete actions render for a card — only for org-local
		 * templates the caller may write per OR's per-object rights (no
		 * openbuild-local role logic, REQ-SAT-005/006). Seeded templates are
		 * never manageable in the UI (REQ-OBTC-008).
		 *
		 * @param {object} tpl A template record.
		 * @return {boolean}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		canManage(tpl) {
			if (!this.isOrgLocal(tpl)) {
				return false
			}
			const self = (tpl && tpl['@self']) || {}
			const canWrite = self.canWrite ?? tpl.canWrite
			return canWrite !== false
		},
		/**
		 * Open the metadata-edit dialog for an org-local template.
		 *
		 * @param {object} template The template record.
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		openEdit(template) {
			this.editTarget = template
			this.editOpen = true
		},
		/**
		 * Open the delete-confirm dialog for an org-local template.
		 *
		 * @param {object} template The template record.
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		openDelete(template) {
			this.deleteTarget = template
			this.deleteOpen = true
		},
		/**
		 * Delete the template record via OR REST. Removes only the
		 * ApplicationTemplate — cloned + source apps are untouched
		 * (REQ-SAT-005). Zero new PHP (REQ-SAT-006).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		async confirmDelete() {
			if (!this.deleteTarget || this.deleting) {
				return
			}
			this.deleting = true
			try {
				const tpl = this.deleteTarget
				const uuid = (tpl['@self'] && tpl['@self'].id) || tpl.uuid || tpl.id
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application-template/${encodeURIComponent(uuid)}`)
				await axios.delete(url)
				this.deleteOpen = false
				this.deleteTarget = null
				await this.fetchTemplates()
			} catch (e) {
				console.error('Failed to delete template:', e)
			} finally {
				this.deleting = false
			}
		},
		/**
		 * Refresh the gallery after a metadata edit.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		async onTemplateChanged() {
			this.editOpen = false
			await this.fetchTemplates()
		},
		/**
		 * Observed behaviour of `onCloneSubmit` (retrofit annotation).
		 *
		 * @param payload
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
		 * @param created
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		redirectAfterClone(created) {
			const slug = created?.slug
			if (!slug) {
				return
			}
			// Feature-detect chain #5 page editor; fall back to the manifest-driven
			// virtual-app manager, then the dashboard. Routes are registered from
			// the manifest with `name = page.id` (see main.js#routesFromManifest),
			// so probe by name against the registered route table first —
			// $router.resolve() on an unknown name emits a vue-router warning.
			if (this.hasRoute('PageEditor')) {
				this.$router.push({ name: 'PageEditor', params: { slug } })
				return
			}
			if (this.hasRoute('VirtualApps')) {
				this.$router.push({ name: 'VirtualApps', params: { slug } })
				return
			}
			this.$router.push({ name: 'Dashboard' })
		},
		/**
		 * Whether a named route is registered on the router. Routes are built
		 * from the manifest (flat, `name = page.id`), so a shallow scan of
		 * `$router.options.routes` is sufficient and avoids the vue-router
		 * warning that `$router.resolve()` logs for unknown route names.
		 *
		 * @param {string} name The route name to check.
		 * @return {boolean} True when a route with that name is registered.
		 */
		hasRoute(name) {
			const routes = this.$router?.options?.routes || []
			return routes.some((route) => route.name === name)
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

.template-gallery__tabs {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 8px;
}

.template-gallery__filters {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.template-gallery__github-hint {
	margin: 0;
}

.template-card__badge--warn {
	background: var(--color-warning, #d99000);
	color: var(--color-primary-element-text, #fff);
}

.template-card__github-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 4px;
}

.template-card__chip {
	font-size: 0.75rem;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.template-card__chip--muted {
	color: var(--color-text-maxcontrast);
}

.template-card__disabled-hint {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}

.template-gallery__loading {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 24px;
	color: var(--color-text-maxcontrast);
}

.template-gallery__empty {
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

.template-card__badge {
	align-self: flex-start;
	font-size: 0.7rem;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-primary-element-light, var(--color-background-dark));
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
