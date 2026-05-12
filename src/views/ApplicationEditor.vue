<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Textarea-based manifest editor — v1 of the OpenBuilt authoring UI.
  - Visual editors land in chain spec #5 (openbuilt-page-editor).
  - Per design.md the editor is integrator-only; the in-app help string
  - openbuilt.editor.help documents that.
  -
  - Per ADR-022 the editor reads/writes Application objects via OR's
  - REST API directly — no app-local CRUD wrapper. Per the openbuilt-rbac
  - change the list filters out Applications on which the caller has no
  - role (REQ-OBR-007) and destructive actions gate via `useRole(app)`
  - (REQ-OBR-008 / REQ-OBRBAC-004). The Permissions modal lives in
  - `src/modals/PermissionsModal.vue` per ADR-004 `gate-modal-isolation`.
  -
  - Chain spec #6 (openbuilt-versioning) adds the Publish action,
  - status + "draft modified" badges, version-history sibling panel,
  - and manifest-diff component. Each lives in its own SFC per Hydra
  - modal-isolation + composition rules.
  -->
<template>
	<div class="openbuilt-editor">
		<NcAppContent>
			<div class="openbuilt-editor__layout">
				<aside class="openbuilt-editor__list">
					<h3>{{ t('openbuilt', 'Virtual apps') }}</h3>
					<ul>
						<li
							v-for="app in visibleApplications"
							:key="app.uuid || app.id"
							:class="{ active: appUuid(app) === selectedUuid }"
							@click="select(app)">
							{{ app.name || app.slug }}
							<span class="openbuilt-editor__badge" :class="badgeClass(app.status)">{{ statusLabel(app.status) }}</span>
							<small>{{ translatedRole(roleFor(app)) }}</small>
						</li>
					</ul>
					<p v-if="visibleApplications.length === 0" class="openbuilt-editor__empty">
						{{ t('openbuilt', 'No applications available — ask an owner to grant you access.') }}
					</p>
				</aside>
				<section class="openbuilt-editor__pane">
					<header v-if="selected" class="openbuilt-editor__pane-header">
						<h2>{{ selected.name || selected.slug }}</h2>
						<div class="openbuilt-editor__meta">
							<span class="openbuilt-editor__badge" :class="badgeClass(selected.status)">
								{{ statusLabel(selected.status) }}
							</span>
							<small v-if="isDraftModified" class="openbuilt-editor__modified">
								{{ t('openbuilt', 'modified since last publish') }}
							</small>
							<small>{{ t('openbuilt', 'Version') }}: {{ selected.version }}</small>
							<small>{{ t('openbuilt', 'Role') }}: {{ translatedRole(selectedRole) }}</small>
						</div>
						<nav class="openbuilt-editor__tabs">
							<button :class="{ active: activeTab === 'editor' }" @click="activeTab = 'editor'">
								{{ t('openbuilt', 'Editor') }}
							</button>
							<button :class="{ active: activeTab === 'history' }" @click="activeTab = 'history'">
								{{ t('openbuilt', 'Version history') }}
							</button>
							<button :class="{ active: activeTab === 'diff' }" @click="activeTab = 'diff'">
								{{ t('openbuilt', 'Diff') }}
							</button>
						</nav>
					</header>

					<div v-if="!selected" />

					<div v-else-if="activeTab === 'editor'" class="openbuilt-editor__editor-tab">
						<p class="openbuilt-editor__help">
							{{ t('openbuilt', 'Integrator-only editor: edit the raw JSON manifest below. The visual editor lives in a follow-on release (openbuilt-page-editor).') }}
						</p>
						<textarea
							v-model="manifestText"
							class="openbuilt-editor__textarea"
							data-testid="openbuilt-editor-textarea"
							spellcheck="false"
							:readonly="selectedRole === 'viewer'"
							:placeholder="t('openbuilt', 'Paste or edit the JSON manifest here. See @conduction/nextcloud-vue/src/schemas/app-manifest.schema.json for the canonical schema.')" />
						<div v-if="validationError" class="openbuilt-editor__error">
							{{ t('openbuilt', 'Invalid manifest') }}: {{ validationError }}
						</div>
						<div class="openbuilt-editor__actions">
							<button
								v-if="selectedRole === 'editor' || selectedRole === 'owner'"
								:disabled="!selected || saving"
								data-testid="openbuilt-editor-save"
								@click="save">
								{{ saving ? t('openbuilt', 'Saving…') : t('openbuilt', 'Save') }}
							</button>
							<button
								v-if="selectedRole === 'owner'"
								:disabled="!selected || publishing || !canPublish"
								@click="publish">
								{{ publishing ? t('openbuilt', 'Publishing…') : t('openbuilt', 'Publish') }}
							</button>
							<button
								v-if="selectedRole === 'owner'"
								:disabled="!selected"
								@click="openPermissionsModal">
								{{ t('openbuilt', 'Manage permissions') }}
							</button>
							<router-link
								v-if="selected"
								class="openbuilt-editor__link"
								:to="{ name: 'PageDesigner', params: { slug: selected.slug } }">
								{{ t('openbuilt', 'Design pages') }}
							</router-link>
							<a v-if="selected && (selected.currentVersion || selected.status === 'published')" :href="builderUrl">
								{{ t('openbuilt', 'Open virtual app') }}
							</a>
						</div>
						<div v-if="publishToast" class="openbuilt-editor__toast">
							{{ publishToast }}
						</div>
					</div>

					<div v-else-if="activeTab === 'history'" class="openbuilt-editor__history-tab">
						<VersionHistory
							:application-uuid="selectedUuid"
							:current-version-uuid="selected.currentVersion || ''"
							@compare="onCompare"
							@rollback="onRollback" />
					</div>

					<div v-else-if="activeTab === 'diff'" class="openbuilt-editor__diff-tab">
						<ManifestDiff
							:slug="selected.slug"
							:from="diffPair.from"
							:to="diffPair.to" />
					</div>
				</section>
			</div>
			<PermissionsModal
				:open="permissionsModalOpen"
				:application="selected"
				:available-groups="availableGroups"
				@update:open="permissionsModalOpen = $event"
				@save="onPermissionsSave" />
		</NcAppContent>
	</div>
