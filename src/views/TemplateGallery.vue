<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuild', 'App store') }}</h1>
			<p class="template-gallery__subtitle">
				{{ t('openbuild', 'Install an app published to GitHub. The store lists every repository tagged with the openbuild-app topic; installing clones it into an editable draft application.') }}
			</p>
		</header>

		<!-- GitHub store: server-backed search against topic:openbuild-app. -->
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

		<CloneTemplateDialog
			:open="cloneOpen"
			:template="cloneTarget"
			:github="true"
			:github-repo="cloneGithubRepo"
			@close="cloneOpen = false"
			@installed="onInstalled" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import CloneTemplateDialog from '../modals/CloneTemplateDialog.vue'

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
			return this.githubRateLimited || (this.githubOutcome !== '' && this.githubOutcome !== 'ok')
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
		 * Open the clone dialog to install a GitHub app, seeded with the card.
		 *
		 * @param {object} card The GitHub result card.
		 * @return {void}
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		openGithubInstall(card) {
			this.cloneTarget = { title: card.name || card.slug || card.repo, slug: card.slug || card.repo, description: card.description }
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
			this.redirectAfterClone(created)
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
		 * @param created
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
