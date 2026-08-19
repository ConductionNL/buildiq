<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuild', 'App store') }}</h1>
			<p class="template-gallery__subtitle">
				{{
					t(
						'openbuild',
						'Install an app published to GitHub. The store lists every repository tagged with the openbuild-app topic; installing clones it into an editable draft application.',
					)
				}}
			</p>
		</header>

		<!-- component-blocks REQ-OBTC-003: top-level Templates/Blocks toggle. -->
		<div class="template-gallery__view-toggle" role="tablist">
			<button
				type="button"
				role="tab"
				:aria-selected="viewMode === 'templates'"
				class="template-gallery__view-btn"
				:class="[
					{
						'template-gallery__view-btn--active':
							viewMode === 'templates',
					},
				]"
				@click="viewMode = 'templates'">
				{{ t('openbuild', 'Templates') }}
			</button>
			<button
				type="button"
				role="tab"
				:aria-selected="viewMode === 'blocks'"
				class="template-gallery__view-btn"
				:class="[
					{ 'template-gallery__view-btn--active': viewMode === 'blocks' },
				]"
				@click="onSelectBlocksTab">
				{{ t('openbuild', 'Blocks') }}
			</button>
		</div>

		<template v-if="viewMode === 'templates'">
			<!-- GitHub store: server-backed search against topic:openbuild-app. -->
			<div class="template-gallery__filters">
				<NcTextField
					:modelValue="githubQuery"
					:label="t('openbuild', 'Search GitHub')"
					:placeholder="
						t(
							'openbuild',
							'Search apps published to GitHub (topic: openbuild-app)',
						)
					"
					@update:modelValue="onGithubQuery" />
			</div>

			<NcNoteCard
				v-if="githubUnavailable"
				type="warning"
				class="template-gallery__github-hint">
				{{
					githubRateLimited
						? t(
								'openbuild',
								'GitHub is rate-limiting anonymous browsing right now. Try again shortly.',
							)
						: t(
								'openbuild',
								'GitHub could not be reached right now. Try again shortly.',
							)
				}}
				<span v-if="githubRateLimited && !hasGithubCredential">
					{{
						t(
							'openbuild',
							'Add a GitHub credential in your OpenRegister credentials settings to raise the rate limit and browse private repositories.',
						)
					}}
				</span>
			</NcNoteCard>

			<div v-if="githubLoading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
				<span>{{ t('openbuild', 'Searching GitHub…') }}</span>
			</div>

			<div
				v-else-if="githubCards.length === 0 && githubSearched"
				class="template-gallery__empty">
				<NcEmptyContent
					:name="t('openbuild', 'No GitHub apps match your search')" />
			</div>

			<ul
				v-else
				class="template-gallery__grid"
				data-walkthrough-id="templates-grid">
				<li
					v-for="card in githubCards"
					:key="card.owner + '/' + card.repo"
					class="template-card">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ card.name || card.slug || card.repo }}
						</h2>
						<span
							v-if="card.unparseable || !card.installable"
							class="template-card__badge template-card__badge--warn">
							{{ t('openbuild', 'Unreadable app descriptor') }}
						</span>
						<span v-if="card.category" class="template-card__category">{{
							categoryLabel(card.category)
						}}</span>
						<p class="template-card__description">
							{{ card.description || '' }}
						</p>
						<div class="template-card__github-meta">
							<span class="template-card__chip"
								>{{ card.owner }}/{{ card.repo }}</span
							>
							<span v-if="card.appType" class="template-card__chip">{{
								card.appType
							}}</span>
							<span v-if="card.version" class="template-card__chip"
								>v{{ card.version }}</span
							>
							<span v-if="card.stars" class="template-card__chip"
								>★ {{ card.stars }}</span
							>
						</div>
						<div
							v-if="card.credentials && card.credentials.length"
							class="template-card__github-meta">
							<span
								v-for="cred in card.credentials"
								:key="cred"
								class="template-card__chip template-card__chip--muted">
								{{
									t('openbuild', 'Needs credential: {name}', {
										name: cred,
									})
								}}
							</span>
						</div>
					</div>
					<div class="template-card__actions">
						<NcButton
							v-if="card.installable && !card.unparseable"
							variant="primary"
							@click="openGithubInstall(card)">
							{{ t('openbuild', 'Install') }}
						</NcButton>
						<span v-else class="template-card__disabled-hint">
							{{
								t(
									'openbuild',
									'This repository has no readable OpenBuild descriptor and cannot be installed.',
								)
							}}
						</span>
					</div>
				</li>
			</ul>
		</template>

		<!-- component-blocks: "Blocks" filter — browse-only, no clone action
		     (blocks insert via the page designer's block library, per
		     REQ "Blocks filter shows blocks without the clone action"). -->
		<template v-else>
			<div class="template-gallery__filters">
				<NcSelect
					v-model="blockCategoryFilter"
					:inputLabel="t('openbuild', 'Filter by category')"
					:options="blockCategoryOptions"
					:clearable="true"
					:placeholder="t('openbuild', 'All categories')" />
			</div>

			<div v-if="blocksLoading" class="template-gallery__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<div
				v-else-if="filteredBlocks.length === 0"
				class="template-gallery__empty">
				<NcEmptyContent
					:name="t('openbuild', 'No blocks yet')"
					:description="
						t(
							'openbuild',
							'Save a widget or section from a page designer to build your first block.',
						)
					" />
			</div>

			<ul v-else class="template-gallery__grid">
				<li
					v-for="block in filteredBlocks"
					:key="block.slug"
					class="template-card">
					<div class="template-card__body">
						<h2 class="template-card__title">
							{{ block.name }}
						</h2>
						<span
							v-if="block.category"
							class="template-card__category"
							>{{ block.category }}</span
						>
						<p class="template-card__description">
							{{ block.description }}
						</p>
					</div>
				</li>
			</ul>
		</template>

		<CloneTemplateDialog
			:open="cloneOpen"
			:template="cloneTarget"
			:github="true"
			:githubRepo="cloneGithubRepo"
			@close="cloneOpen = false"
			@installed="onInstalled" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CloneTemplateDialog from '../modals/CloneTemplateDialog.vue'