</template>

<script>
import { NcAppContent } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { validateManifest } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import VersionHistory from './VersionHistory.vue'
import ManifestDiff from '../components/ManifestDiff.vue'
import PermissionsModal from '../modals/PermissionsModal.vue'
import { useRole, hasAnyRole, getCurrentUserGroups } from '../composables/useRole.js'

export default {
	name: 'ApplicationEditor',
	components: {
		NcAppContent,
		VersionHistory,
		ManifestDiff,
		PermissionsModal,
	},
	data() {
		return {
			applications: [],
			selectedUuid: null,
			manifestText: '',
			validationError: '',
			saving: false,
			publishing: false,
			publishToast: '',
			activeTab: 'editor',
			diffPair: { from: 'draft', to: '' },
			currentUserGroups: getCurrentUserGroups(),
			permissionsModalOpen: false,
			availableGroups: [],
		}
	},
	computed: {
		visibleApplications() {
			// REQ-OBR-007 — filter the list to Applications on which the
			// caller has at least one role (or any role when admin bypass
			// is active server-side; the frontend never sees admins as a
			// special case — they get the same filter as anyone else).
			return this.applications.filter(app => hasAnyRole(app, this.currentUserGroups))
		},
		selected() {
			return this.applications.find(a => this.appUuid(a) === this.selectedUuid) || null
		},
		selectedRole() {
			return useRole(this.selected, this.currentUserGroups)
		},
		builderUrl() {
			if (!this.selected) {
				return ''
			}
			return generateUrl(`/apps/openbuilt/builder/${this.selected.slug}`)
		},
		canPublish() {
			return this.selected && (this.selected.status === 'draft' || this.selected.status === 'published')
		},
		isDraftModified() {
			if (!this.selected || !this.selected.currentVersion) {
				return false
			}
			// Compare textarea against the Application's manifest (canonical
			// stored state). The currentVersion's manifest is byte-equal to
			// the published Application manifest at snapshot time; comparing
			// against the Application's stored manifest catches "saved-but-
			// not-published" edits as well.
			try {
				const parsed = JSON.parse(this.manifestText)
				return JSON.stringify(parsed) !== JSON.stringify(this.selected.manifest || {})
			} catch (e) {
				return true
			}
		},
	},
	async mounted() {
		await this.refresh()
		this.availableGroups = Array.from(new Set([
			...this.currentUserGroups,
			...this.collectKnownGroups(),
		]))
		if (this.visibleApplications.length > 0) {
			this.select(this.visibleApplications[0])
		}
	},
	methods: {
		appUuid(app) {
			if (!app) {
				return null
			}
			const self = app['@self'] || {}
			return self.id || self.uuid || app.uuid || app.id || null
		},
		statusLabel(status) {
			const map = {
				draft: t('openbuilt', 'draft'),
				published: t('openbuilt', 'published'),
				archived: t('openbuilt', 'archived'),
			}
			return map[status] || status
		},
		badgeClass(status) {
			return `openbuilt-editor__badge--${status || 'draft'}`
		},
		async refresh() {
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuilt/application')
				const { data } = await axios.get(url, { params: { _limit: 100 } })
				this.applications = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
			} catch (e) {
				this.applications = []
				this.validationError = `Failed to load applications: ${e.message || e}`
			}
		},
		collectKnownGroups() {
			// Surface groups already referenced in any Application's
			// permissions so the owner can pick from them in the modal.
			const gids = new Set()
			for (const app of this.applications) {
				const perms = (app && app.permissions) || {}
				;['owners', 'editors', 'viewers'].forEach(role => {
					if (Array.isArray(perms[role])) {
						perms[role].forEach(g => gids.add(g))
					}
				})
			}
			return Array.from(gids)
		},
		roleFor(app) {
			return useRole(app, this.currentUserGroups)
		},
		/**
		 * Map a raw role token ('owner'|'editor'|'viewer'|'none') to its
		 * translated label. Plain English keys per the i18n decision —
		 * the role token is an enum, the label is a user-facing string.
		 *
		 * @param {string} role The raw role token from useRole
		 * @return {string} The translated label
		 */
		translatedRole(role) {
			switch (role) {
			case 'owner':
				return t('openbuilt', 'Owner')
			case 'editor':
				return t('openbuilt', 'Editor')
			case 'viewer':
				return t('openbuilt', 'Viewer')
			default:
				return t('openbuilt', 'No access')
			}
		},
		select(app) {
			this.selectedUuid = this.appUuid(app)
			this.manifestText = JSON.stringify(app.manifest || {}, null, 2)
			this.validationError = ''
			this.publishToast = ''
			// Default diff pair: current draft vs latest published snapshot.
			this.diffPair = { from: 'draft', to: app.currentVersion || '' }
		},
		parseAndValidate() {
			let parsed
			try {
				parsed = JSON.parse(this.manifestText)
			} catch (e) {
				this.validationError = `JSON parse error: ${e.message}`
				return null
			}
			const result = validateManifest ? validateManifest(parsed) : { valid: true, errors: [] }
			if (result && result.valid === false) {
				this.validationError = (result.errors || ['unknown']).join('; ')
				return null
			}
			return parsed
		},
		async save() {
			if (!this.selected) {
				return
			}
			if (this.selectedRole !== 'editor' && this.selectedRole !== 'owner') {
				this.validationError = t('openbuilt', 'Editor or owner role required to save the manifest.')
				return
			}
			this.validationError = ''
			const parsed = this.parseAndValidate()
			if (parsed === null) {
				return
			}
			this.saving = true
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}`)
				await axios.put(url, { ...this.selected, manifest: parsed })
				await this.refresh()
				const updated = this.applications.find(a => this.appUuid(a) === this.selectedUuid)
				if (updated) {
					this.select(updated)
				}
			} catch (e) {
				this.validationError = `Save failed: ${e.message || e}`
			} finally {
				this.saving = false
			}
		},
		async publish() {
			if (!this.selected || this.publishing) {
				return
			}
			if (this.selectedRole !== 'owner') {
				this.validationError = t('openbuilt', 'Editor or owner role required to save the manifest.')
				return
			}
			this.validationError = ''
			this.publishToast = ''
			const parsed = this.parseAndValidate()
			if (parsed === null) {
				return
			}
			this.publishing = true
			try {
				// Save pending edits first (manifest only).
				const saveUrl = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}`)
				await axios.put(saveUrl, { ...this.selected, manifest: parsed })

				// Invoke OR's lifecycle transition endpoint. The "publish" action
				// triggers ObjectTransitionedEvent which our listener consumes to
				// create the ApplicationVersion snapshot + bump currentVersion.
				const transitionUrl = generateUrl(
					`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}/transition/publish`,
				)
				const { data } = await axios.post(transitionUrl, {})
				await this.refresh()
				const updated = this.applications.find(a => this.appUuid(a) === this.selectedUuid)
				if (updated) {
					this.select(updated)
				}
				const newVersion = (data && (data.currentVersion || data.uuid)) || (updated && updated.currentVersion) || ''
				this.publishToast = t('openbuilt', 'Published version {uuid}', { uuid: newVersion ? String(newVersion).slice(0, 8) + '…' : '' })
			} catch (e) {
				this.validationError = `Publish failed: ${e.message || e}`
			} finally {
				this.publishing = false
			}
		},
		onCompare({ from, to }) {
			this.diffPair = { from, to }
			this.activeTab = 'diff'
		},
		async onRollback(version) {
			if (!this.selected || !version || !version.manifest) {
				return
			}
			try {
				// Per REQ-OBV-003: copy the snapshot's manifest onto the Application,
				// set version with a rollback marker, leave status as draft. Never
				// touches existing ApplicationVersion rows (append-only).
				const rollbackVersion = `${version.version}-rollback-${this.shortHex()}`
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}`)
				await axios.put(url, {
					...this.selected,
					manifest: version.manifest,
					version: rollbackVersion,
					status: 'draft',
				})
				await this.refresh()
				const updated = this.applications.find(a => this.appUuid(a) === this.selectedUuid)
				if (updated) {
					this.select(updated)
				}
				this.activeTab = 'editor'
			} catch (e) {
				this.validationError = `Rollback failed: ${e.message || e}`
			}
		},
		shortHex() {
			// 6-hex-char rollback marker per design.md OQ-2 (pre-release form).
			const bytes = new Uint8Array(3)
			if (globalThis.crypto && globalThis.crypto.getRandomValues) {
				globalThis.crypto.getRandomValues(bytes)
			} else {
				for (let i = 0; i < bytes.length; i++) {
					bytes[i] = Math.floor(Math.random() * 256)
				}
			}
			return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('')
		},
		openPermissionsModal() {
			if (this.selectedRole !== 'owner') {
				return
			}
			this.permissionsModalOpen = true
		},
		async onPermissionsSave(permissions) {
			if (this.selectedRole !== 'owner' || !this.selected) {
				return
			}
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuilt/application/${this.selectedUuid}`)
				await axios.put(url, { ...this.selected, permissions })
				this.permissionsModalOpen = false
				await this.refresh()
				const updated = this.applications.find(a => this.appUuid(a) === this.selectedUuid)
				if (updated) {
					this.select(updated)
				}
			} catch (e) {
				this.validationError = `Failed to save permissions: ${e.message || e}`
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-editor__layout {
	display: flex;
	gap: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px);
	height: 100%;
}

