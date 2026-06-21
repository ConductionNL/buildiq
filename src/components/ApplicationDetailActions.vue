<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDetailActions — the actions bar on the VirtualAppDetail
  - (`type: detail`) page (`config.actionsComponent: "ApplicationDetailActions"`).
  - Owner/editor-gated Publish (OR lifecycle transition → ObjectTransitionedEvent
  - → version snapshot + BuiltAppRoute), Manage permissions (PermissionsModal —
  - kept in this component per ADR-004 gate-modal-isolation), Design pages
  - (router-link to PageDesigner), and Open virtual app. Reads/writes the
  - Application via OR's REST API (ADR-022) using the applicationContext mixin.
  -->
<template>
	<div class="ob-detail-actions">
		<NcButton
			v-if="obAppRole === 'owner'"
			type="primary"
			:disabled="!canPublish || publishing"
			@click="publish">
			{{ publishing ? t('openbuild', 'Publishing…') : t('openbuild', 'Publish') }}
		</NcButton>
		<NcButton
			v-if="obAppRole === 'owner'"
			:disabled="!obApp"
			@click="permissionsOpen = true">
			{{ t('openbuild', 'Manage permissions') }}
		</NcButton>
		<NcButton
			v-if="obAppRole === 'owner'"
			:disabled="!obApp"
			@click="historyOpen = true">
			{{ t('openbuild', 'Permission history') }}
		</NcButton>
		<NcButton v-if="obApp && obApp.slug" :to="{ name: 'PageDesigner', params: { slug: obApp.slug } }">
			{{ t('openbuild', 'Design pages') }}
		</NcButton>
		<NcButton v-if="builderUrl" :href="builderUrl">
			{{ t('openbuild', 'Open app') }}
		</NcButton>
		<NcButton
			:disabled="!obApp || !obApp.productionVersion"
			@click="exportOpen = true">
			{{ t('openbuild', 'Export') }}
		</NcButton>
		<NcButton
			v-if="canSaveAsTemplate"
			:disabled="!obApp || saveTemplateLoading"
			@click="openSaveAsTemplate">
			{{ saveTemplateLoading ? t('openbuild', 'Preparing…') : t('openbuild', 'Save as template') }}
		</NcButton>
		<span v-if="toast" class="ob-detail-actions__toast">{{ toast }}</span>
		<span v-if="error" class="ob-detail-actions__error">{{ error }}</span>
		<ExportDialog
			v-if="exportOpen && obApp"
			:application-slug="obApp.slug"
			@close="exportOpen = false" />
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
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import PermissionsModal from '../modals/PermissionsModal.vue'
import PermissionHistoryModal from '../modals/PermissionHistoryModal.vue'
import SaveAsTemplateDialog from '../dialogs/SaveAsTemplateDialog.vue'
import { getCurrentUserGroups } from '../composables/useRole.js'
import { useRegisterPicker } from '../composables/useRegisterPicker.js'
import applicationContext from '../mixins/applicationContext.js'

const ExportDialog = () => import('../dialogs/ExportDialog.vue')

const OR_TEMPLATES = '/apps/openregister/api/objects/openbuild/application-template'

export default {
	name: 'ApplicationDetailActions',
	components: { NcButton, PermissionsModal, PermissionHistoryModal, SaveAsTemplateDialog, ExportDialog },
	mixins: [applicationContext],
	data() {
		return {
			publishing: false,
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
		 * Observed behaviour of `canPublish` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		canPublish() {
			return !!this.obApp && (this.obApp.status === 'draft' || this.obApp.status === 'published')
		},
		/**
		 * Observed behaviour of `builderUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		builderUrl() {
			if (!this.obApp || !(this.obApp.currentVersion || this.obApp.status === 'published')) {
				return ''
			}
			return generateUrl(`/apps/openbuild/builder/${this.obApp.slug}`)
		},
		/**
		 * "Save as template" is offered to owners and editors only — same
		 * rbac source of truth as the edit actions (REQ-SAT-001). Viewers
		 * (and 'none') never see it.
		 *
		 * @spec openspec/changes/save-as-template/specs/save-as-template/spec.md
		 */
		canSaveAsTemplate() {
			return this.obAppRole === 'owner' || this.obAppRole === 'editor'
		},
		/**
		 * Observed behaviour of `availableGroups` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
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
		 * Observed behaviour of `publish` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		async publish() {
			if (this.obAppRole !== 'owner' || !this.obApp || this.publishing) {
				return
			}
			this.publishing = true
			this.toast = ''
			this.error = ''
			try {
				// OR's lifecycle transition endpoint — fires ObjectTransitionedEvent,
				// which ApplicationVersionSnapshotListener consumes to snapshot the
				// manifest into ApplicationVersion and bump currentVersion + create
				// the BuiltAppRoute.
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${this.obAppUuid}/transition/publish`)
				const { data } = await axios.post(url, {})
				await this.obLoadApp()
				const v = (data && (data.currentVersion || data.uuid)) || (this.obApp && this.obApp.currentVersion) || ''
				this.toast = t('openbuild', 'Published version {uuid}', { uuid: v ? String(v).slice(0, 8) + '…' : '' })
			} catch (e) {
				this.error = `${t('openbuild', 'Publish failed')}: ${e.message || e}`
			} finally {
				this.publishing = false
			}
		},
		/**
		 * Observed behaviour of `onPermissionsSave` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
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
		 * Gather the app's manifest + companion schemas + visible templates,
		 * then open the SaveAsTemplateDialog (REQ-SAT-001).
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
				// Manifest from the app's current/production version (falls back
				// to an inline manifest on the record for un-versioned drafts).
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
				const list = Array.isArray(data?.results) ? data.results : (Array.isArray(data) ? data : [])
				return list
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
