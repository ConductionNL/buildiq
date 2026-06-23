<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDetailActions — the actions bar on the VirtualAppDetail
  - (`type: detail`) page (`config.actionsComponent: "ApplicationDetailActions"`).
  - Surfaces a primary "Open app" button (the app's own manifest runtime), an
  - Export button, and a "··· Actions" overflow menu (Settings — incl.
  - publish/unpublish — Design pages, permissions, Save as template, Delete).
  - Reads/writes the Application via OR's REST API (ADR-022) + the dedicated
  - publish/delete endpoints, using the applicationContext mixin. Modals/dialogs
  - live in their own files per ADR-004 gate-modal-isolation.
  -->
<template>
	<div class="ob-detail-actions">
		<NcButton
			v-if="builderUrl"
			type="primary"
			:href="builderUrl">
			<template #icon>
				<OpenInNew :size="20" />
			</template>
			{{ t('openbuild', 'Open app') }}
		</NcButton>
		<NcButton
			:disabled="!obApp"
			@click="exportOpen = true">
			{{ t('openbuild', 'Export') }}
		</NcButton>

		<NcActions :menu-name="t('openbuild', 'Actions')" :force-menu="true">
			<NcActionButton v-if="obAppRole === 'owner'" :disabled="!obApp" @click="settingsOpen = true">
				<template #icon>
					<CogOutline :size="20" />
				</template>
				{{ t('openbuild', 'Settings') }}
			</NcActionButton>
			<NcActionButton v-if="obApp && obApp.slug" :to="{ name: 'PageDesigner', params: { slug: obApp.slug } }">
				<template #icon>
					<PencilRulerOutline :size="20" />
				</template>
				{{ t('openbuild', 'Design pages') }}
			</NcActionButton>
			<NcActionButton v-if="obAppRole === 'owner'" :disabled="!obApp" @click="permissionsOpen = true">
				<template #icon>
					<AccountMultipleOutline :size="20" />
				</template>
				{{ t('openbuild', 'Manage permissions') }}
			</NcActionButton>
			<NcActionButton v-if="obAppRole === 'owner'" :disabled="!obApp" @click="historyOpen = true">
				<template #icon>
					<History :size="20" />
				</template>
				{{ t('openbuild', 'Permission history') }}
			</NcActionButton>
			<NcActionButton v-if="canSaveAsTemplate" :disabled="!obApp || saveTemplateLoading" @click="openSaveAsTemplate">
				<template #icon>
					<ContentSaveOutline :size="20" />
				</template>
				{{ saveTemplateLoading ? t('openbuild', 'Preparing…') : t('openbuild', 'Save as template') }}
			</NcActionButton>
			<NcActionLink href="https://openbuild.conduction.nl" target="_blank" rel="noopener noreferrer">
				<template #icon>
					<HelpCircleOutline :size="20" />
				</template>
				{{ t('openbuild', 'Documentation') }}
			</NcActionLink>
			<NcActionButton v-if="obAppRole === 'owner'" :disabled="!obApp" @click="deleteOpen = true">
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
			:application-slug="obApp.slug"
			@close="exportOpen = false" />
		<AppSettingsModal
			:open="settingsOpen"
			:app-name="(obApp && (obApp.name || obApp.slug)) || ''"
			:is-published="(obApp && obApp.status) === 'published'"
			:allow-user-overrides="!!(obApp && obApp.allowUserOverrides)"
			:busy="publishing"
			@update:open="settingsOpen = $event"
			@set-published="setPublished"
			@update:allow-overrides="setAllowOverrides" />
		<DeleteAppDialog
			:open="deleteOpen"
			:app-name="(obApp && (obApp.name || obApp.slug)) || ''"
			:busy="deleting"
			@update:open="deleteOpen = $event"
			@confirm="deleteApp" />
		<PermissionsModal
			:open="permissionsOpen"
			:application="obApp"
			:available-groups="availableGroups"
			@update:open="permissionsOpen = $event"
			@save="onPermissionsSave" />
		<PermissionHistoryModal
			v-if="obApp"
			:open="historyOpen"
			:application-uuid="obAppUuid"
			@update:open="historyOpen = $event" />
		<SaveAsTemplateDialog
			v-if="saveTemplateOpen && obApp"
			:open="saveTemplateOpen"
			:application="obApp"
			:manifest="saveTemplateManifest"
			:schemas="saveTemplateSchemas"
			:existing-templates="existingTemplates"
			@update:open="saveTemplateOpen = $event"
			@saved="onTemplateSaved" />
	</div>
</template>

<script>
import { NcButton, NcActions, NcActionButton, NcActionLink } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import PencilRulerOutline from 'vue-material-design-icons/PencilRulerOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import ContentSaveOutline from 'vue-material-design-icons/ContentSaveOutline.vue'
import HelpCircleOutline from 'vue-material-design-icons/HelpCircleOutline.vue'
import PermissionsModal from '../modals/PermissionsModal.vue'
import PermissionHistoryModal from '../modals/PermissionHistoryModal.vue'
import AppSettingsModal from '../modals/AppSettingsModal.vue'
import DeleteAppDialog from '../dialogs/DeleteAppDialog.vue'
import SaveAsTemplateDialog from '../dialogs/SaveAsTemplateDialog.vue'
import { getCurrentUserGroups } from '../composables/useRole.js'
import { useRegisterPicker } from '../composables/useRegisterPicker.js'
import applicationContext from '../mixins/applicationContext.js'

const ExportDialog = () => import('../dialogs/ExportDialog.vue')

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
		PermissionsModal,
		PermissionHistoryModal,
		AppSettingsModal,
		DeleteAppDialog,
		SaveAsTemplateDialog,
		ExportDialog,
	},
	mixins: [applicationContext],
	data() {
		return {
			publishing: false,
			deleting: false,
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
	methods: {
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
				await axios.post(generateUrl(`/apps/openbuild/api/applications/${this.obAppUuid}/${action}`), {})
				await this.obLoadApp()
				this.toast = shouldPublish
					? t('openbuild', 'App published — it now appears in the app menu.')
					: t('openbuild', 'App unpublished — removed from the app menu.')
			} catch (e) {
				const detail = (e.response && e.response.data && e.response.data.detail) || e.message || e
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
		 * Delete the app (Application + versions + per-version registers), then
		 * navigate back to the apps list. Owner-only (enforced server-side too).
		 *
		 * @return {Promise<void>}
		 */
		async deleteApp() {
			if (this.obAppRole !== 'owner' || !this.obApp || this.deleting) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				await axios.delete(generateUrl(`/apps/openbuild/api/applications/${this.obAppUuid}`))
				this.deleteOpen = false
				if (this.$router) {
					this.$router.push({ name: 'VirtualApps' }).catch(() => {})
				} else {
					window.location.href = generateUrl('/apps/openbuild/applications')
				}
			} catch (e) {
				const detail = (e.response && e.response.data && e.response.data.detail) || e.message || e
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
				this.saveTemplateManifest = this.obApp.manifest
					|| (this.obApp.currentVersion && this.obApp.currentVersion.manifest)
					|| {}
				const picker = useRegisterPicker({ appSlug: this.obApp.slug })
				this.saveTemplateSchemas = await picker.fetchSchemas(picker.resolveAppRegister())
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
				return Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
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
			this.toast = payload && payload.mode === 'update'
				? t('openbuild', 'Template "{slug}" updated', { slug: payload.slug })
				: t('openbuild', 'Saved as template "{slug}"', { slug: payload.slug })
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

.ob-detail-actions__toast {
	font-size: 13px;
	color: var(--color-success-text, #2d8a3e);
}

.ob-detail-actions__error {
	font-size: 13px;
	color: var(--color-error, #d63f3f);
}
</style>