.openbuilt-editor__list {
	width: 240px;
	flex-shrink: 0;
	overflow-y: auto;
	border-right: 1px solid var(--color-border, #ddd);
}

.openbuilt-editor__list ul {
	list-style: none;
	padding: 0;
	margin: 0;
}

.openbuilt-editor__list li {
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius, 4px);
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 6px;
}

.openbuilt-editor__list li.active {
	background: var(--color-primary-light, #e6f0fa);
}

.openbuilt-editor__empty {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
	padding: 8px 12px;
}

.openbuilt-editor__pane {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 16px;
}

.openbuilt-editor__pane-header h2 {
	margin: 0 0 4px;
}

.openbuilt-editor__meta {
	display: flex;
	gap: 8px;
	align-items: center;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.openbuilt-editor__modified {
	color: var(--color-warning, #c97900);
	font-weight: bold;
}

.openbuilt-editor__tabs {
	display: flex;
	gap: 4px;
	margin-top: 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.openbuilt-editor__tabs button {
	background: transparent;
	border: none;
	padding: 6px 10px;
	cursor: pointer;
	color: var(--color-main-text, #222);
	border-bottom: 2px solid transparent;
}

.openbuilt-editor__tabs button.active {
	border-bottom-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element, #0082c9);
}

.openbuilt-editor__editor-tab,
.openbuilt-editor__history-tab,
.openbuilt-editor__diff-tab {
	display: flex;
	flex-direction: column;
	gap: 8px;
	flex: 1 1 auto;
}

.openbuilt-editor__textarea {
	flex: 1 1 auto;
	min-height: 400px;
	font-family: monospace;
	font-size: 13px;
	padding: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.openbuilt-editor__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}

.openbuilt-editor__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.openbuilt-editor__toast {
	padding: 6px 10px;
	background: var(--color-success-default-background, rgba(70, 186, 97, 0.2));
	color: var(--color-success-text, #2d8a3e);
	border-radius: var(--border-radius, 4px);
	font-size: 13px;
}

.openbuilt-editor__help {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.openbuilt-editor__badge {
	display: inline-block;
	font-size: 11px;
	padding: 1px 6px;
	border-radius: var(--border-radius-pill, 12px);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.openbuilt-editor__badge--draft {
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.openbuilt-editor__badge--published {
	background: var(--color-success-default-background, rgba(70, 186, 97, 0.2));
	color: var(--color-success-text, #2d8a3e);
}

.openbuilt-editor__badge--archived {
	background: var(--color-warning-default-background, rgba(201, 121, 0, 0.2));
	color: var(--color-warning-text, #8a5300);
}
</style>
