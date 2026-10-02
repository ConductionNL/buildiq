<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	FormLibraryView: the app store's "Forms" view
	(reuse-gallery-categories-and-form-library, REQ-BQGL-003 and REQ-BQGL-005).

	Lists the library forms with a search and the gallery's category filter,
	beside the forms published on GitHub under the topic buildiq-form. A form
	is used in an app through UseLibraryFormDialog, exported as a JSON file,
	and imported from one after the file is validated.
-->
<template>
	<div class="bq-form-library">
		<div class="bq-form-library__filters">
			<NcTextField
				:modelValue="query"
				:label="t('buildiq', 'Search forms')"
				data-testid="form-library-search"
				@update:modelValue="onQuery" />
			<NcSelect
				data-testid="form-category-filter"
				:modelValue="categoryOption"
				:inputLabel="t('buildiq', 'Filter by category')"
				:options="categoryOptions"
				:clearable="true"
				:placeholder="t('buildiq', 'All categories')"
				@update:modelValue="$emit('update:category', $event)" />
			<NcButton
				data-testid="form-library-import"
				@click="$refs.importInput.click()">
				{{ t('buildiq', 'Import a form') }}
			</NcButton>
			<input
				ref="importInput"
				class="bq-form-library__file"
				type="file"
				accept="application/json,.json"
				:aria-label="t('buildiq', 'Import a form')"
				@change="onImportFile" />
		</div>

		<p v-if="notice" class="bq-form-library__notice" role="status">
			{{ notice }}
		</p>
		<p v-if="error" class="bq-form-library__error" role="alert">
			{{ error }}
		</p>

		<section
			class="bq-form-library__section"
			aria-labelledby="bq-library-forms-heading">
			<h2 id="bq-library-forms-heading" class="bq-form-library__title">
				{{ t('buildiq', 'Library forms') }}
			</h2>
			<div v-if="loading" class="bq-form-library__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<NcEmptyContent
				v-else-if="visibleForms.length === 0"
				:name="t('buildiq', 'No forms match')"
				:description="
					t(
						'buildiq',
						'Save a form page or a registration form to the library, or import a form file.',
					)
				" />
			<ul v-else class="bq-form-library__grid">
				<li
					v-for="form in visibleForms"
					:key="form.slug"
					class="bq-form-library__card"
					data-testid="library-form-card">
					<h3 class="bq-form-library__name">
						{{ form.name }}
					</h3>
					<span class="bq-form-library__chip">{{
						categoryLabel(form.category)
					}}</span>
					<p class="bq-form-library__meta">
						{{
							t('buildiq', 'Published by {publisher}', {
								publisher: form.publisher || t('buildiq', 'unknown'),
							})
						}}
					</p>
					<p class="bq-form-library__description">
						{{ form.description || '' }}
					</p>
					<div class="bq-form-library__actions">
						<NcButton variant="primary" @click="openUse(form)">
							{{ t('buildiq', 'Use this form') }}
						</NcButton>
						<NcButton @click="exportForm(form)">
							{{ t('buildiq', 'Export') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<section
			class="bq-form-library__section"
			aria-labelledby="bq-github-forms-heading">
			<h2 id="bq-github-forms-heading" class="bq-form-library__title">
				{{ t('buildiq', 'Forms on GitHub') }}
			</h2>
			<div v-if="githubLoading" class="bq-form-library__loading">
				<NcLoadingIcon :size="32" />
			</div>
			<p v-else-if="githubOutcome !== 'ok'" class="bq-form-library__notice">
				{{
					t(
						'buildiq',
						'GitHub could not be reached right now. Try again shortly.',
					)
				}}
			</p>
			<p
				v-else-if="visibleGithubCards.length === 0"
				class="bq-form-library__notice">
				{{
					t(
						'buildiq',
						'No forms on GitHub match. Publish one in a repository with the topic buildiq-form and a form.json at its root.',
					)
				}}
			</p>
			<ul v-else class="bq-form-library__grid">
				<li
					v-for="card in visibleGithubCards"
					:key="card.owner + '/' + card.repo"
					class="bq-form-library__card"
					data-testid="github-form-card">
					<h3 class="bq-form-library__name">
						{{ card.name }}
					</h3>
					<span v-if="card.category" class="bq-form-library__chip">{{
						categoryLabel(card.category)
					}}</span>
					<p class="bq-form-library__meta">
						{{
							t('buildiq', 'Published by {publisher}', {
								publisher: card.publisher,
							})
						}}
						<a
							:href="card.htmlUrl"
							target="_blank"
							rel="noopener noreferrer"
							>{{ card.owner }}/{{ card.repo }}</a
						>
					</p>
					<p class="bq-form-library__description">
						{{ card.description || '' }}
					</p>
					<div class="bq-form-library__actions">
						<NcButton
							:disabled="!card.installable"
							@click="install(card)">
							{{ t('buildiq', 'Add to my library') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</section>

		<UseLibraryFormDialog
			v-if="useTarget"
			v-model:open="useOpen"
			:template="useTarget"
			@used="onUsed" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import UseLibraryFormDialog from '../../dialogs/UseLibraryFormDialog.vue'
import {
	downloadFormExport,
	FormImportError,
	parseFormImport,
} from '../../services/formExport.js'
import {
	createLibraryForm,
	fetchLibraryForms,
	filterLibraryForms,
} from '../../services/formLibrary.js'

export default {
	name: 'FormLibraryView',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		UseLibraryFormDialog,
	},

	props: {
		// The gallery's category options, `{id, label}`.
		categoryOptions: { type: Array, default: () => [] },
		// The selected category id, or null for every category.
		category: { type: String, default: null },
	},

	emits: ['update:category'],

	data() {
		return {
			forms: [],
			loading: false,
			query: '',
			githubCards: [],
			githubLoading: false,
			githubOutcome: 'ok',
			githubDebounce: null,
			useTarget: null,
			useOpen: false,
			notice: '',
			error: '',
		}
	},

	computed: {
		/**
		 * The selected category option, or null.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		categoryOption() {
			return (
				this.categoryOptions.find((option) => option.id === this.category)
				|| null
			)
		},

		/**
		 * The library forms after the search and the category filter.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		visibleForms() {
			return filterLibraryForms(this.forms, {
				query: this.query,
				category: this.category || '',
			})
		},

		/**
		 * The GitHub form cards after the category filter (the search runs on GitHub).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		visibleGithubCards() {
			return this.category
				? this.githubCards.filter(
						(card) => card && card.category === this.category,
					)
				: this.githubCards
		},
	},

	/**
	 * Read the library and search GitHub when the view opens.
	 *
	 * @return {void}
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
	 */
	created() {
		this.load()
		this.searchGithub()
	},

	/**
	 * Drop a pending GitHub search.
	 *
	 * @return {void}
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
	 */
	beforeUnmount() {
		if (this.githubDebounce) {
			clearTimeout(this.githubDebounce)
		}
	},

	methods: {
		/**
		 * Read the library forms.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		async load() {
			this.loading = true
			try {
				this.forms = await fetchLibraryForms()
			} catch {
				this.forms = []
				this.error = t('buildiq', 'The form library could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * The label of a category.
		 *
		 * @param {string} id The category id.
		 * @return {string}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		categoryLabel(id) {
			const option = this.categoryOptions.find((entry) => entry.id === id)
			return option ? option.label : t('buildiq', 'Other')
		},

		/**
		 * Search the library at once and GitHub after a pause.
		 *
		 * @param {string} value The search text.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-the-app-store-lists-library-forms-req-bqgl-003
		 */
		onQuery(value) {
			this.query = value
			if (this.githubDebounce) {
				clearTimeout(this.githubDebounce)
			}
			this.githubDebounce = setTimeout(() => this.searchGithub(), 400)
		},

		/**
		 * Search GitHub for forms under the topic buildiq-form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
		 */
		async searchGithub() {
			this.githubLoading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/buildiq/api/shop/github/forms'),
					{
						params: { q: this.query.trim() },
					},
				)
				this.githubCards = Array.isArray(data && data.cards)
					? data.cards
					: []
				this.githubOutcome = (data && data.outcome) || 'ok'
			} catch {
				this.githubCards = []
				this.githubOutcome = 'github_unreachable'
			} finally {
				this.githubLoading = false
			}
		},

		/**
		 * Open the use dialog for a form.
		 *
		 * @param {object} form The library form.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		openUse(form) {
			this.useTarget = form
			this.useOpen = true
		},

		/**
		 * Say where the form went.
		 *
		 * @param {{appSlug: string, added: Array<string>}} result What was written.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		onUsed(result) {
			this.notice = t('buildiq', 'The form was added to {app}.', {
				app: result.appSlug,
			})
		},

		/**
		 * Download a form as a JSON file.
		 *
		 * @param {object} form The library form.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
		 */
		exportForm(form) {
			downloadFormExport(form)
		},

		/**
		 * Import the picked file, after it is validated as a form export.
		 *
		 * @param {Event} event The change event of the file input.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
		 */
		async onImportFile(event) {
			const file =
				event && event.target && event.target.files && event.target.files[0]
			if (!file) {
				return
			}
			const text = await file.text()
			event.target.value = ''
			await this.importText(text)
		},

		/**
		 * Validate a form export and create the library form.
		 *
		 * @param {string|object} input The file's text or content.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
		 */
		async importText(input) {
			this.notice = ''
			this.error = ''
			let record
			try {
				record = parseFormImport(input)
			} catch (refusal) {
				this.error =
					refusal instanceof FormImportError
					&& refusal.code === 'invalid-form'
						? t(
								'buildiq',
								'This file is a form export, but the form in it is not complete.',
							)
						: t('buildiq', 'This file is not a form export.')
				return
			}
			if (this.forms.some((form) => form && form.slug === record.slug)) {
				this.error = t(
					'buildiq',
					'Your library already has a form with the slug {slug}.',
					{ slug: record.slug },
				)
				return
			}
			try {
				await createLibraryForm(record)
				this.notice = t('buildiq', '{name} is in your form library.', {
					name: record.name,
				})
				await this.load()
			} catch {
				this.error = t('buildiq', 'Adding the form to the library failed.')
			}
		},

		/**
		 * Install a GitHub form into the local library.
		 *
		 * @param {object} card The GitHub form card, carrying its export.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-forms-travel-between-organisations-req-bqgl-005
		 */
		async install(card) {
			await this.importText(card && card.export)
		},
	},
}
</script>

<style scoped>
.bq-form-library__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 16px;
}

.bq-form-library__file {
	display: none;
}

.bq-form-library__section {
	margin-bottom: 24px;
}

.bq-form-library__title {
	font-size: 1.2rem;
	margin: 0 0 12px;
}

.bq-form-library__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.bq-form-library__card {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.bq-form-library__name {
	margin: 0;
	font-size: 1rem;
}

.bq-form-library__chip {
	align-self: flex-start;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 0.85rem;
}

.bq-form-library__meta,
.bq-form-library__description,
.bq-form-library__notice {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.bq-form-library__actions {
	display: flex;
	gap: 8px;
	margin-top: auto;
}

.bq-form-library__error {
	color: var(--color-text-error, var(--color-error));
}

.bq-form-library__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}
</style>
