<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="template-gallery">
		<header class="template-gallery__header">
			<h1>{{ t('openbuild', 'Template gallery') }}</h1>
			<p class="template-gallery__subtitle">
				{{ t('openbuild', 'Start from a recognisable use case. Every template clones into an editable draft application.') }}
			</p>
		</header>

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

		<div v-if="loading" class="template-gallery__loading">
			<NcLoadingIcon :size="32" />
			<span>{{ t('openbuild', 'Loading templates…') }}</span>
		</div>

		<div v-else-if="filteredTemplates.length === 0" class="template-gallery__empty">
			<NcEmptyContent :name="t('openbuild', 'No templates match your filters')" />
		</div>

		<ul v-else class="template-gallery__grid" data-walkthrough-id="templates-grid">
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
					<span v-if="isOrgLocal(tpl)" class="template-card__badge">{{ t('openbuild', 'Organisation template') }}</span>
					<span class="template-card__category">{{ categoryLabel(tpl.category) }}</span>
					<p class="template-card__usecase">
						{{ tpl.useCase || '' }}
					</p>
					<p class="template-card__description">
						{{ tpl.description || '' }}
					</p>
				</div>
				<div class="template-card__actions">
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
				</div>
			</li>
		</ul>

		<CloneTemplateDialog
			ref="cloneDialog"
			:open="cloneOpen"
			:template="cloneTarget"
			@close="cloneOpen = false"
			@submit="onCloneSubmit" />

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
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
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
			editOpen: false,
			editTarget: null,
			deleteOpen: false,
			deleteTarget: null,
			deleting: false,
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
	},
	mounted() {
		this.fetchTemplates()
	},
	methods: {
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
			if (url.startsWith('http') || url.startsWith('/')) {
				return url
			}
			return generateUrl(`/apps/openbuild/${url}`)
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
