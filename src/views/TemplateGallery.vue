<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuilt', 'Template gallery') }}</h1>
			<p class="template-gallery__subtitle">
				{{ t('openbuilt', 'Start from a recognisable use case. Every template clones into an editable draft application.') }}
			</p>
		</header>

		<div class="template-gallery__filters">
			<NcTextField
				:value="search"
				:label="t('openbuilt', 'Search templates')"
				:placeholder="t('openbuilt', 'Search by name, use case, or description')"
				@update:value="search = $event" />
			<NcSelect
				v-model="categoryFilter"
				:input-label="t('openbuilt', 'Category')"
				:options="categoryOptions"
				:placeholder="t('openbuilt', 'All categories')"
				:clearable="true" />
		</div>

		<div v-if="loading" class="template-gallery__loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('openbuilt', 'Loading templates…') }}</span>
		</div>

		<div v-else-if="filteredTemplates.length === 0" class="template-gallery__empty">
			<NcEmptyContent :name="t('openbuilt', 'No templates match your filters')" />
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
						{{ t('openbuilt', 'Use this template') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<CloneTemplateDialog
			ref="cloneDialog"
			:open="cloneOpen"
			:template="cloneTarget"
			@close="cloneOpen = false"
			@submit="onCloneSubmit" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
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
		}
	},
	computed: {
		categoryOptions() {
			return Object.entries(CATEGORY_LABELS).map(([value, label]) => ({
				id: value,
				label: t('openbuilt', label),
			}))
		},
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
	},
	mounted() {
		this.fetchTemplates()
	},
	methods: {
		async fetchTemplates() {
			this.loading = true
			try {
				// Read templates directly from OpenRegister by register+schema slug.
				// Per hybrid register model: ApplicationTemplate lives in the shared `openbuilt` register.
				const url = generateUrl('/apps/openregister/api/objects/openbuilt/application-template')
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
		resolveScreenshot(url) {
			if (!url) {
				return ''
			}
			if (url.startsWith('http') || url.startsWith('/')) {
				return url
			}
			return generateUrl(`/apps/openbuilt/${url}`)
		},
		categoryLabel(category) {
			return t('openbuilt', CATEGORY_LABELS[category] || category || '')
		},
		openClone(template) {
			this.cloneTarget = template
			this.cloneOpen = true
		},
		async onCloneSubmit(payload) {
			const slug = this.cloneTarget?.slug
			if (!slug) {
				return
			}
			try {
				const url = generateUrl(`/apps/openbuilt/api/applications/from-template/${encodeURIComponent(slug)}`)
				const resp = await axios.post(url, payload)
				this.cloneOpen = false
				this.redirectAfterClone(resp.data)
			} catch (e) {
				const data = e?.response?.data
				const message = data?.detail || data?.error || e?.message || t('openbuilt', 'Clone failed.')
				this.$refs.cloneDialog?.setError(message)
			}
		},
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
