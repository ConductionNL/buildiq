<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  The app store's "Shared catalogue" section (reuse-remote-store-section): the
  templates of the remote catalogue an administrator configured, between the
  built-in templates and the apps on GitHub, in the GitHub section's card shape.
  Renders nothing when no catalogue is configured; a catalogue that cannot be
  reached shows one warning inside the section and nothing else.
-->
<template>
	<section
		v-if="configured"
		class="template-gallery__section"
		data-testid="shared-catalogue"
		aria-labelledby="shared-catalogue-heading">
		<h2 id="shared-catalogue-heading" class="template-gallery__section-title">
			{{ t('buildiq', 'Shared catalogue') }}
		</h2>
		<p v-if="source" class="shared-catalogue__lead" data-testid="shared-catalogue-source">
			{{ t('buildiq', 'From {source}', { source }) }}
		</p>

		<div class="template-gallery__filters">
			<NcTextField
				:modelValue="query"
				:label="t('buildiq', 'Search the catalogue')"
				@update:modelValue="onQuery" />
		</div>

		<NcNoteCard
			v-if="unreachable"
			type="warning"
			data-testid="shared-catalogue-unreachable">
			{{ t('buildiq', 'The shared catalogue could not be reached. Try again later.') }}
		</NcNoteCard>

		<div v-else-if="loading" class="template-gallery__loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('buildiq', 'Loading templates…') }}</span>
		</div>

		<NcEmptyContent
			v-else-if="visibleCards.length === 0"
			:name="t('buildiq', 'No templates in the catalogue match your search')" />

		<ul v-else class="template-gallery__grid">
			<li
				v-for="card in visibleCards"
				:key="card.slug"
				class="template-card"
				data-testid="shared-catalogue-card">
				<div class="template-card__body">
					<h3 class="template-card__title">
						{{ card.title || card.slug }}
					</h3>
					<span v-if="card.category" class="template-card__category">{{
						categoryLabel(card.category)
					}}</span>
					<div v-if="card.useCase" class="template-card__github-meta">
						<span class="template-card__chip">{{ card.useCase }}</span>
					</div>
					<p class="template-card__description">
						{{ card.description || '' }}
					</p>
					<div v-if="card.version" class="template-card__github-meta">
						<span class="template-card__chip">v{{ card.version }}</span>
					</div>
				</div>
				<div class="template-card__actions">
					<NcButton variant="primary" @click="$emit('install', card)">
						{{ t('buildiq', 'Install') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'

/**
 * The search pause before the catalogue is asked again, in milliseconds.
 */
export const SEARCH_DEBOUNCE_MS = 300

/**
 * The outcomes that mean the catalogue is configured but did not answer usefully.
 */
const UNREACHABLE_OUTCOMES = ['store_unreachable', 'store_invalid_response']

export default {
	name: 'SharedCatalogueSection',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/**
		 * The category id the view is narrowed to, or '' for all.
		 */
		category: { type: String, default: '' },
		/**
		 * Turns a category id into its label, as the view shows it.
		 */
		categoryLabel: { type: Function, default: (category) => category },
	},

	emits: ['install', 'configured'],

	data() {
		return {
			// Hidden until the catalogue answers with anything but not_configured.
			configured: false,
			outcome: '',
			source: '',
			cards: [],
			query: '',
			loading: false,
			debounce: null,
		}
	},

	computed: {
		/**
		 * Whether the catalogue was configured but could not be read.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-store-errors-stay-inside-the-section-req-bqrs-003
		 */
		unreachable() {
			return UNREACHABLE_OUTCOMES.includes(this.outcome)
		},

		/**
		 * The cards after the view's category filter.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-the-shared-catalogue-can-be-searched-and-filtered-req-bqrs-002
		 */
		visibleCards() {
			if (!this.category) {
				return this.cards
			}
			return this.cards.filter((card) => card.category === this.category)
		},
	},

	/**
	 * Ask the catalogue once on mount.
	 *
	 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-the-app-store-shows-the-shared-catalogue-as-a-section-req-bqrs-001
	 */
	mounted() {
		this.load()
	},

	beforeUnmount() {
		clearTimeout(this.debounce)
	},

	methods: {
		/**
		 * Read the catalogue, with the current search term.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-the-app-store-shows-the-shared-catalogue-as-a-section-req-bqrs-001
		 */
		async load() {
			this.loading = true
			const term = this.query.trim()
			const params = term ? { q: term } : {}
			try {
				const resp = await axios.get(generateUrl('/apps/buildiq/api/store/templates'), { params })
				const data = resp?.data || {}
				this.outcome = String(data.outcome || '')
				this.source = String(data.source || '')
				this.cards = Array.isArray(data.cards) ? data.cards : []
			} catch {
				this.outcome = 'store_unreachable'
				this.cards = []
			} finally {
				this.loading = false
			}
			if (this.outcome !== 'not_configured' && !this.configured) {
				this.configured = true
			}
			this.$emit('configured', this.outcome !== 'not_configured')
		},

		/**
		 * Search the catalogue after a pause in typing.
		 *
		 * @param {string} value The search term.
		 * @return {void}
		 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-the-shared-catalogue-can-be-searched-and-filtered-req-bqrs-002
		 */
		onQuery(value) {
			this.query = String(value || '')
			clearTimeout(this.debounce)
			this.debounce = setTimeout(() => this.load(), SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Read the catalogue again, for a template that left it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-a-maker-installs-a-catalogue-template-req-bqrs-004
		 */
		reload() {
			return this.load()
		},
	},
}
</script>

<style scoped>
.shared-catalogue__lead {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}
</style>
