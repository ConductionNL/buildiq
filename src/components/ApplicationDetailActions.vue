<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDetailActions — the actions bar on the VirtualAppDetail
  - (`type: detail`) page (`config.actionsComponent: "ApplicationDetailActions"`).
  - Surfaces a primary "Open app" button (the app's own manifest runtime), an
  - Export button, and a "··· Actions" overflow menu (Settings — incl.
  - publish/unpublish — GitHub, permissions, Save as template, Delete).
  - Page/walkthrough design happens inside the running app via the in-app
  - OpenBuild edit menu (CnOpenBuildEditButton, ADR-041), not from here.
  - Reads/writes the Application via OR's REST API (ADR-022) + the dedicated
  - publish/delete endpoints, using the applicationContext mixin. Modals/dialogs
  - live in their own files per ADR-004 gate-modal-isolation.
  -->
<template>
	<div class="ob-detail-actions">
		<!-- Split button: primary opens PRODUCTION; chevron lists versions to
		     view/use (and edit, editor+). Production is always the canonical URL. -->
		<div v-if="builderUrl" class="ob-detail-actions__open">
			<NcButton
				type="primary"
				:href="builderUrl"
				target="_blank"
				class="ob-detail-actions__open-primary">
				<template #icon>
					<OpenInNew :size="20" />
				</template>
				{{ t('openbuild', 'Open app') }}
			</NcButton>
			<NcActions
				v-if="openableVersions.length"
				:menuName="t('openbuild', 'Open a version')"
				:forceMenu="true"
				class="ob-detail-actions__open-chevron">
				<!-- Vue 3 requires the v-for key on the <template> itself, not on
				     its children (Vue 2 allowed the per-child form used before). -->
				<template v-for="v in openableVersions" :key="v.slug">
					<NcActionButton @click="openVersion(v)">
						<template #icon>
							<OpenInNew :size="20" />
						</template>
						{{ versionLabel(v) }}
					</NcActionButton>
					<NcActionButton
						v-if="canEditVersions"
						class="ob-detail-actions__open-edit"
						@click="editVersion(v)">
						<template #icon>
							<PencilRulerOutline :size="20" />
						</template>
						{{
							t('openbuild', 'Edit {name}', { name: versionLabel(v) })
						}}
					</NcActionButton>
				</template>
			</NcActions>
		</div>
		<NcButton :disabled="!obApp" @click="exportOpen = true">
			{{ t('openbuild', 'Export') }}
		</NcButton>

		<NcActions :menuName="t('openbuild', 'Actions')" :forceMenu="true">
			<NcActionButton
				v-if="obAppRole === 'owner'"
				:disabled="!obApp"
				@click="settingsOpen = true">
				<template #icon>
					<CogOutline :size="20" />
				</template>
				{{ t('openbuild', 'Settings') }}
			</NcActionButton>
			<NcActionButton
				v-if="obApp && obApp.slug"
				:disabled="!obApp"
				@click="githubOpen = true">
				<template #icon>
					<Github :size="20" />
				</template>
				{{ t('openbuild', 'GitHub') }}
			</NcActionButton>
			<NcActionButton
				v-if="obAppRole === 'owner'"
				:disabled="!obApp"
				@click="permissionsOpen = true">
				<template #icon>
					<AccountMultipleOutline :size="20" />
				</template>
				{{ t('openbuild', 'Manage permissions') }}
			</NcActionButton>
			<NcActionButton
				v-if="obAppRole === 'owner'"
				:disabled="!obApp"
				@click="historyOpen = true">
				<template #icon>
					<History :size="20" />
				</template>
				{{ t('openbuild', 'Permission history') }}
			</NcActionButton>
			<NcActionButton
				v-if="canSaveAsTemplate"
				:disabled="!obApp || saveTemplateLoading"
				@click="openSaveAsTemplate">
				<template #icon>
					<ContentSaveOutline :size="20" />
				</template>
				{{
					saveTemplateLoading
						? t('openbuild', 'Preparing…')
						: t('openbuild', 'Save as template')
				}}
			</NcActionButton>
			<NcActionLink
				href="https://openbuild.conduction.nl"
				target="_blank"
				rel="noopener noreferrer">
				<template #icon>
					<HelpCircleOutline :size="20" />
				</template>
				{{ t('openbuild', 'Documentation') }}
			</NcActionLink>
			<NcActionButton
				v-if="obAppRole === 'owner'"
				:disabled="!obApp"
				@click="deleteOpen = true">
				<template #icon>
					<DeleteOutline :size="20" />
				</template>
				{{ t('openbuild', 'Delete') }}
			</NcActionButton>
		</NcActions>

		<span v-if="toast" class="ob-detail-actions__toast">{{ toast }}</span>
		<span v-if="error" class="ob-detail-actions__error">{{ error }}</span>

		<ExportDialog
			v-if="exportOpen && obApp"
			:applicationSlug="obApp.slug"
			:data-registers="obApp.dataRegisters || []"
			@close="exportOpen = false" />
		<GitHubSyncModal
			v-if="obApp && obApp.slug"
			:open="githubOpen"
			:slug="obApp.slug"
			:isOwner="obAppRole === 'owner'"
			@update:open="githubOpen = $event" />
		<AppSettingsModal
			:open="settingsOpen"
			:appName="(obApp && (obApp.name || obApp.slug)) || ''"
			:isPublished="(obApp && obApp.status) === 'published'"
			:allowUserOverrides="!!(obApp && obApp.allowUserOverrides)"
			:data-registers="(obApp && obApp.dataRegisters) || []"
			:busy="publishing"
			@update:open="settingsOpen = $event"
			@setPublished="setPublished"
			@update:allowOverrides="setAllowOverrides"
			@update:dataRegisters="setDataRegisters" />
		<DeleteAppDialog
			:open="deleteOpen"
			:appName="(obApp && (obApp.name || obApp.slug)) || ''"
			:busy="deleting"
			@update:open="deleteOpen = $event"
			@confirm="deleteApp" />
		<PermissionsModal
			:open="permissionsOpen"
			:application="obApp"
			:availableGroups="availableGroups"
			@update:open="permissionsOpen = $event"
			@save="onPermissionsSave" />
		<PermissionHistoryModal
			v-if="obApp"
			:open="historyOpen"
			:applicationUuid="obAppUuid"
			@update:open="historyOpen = $event" />
		<SaveAsTemplateDialog
			v-if="saveTemplateOpen && obApp"
			:open="saveTemplateOpen"
			:application="obApp"
			:manifest="saveTemplateManifest"
			:schemas="saveTemplateSchemas"
			:existingTemplates="existingTemplates"
			@update:open="saveTemplateOpen = $event"
			@saved="onTemplateSaved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActionLink, NcActions, NcButton } from '@nextcloud/vue'
import { defineAsyncComponent } from 'vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import Github from 'vue-material-design-icons/Github.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import PencilRulerOutline from 'vue-material-design-icons/PencilRulerOutline.vue'
import DeleteAppDialog from '../dialogs/DeleteAppDialog.vue'
import SaveAsTemplateDialog from '../dialogs/SaveAsTemplateDialog.vue'
import AppSettingsModal from '../modals/AppSettingsModal.vue'
import GitHubSyncModal from '../modals/GitHubSyncModal.vue'
import PermissionHistoryModal from '../modals/PermissionHistoryModal.vue'
import PermissionsModal from '../modals/PermissionsModal.vue'
import { useRegisterPicker } from '../composables/useRegisterPicker.js'
import { getCurrentUserGroups } from '../composables/useRole.js'
import applicationContext from '../mixins/applicationContext.js'

// Vue 3 requires `defineAsyncComponent()` around a lazy import. The bare
// `() => import(…)` form is Vue 2 syntax: Vue 3 accepts a plain function as a
// FUNCTIONAL component, so this was registered as a component whose render
// function returns a Promise — it rendered nothing, with no error and no
// warning. The Export button set `exportOpen = true`, the `v-if` passed, and
// still no dialog ever appeared: Export was dead for every user of the app
// detail page. This is the only such import left in src/; every sibling dialog
// here is imported eagerly.
const ExportDialog = defineAsyncComponent(
	() => import('../dialogs/ExportDialog.vue'),
)

const OR_TEMPLATES = '/apps/openregister/api/objects/openbuild/application-template'

export default {
	name: 'ApplicationDetailActions',
	components: {
		NcButton,
		NcActions,
		NcActionButton,
		NcActionLink,
		OpenInNew,
		CogOutline,
		DeleteOutline,
		PencilRulerOutline,
		AccountMultipleOutline,
		History,
		ContentSaveOutline,
		HelpCircleOutline,
		Github,
		PermissionsModal,
		PermissionHistoryModal,
		AppSettingsModal,
		GitHubSyncModal,
		DeleteAppDialog,
		SaveAsTemplateDialog,
		ExportDialog,
	},

	mixins: [applicationContext],
	data() {
		return {
			versions: [],
			publishing: false,
			deleting: false,
			githubOpen: false,
			settingsOpen: false,
			deleteOpen: false,
			permissionsOpen: false,
			historyOpen: false,
			exportOpen: false,
			saveTemplateOpen: false,
			saveTemplateLoading: false,
			saveTemplateManifest: null,
			saveTemplateSchemas: [],
			existingTemplates: [],
			toast: '',
			error: '',
		}
	},

	computed: {
		/**
		 * URL of the app's own manifest runtime (the nested CnAppRoot host at
		 * /builder/{slug}). Shown as the primary "Open app" action whenever the
		 * app slug is known. Top-level URL — the runtime is a sibling route.
		 *
		 * @return {string}
		 */
		builderUrl() {
			if (!this.obApp || !this.obApp.slug) {
				return ''
			}
			return generateUrl(`/apps/openbuild/builder/${this.obApp.slug}`)
		},

		/**
		 * "Save as template" is offered to owners and editors only — same
		 * rbac source of truth as the edit actions (REQ-SAT-001).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		canSaveAsTemplate() {
			return this.obAppRole === 'owner' || this.obAppRole === 'editor'
		},

		/**
		 * Whether the caller may edit versions (owner / editor) — gates the
		 * per-version Edit entries in the Open-a-version chevron.
		 *
		 * @return {boolean}
		 */
		canEditVersions() {
			return this.obAppRole === 'owner' || this.obAppRole === 'editor'
		},

		/**
		 * The current production version UUID (handles string + inline-object).
		 *
		 * @return {string}
		 */
		productionUuid() {
			const pv = this.obApp && this.obApp.productionVersion
			if (!pv) {
				return ''
			}
			return typeof pv === 'string' ? pv : pv.uuid || pv.id || ''
		},

		/**
		 * Versions offered in the Open-a-version chevron — non-archived, with the
		 * production version first (decision 4: archived hidden by default).
		 *
		 * @return {Array<object>}
		 */
		openableVersions() {
			return this.versions
				.filter((v) => (v.status || 'draft') !== 'archived')
				.slice()
				.sort(
					(a, b) =>
						(this.isProductionVersion(b) ? 1 : 0)
						- (this.isProductionVersion(a) ? 1 : 0),
				)
		},

		/**
		 * Group ids selectable in the permissions modal (current user's groups
		 * unioned with any already-referenced principals).
		 *
		 * @return {Array<string>}
		 */
		availableGroups() {
			const perms = (this.obApp && this.obApp.permissions) || {}
			const gids = new Set(getCurrentUserGroups())
			;['owners', 'editors', 'viewers'].forEach((b) => {
				if (Array.isArray(perms[b])) {
					perms[b].forEach((g) => gids.add(g))
				}
			})
			return Array.from(gids)
		},
	},

	watch: {
		'obApp.slug': {
			immediate: true,
			/**
			 * Load the app's versions for the Open-a-version chevron once the
			 * slug resolves.
			 *
			 * @param {string} slug The app slug.
			 * @return {void}
			 */
			handler(slug) {
				if (slug) {
					this.loadVersions()
				}
			},
		},
	},

	methods: {
		/**
		 * Load the app's ApplicationVersion rows for the Open-a-version chevron.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		async loadVersions() {
			if (!this.obApp || !this.obApp.slug) {
				this.versions = []
				return
			}
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/versions',
					{ slug: this.obApp.slug },
				)
				const { data } = await axios.get(url)
				this.versions = Array.isArray(data)
					? data
					: data && data.results
						? data.results
						: []
			} catch (e) {
				this.versions = []
			}
		},

		/**
		 * The own UUID of a version row (`id` or the `@self` envelope).
		 *
		 * @param {object} v The version row.
		 * @return {string}
		 */
		versionRowUuid(v) {
			const self = (v && v['@self']) || {}
			return (v && v.id) || self.id || self.uuid || (v && v.uuid) || ''
		},

		/**
		 * Whether a version row is the current production version.
		 *
		 * @param {object} v The version row.
		 * @return {boolean}
		 */
		isProductionVersion(v) {
			return (
				!!this.productionUuid
				&& this.versionRowUuid(v) === this.productionUuid
			)
		},

		/**
		 * Human label for a version in the chevron (name + semver + marker).
		 *
		 * @param {object} v The version row.
		 * @return {string}
		 */
		versionLabel(v) {
			const name = (v && (v.name || v.slug)) || ''
			const semver = v && v.semver ? ` (${v.semver})` : ''
			const prod = this.isProductionVersion(v)
				? ` — ${t('openbuild', 'Production')}`
				: ''
			return `${name}${semver}${prod}`
		},

		/**
		 * Open a version in the live shell — production at the canonical URL,
		 * any other via `?_version=` (RBAC-gated server-side).
		 *
		 * @param {object} v The version row.
		 * @return {void}
		 */
		openVersion(v) {
			if (!this.obApp || !this.obApp.slug) {
				return
			}
			const base = generateUrl(`/apps/openbuild/builder/${this.obApp.slug}`)
			const url = this.isProductionVersion(v)
				? base
				: `${base}?_version=${encodeURIComponent(v.slug)}`
			// Open in a new tab to match the open-in-new affordance (OpenInNew icon).
			window.open(url, '_blank', 'noopener,noreferrer')
		},

		/**
		 * Edit a version in the page designer, scoped via `?_version=` for
		 * non-production versions.
		 *
		 * @param {object} v The version row.
		 * @return {void}
		 */
		editVersion(v) {
			if (!this.obApp || !this.obApp.slug) {
				return
			}
			const base = generateUrl(
				`/apps/openbuild/builder/${this.obApp.slug}/pages`,
			)
			window.location.href = this.isProductionVersion(v)
				? base
				: `${base}?_version=${encodeURIComponent(v.slug)}`
		},

		/**
		 * Publish or unpublish the app (owner-only, enforced again server-side).
		 * Publishing makes it appear in the Nextcloud app menu.
		 *
		 * @param {boolean} shouldPublish True to publish, false to unpublish.
		 * @return {Promise<void>}
		 */
		async setPublished(shouldPublish) {
			if (this.obAppRole !== 'owner' || !this.obApp || this.publishing) {
				return
			}
			this.publishing = true
			this.toast = ''
			this.error = ''
			try {
				const action = shouldPublish ? 'publish' : 'unpublish'
				await axios.post(
					generateUrl(
						`/apps/openbuild/api/applications/${this.obAppUuid}/${action}`,
					),
					{},
				)
				// Force a refetch — the `object` prop snapshot still carries the
				// pre-publish status, so the default load path would keep the
				// toggle/badge stale until a full page reload.
				await this.obLoadApp(true)
				this.toast = shouldPublish
					? t(
							'openbuild',
							'App published — it now appears in the app menu.',
						)
					: t('openbuild', 'App unpublished — removed from the app menu.')
			} catch (e) {
				const detail =
					(e.response && e.response.data && e.response.data.detail)
					|| e.message
					|| e
				this.error = shouldPublish
					? `${t('openbuild', 'Publish failed')}: ${detail}`
					: `${t('openbuild', 'Unpublish failed')}: ${detail}`
			} finally {
				this.publishing = false
			}
		},

		/**
		 * Toggle per-user manifest overrides on the app.
		 *
		 * @param {boolean} allow Whether to allow per-user overrides.
		 * @return {Promise<void>}
		 */
		async setAllowOverrides(allow) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ allowUserOverrides: allow })
			} catch (e) {
				this.error = `${t('openbuild', 'Failed to save settings')}: ${e.message || e}`
			}
		},

		/**
		 * Persist an add/remove/edit of the app's `dataRegisters` bindings
		 * from the settings modal — same shape as `setAllowOverrides()`
		 * (data-registers-runtime task 5.2).
		 *
		 * @param {Array<{register: string, label?: string}>} dataRegisters The full updated bindings array.
		 * @return {Promise<void>}
		 */
		async setDataRegisters(dataRegisters) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ dataRegisters })
			} catch (e) {
				this.error = `${t('openbuild', 'Failed to save settings')}: ${e.message || e}`
			}
		},

		/**
		 * Delete the app (Application + versions + per-version registers + routes),
		 * then navigate back to the apps list. Owner-only (enforced server-side
		 * too). When `deleteData` is true the underlying registers and all their
		 * data are wiped too; otherwise that data is preserved.
		 *
		 * @param {boolean} deleteData Whether to also delete all app data.
		 * @return {Promise<void>}
		 */
		async deleteApp(deleteData = false) {
			if (this.obAppRole !== 'owner' || !this.obApp || this.deleting) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				await axios.delete(
					generateUrl(
						`/apps/openbuild/api/applications/${this.obAppUuid}`,
					),
					{
						params: { deleteData: deleteData ? 1 : 0 },
					},
				)
				this.deleteOpen = false
				if (this.$router) {
					this.$router.push({ name: 'VirtualApps' }).catch(() => {})
				} else {
					window.location.href = generateUrl(
						'/apps/openbuild/applications',
					)
				}
			} catch (e) {
				const detail =
					(e.response && e.response.data && e.response.data.detail)
					|| e.message
					|| e
				this.error = `${t('openbuild', 'Delete failed')}: ${detail}`
			} finally {
				this.deleting = false
			}
		},

		/**
		 * Persist edited permissions from the permissions modal.
		 *
		 * @param {object} permissions The new permissions block.
		 * @return {Promise<void>}
		 */
		async onPermissionsSave(permissions) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ permissions })
				this.permissionsOpen = false
			} catch (e) {
				this.error = `${t('openbuild', 'Failed to save permissions')}: ${e.message || e}`
			}
		},

		/**
		 * Gather the app's manifest + companion schemas + visible templates, then
		 * open the SaveAsTemplateDialog (REQ-SAT-001).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		async openSaveAsTemplate() {
			if (!this.canSaveAsTemplate || !this.obApp || this.saveTemplateLoading) {
				return
			}
			this.saveTemplateLoading = true
			this.error = ''
			try {
				// Resolve the manifest from the app's ACTIVE VERSION, through the
				// endpoint that owns that resolution.
				//
				// This used to read `obApp.manifest`, falling back to
				// `obApp.currentVersion.manifest`. An Application record carries
				// NEITHER: the manifest lives on the ApplicationVersion, and
				// `GET /api/applications` returns no `manifest` and no
				// `currentVersion` for any app — the seeded hello-world included.
				// So the capture always fell through to `{}`, the dialog validated
				// an empty object and opened with "The captured manifest is invalid
				// and cannot be published: /version must be a string /menu must be
				// an array /pages must be an array", with Save permanently
				// disabled. Saving an app as a template was impossible for EVERY
				// application.
				const manifestUrl = generateUrl(
					`/apps/openbuild/api/applications/${encodeURIComponent(this.obApp.slug)}/manifest`,
				)
				const { data: resolvedManifest } = await axios.get(manifestUrl)
				this.saveTemplateManifest =
					resolvedManifest
					|| this.obApp.manifest
					|| (this.obApp.currentVersion
						&& this.obApp.currentVersion.manifest)
					|| {}
				const picker = useRegisterPicker({
					appSlug: this.obApp.slug,
					dataRegisters: this.obApp.dataRegisters || [],
				})
				this.saveTemplateSchemas = await picker.fetchSchemas(
					picker.resolveAppRegister(),
				)
				this.existingTemplates = await this.loadExistingTemplates()
				this.saveTemplateOpen = true
			} catch (e) {
				this.error = `${t('openbuild', 'Could not prepare template capture')}: ${e.message || e}`
			} finally {
				this.saveTemplateLoading = false
			}
		},

		/**
		 * Read the templates visible to the caller (for slug-collision
		 * resolution). Plain OR REST read — no new PHP (REQ-SAT-006).
		 *
		 * @return {Promise<Array>}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		async loadExistingTemplates() {
			try {
				const { data } = await axios.get(generateUrl(OR_TEMPLATES))
				return Array.isArray(data?.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (e) {
				return []
			}
		},

		/**
		 * Surface a toast after a successful template save/update.
		 *
		 * @param {{slug: string, mode: string}} payload The save result.
		 * @return {void}
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		onTemplateSaved(payload) {
			this.saveTemplateOpen = false
			this.toast =
				payload && payload.mode === 'update'
					? t('openbuild', 'Template "{slug}" updated', {
							slug: payload.slug,
						})
					: t('openbuild', 'Saved as template "{slug}"', {
							slug: payload.slug,
						})
		},
	},
}
</script>

<style scoped>
.ob-detail-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.ob-detail-actions__open {
	display: inline-flex;
	align-items: center;
	gap: 2px;
}

.ob-detail-actions__toast {
	font-size: 13px;
	color: var(--color-success-text, #2d8a3e);
}

.ob-detail-actions__error {
	font-size: 13px;
	color: var(--color-error, #d63f3f);
}
</style>