const OR_BLOCKS = '/apps/openregister/api/objects/openbuild/component-block'

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
			cloneOpen: false,
			cloneTarget: null,
			cloneGithubRepo: null,
			// GitHub store.
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
			// component-blocks REQ-OBTC-003: Templates/Blocks toggle + block list.
			viewMode: 'templates',
			blocks: [],
			blocksLoading: false,
			blocksLoaded: false,
			blockCategoryFilter: null,
		}
	},

	computed: {
		/**
		 * Whether GitHub browsing is currently degraded (rate-limited or
		 * unreachable) — drives the non-blocking hint.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		githubUnavailable() {
			return (
				this.githubRateLimited
				|| (this.githubOutcome !== '' && this.githubOutcome !== 'ok')
			)
		},

		/**
		 * Distinct categories present across the loaded blocks, for the filter.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/component-blocks/specs/openbuild-template-catalogue/spec.md
		 */
		blockCategoryOptions() {
			const seen = new Set()
			return this.blocks
				.map((b) => b && b.category)
				.filter((c) => c && !seen.has(c) && seen.add(c))
				.map((c) => ({ id: c, label: c }))
		},

		/**
		 * The visible blocks after the category filter is applied.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/component-blocks/specs/openbuild-template-catalogue/spec.md
		 */
		filteredBlocks() {
			const selected =
				this.blockCategoryFilter
				&& (this.blockCategoryFilter.id ?? this.blockCategoryFilter)
			if (!selected) {
				return this.blocks
			}
			return this.blocks.filter((b) => b && b.category === selected)
		},
	},

	mounted() {
		// The store is GitHub-only: run the initial (empty-query) search so the
		// topic:openbuild-app repositories appear, and feature-detect a github
		// credential for the raised rate limit + private repos.
		this.searchGithub()
		this.fetchGithubCredentials()
	},

	methods: {
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
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				const list = Array.isArray(data)
					? data
					: Array.isArray(data?.results)
						? data.results
						: []
				const github = list.filter((c) => c && c.provider === 'github')
				this.hasGithubCredential = github.length > 0
				this.githubCredentialId = github.length ? github[0].id || null : null
			} catch (e) {
				this.hasGithubCredential = false
				this.githubCredentialId = null
			}
		},

		/**
		 * Open the clone dialog to install a GitHub app, seeded with the card.
		 *
		 * @param {object} card The GitHub result card.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		openGithubInstall(card) {
			this.cloneTarget = {
				title: card.name || card.slug || card.repo,
				slug: card.slug || card.repo,
				description: card.description,
			}
			this.cloneGithubRepo = { owner: card.owner, repo: card.repo }
			this.cloneOpen = true
		},

		/**
		 * Redirect to the new application after a GitHub install (the dialog owns
		 * the POST and emits `installed`).
		 *
		 * @param {object} created The created application payload.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		onInstalled(created) {
			this.cloneOpen = false
			// The install still succeeds (best-effort, no atomicity — see
			// app-channel-application), but a warning (e.g. a credential
			// missing the scope the skills channel's hermiq delegation
			// needs) must not silently vanish just because this handler
			// redirects immediately afterwards.
			this.showInstallWarnings(created)
			this.redirectAfterClone(created)
		},

		/**
		 * Toast any top-level `warnings` the install response carries.
		 *
		 * @param {{warnings?: Array<{message: string}>}} created The install response.
		 * @return {void}
		 * @spec openspec/changes/surface-hermiq-credential-scope-requirement/specs/app-channel-application/spec.md
		 */
		showInstallWarnings(created) {
			const warnings = Array.isArray(created?.warnings) ? created.warnings : []
			warnings.forEach((warning) => {
				showWarning(warning.message)
			})
		},

		/**
		 * Human-readable label for a template/app category.
		 *
		 * @param {string} category The category key.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-1
		 */
		categoryLabel(category) {
			return t('openbuild', CATEGORY_LABELS[category] || category || '')
		},

		/**
		 * Observed behaviour of `redirectAfterClone` (retrofit annotation).
		 *
		 * @param {{slug: string}} created - The Application just cloned/installed from
		 *   a template, as returned by the clone endpoint. Only its `slug` is used, to
		 *   build the route params; a payload without one (a backend that answered 200
		 *   with no body) leaves the user on the gallery rather than navigating
		 *   nowhere.
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

		/**
		 * Switch to the "Blocks" filter, lazily fetching the block list the
		 * first time it is opened.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/openbuild-template-catalogue/spec.md
		 */
		onSelectBlocksTab() {
			this.viewMode = 'blocks'
			if (!this.blocksLoaded) {
				this.fetchBlocks()
			}
		},

		/**
		 * Fetch every `ComponentBlock` visible to the caller (org-scoped, same
		 * OR REST listing the page designer's block-library panel uses).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/openbuild-template-catalogue/spec.md
		 */
		async fetchBlocks() {
			this.blocksLoading = true
			try {
				const { data } = await axios.get(generateUrl(OR_BLOCKS))
				this.blocks = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (e) {
				this.blocks = []
			} finally {
				this.blocksLoading = false
				this.blocksLoaded = true
			}
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

.template-gallery__filters {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.template-gallery__view-toggle {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

.template-gallery__view-btn {
	padding: 8px 16px;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	border-bottom: 2px solid transparent;
}

.template-gallery__view-btn--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
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
