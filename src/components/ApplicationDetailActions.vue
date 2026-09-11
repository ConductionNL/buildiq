<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDetailActions — the actions bar on the VirtualAppDetail
  - (`type: detail`) page (`config.actionsComponent: "ApplicationDetailActions"`).
  - The whole cluster is ONE CnActionButtons, fed by the `actionDescriptors`
  - computed: "Open app" primary (carrying the version list as its chevron
  - `children`), then Settings and the page's own Edit, then everything else
  - (chrome editors, permissions, Save as template, GitHub, Documentation,
  - Export, Delete). `inline` is 2, so those two stay buttons beside the primary
  - and the rest collapse into a single "··· Actions".
  - Descriptors are composed in JS, not declared in the manifest, because each
  - drives this component's own modals and the applicationContext gating.
  - Page/walkthrough design happens inside the running app via the in-app
  - Buildiq edit menu (CnBuildiqEditButton, ADR-041), not from here.
  - Reads/writes the Application via OR's REST API (ADR-022) + the dedicated
  - publish/delete endpoints, using the applicationContext mixin. Modals/dialogs
  - live in their own files per ADR-004 gate-modal-isolation.
  -->
<template>
	<div class="ob-detail-actions">
		<!-- The whole cluster is ONE CnActionButtons: `inline` decides how many
		     stay buttons and the rest collapse into a single `···`, so there is
		     one overflow menu on the page rather than this component's and the
		     page's side by side. Descriptors are built in JS rather than
		     declared in the manifest because every one of them drives this
		     component's own modals and mixin gating — `onSelect` exists for
		     exactly that. Open app keeps `variant: 'primary'` (never collapsed,
		     costs no inline slot) and carries the version list as `children`, so
		     it renders as the same split button it always was. -->
		<CnActionButtons
			:actions="actionDescriptors"
			:inline="2"
			:overflowLabel="t('buildiq', 'Actions')" />
		<span v-if="toast" class="ob-detail-actions__toast">{{ toast }}</span>
		<span v-if="error" class="ob-detail-actions__error">{{ error }}</span>

		<ExportDialog
			v-if="exportOpen && obApp"
			:applicationSlug="obApp.slug"
			:data-registers="obApp.dataRegisters || []"
			:flows="obApp.flows || []"
			@close="exportOpen = false" />
		<GitHubSyncModal
			v-if="obApp && obApp.slug"
			:open="githubOpen"
			:slug="obApp.slug"
			:isOwner="obAppRole === 'owner'"
			@update:open="githubOpen = $event" />
		<CnEditSupportModal
			v-if="supportOpen"
			:working="supportWorkingManifest"
			@close="onSupportClose" />
		<AppSettingsModal
			:open="settingsOpen"
			:appName="(obApp && (obApp.name || obApp.slug)) || ''"
			:isPublished="(obApp && obApp.status) === 'published'"
			:allowUserOverrides="!!(obApp && obApp.allowUserOverrides)"
			:data-registers="(obApp && obApp.dataRegisters) || []"
			:flows="(obApp && obApp.flows) || []"
			:availableFlows="availableFlows"
			:loadingFlows="loadingFlows"
			:busy="publishing"
			@update:open="onSettingsOpen"
			@setPublished="setPublished"
			@update:allowOverrides="setAllowOverrides"
			@update:dataRegisters="setDataRegisters"
			@update:flows="setFlows" />
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
// The action cluster names its icons as STRINGS in the CnActionButtons
// descriptors, resolved by CnIcon through the registry src/icons.js fills — so
// this file imports no icon components of its own any more.
import { CnActionButtons, CnEditSupportModal } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineAsyncComponent } from 'vue'
import DeleteAppDialog from '../dialogs/DeleteAppDialog.vue'
import SaveAsTemplateDialog from '../dialogs/SaveAsTemplateDialog.vue'
import AppSettingsModal from '../modals/AppSettingsModal.vue'
import GitHubSyncModal from '../modals/GitHubSyncModal.vue'
import PermissionHistoryModal from '../modals/PermissionHistoryModal.vue'
import PermissionsModal from '../modals/PermissionsModal.vue'
import { useRegisterPicker } from '../composables/useRegisterPicker.js'
import { getCurrentUserGroups } from '../composables/useRole.js'
import applicationContext from '../mixins/applicationContext.js'
import { buildVersionedRoute } from '../router/helpers.js'

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

const OR_TEMPLATES = '/apps/openregister/api/objects/buildiq/application-template'

export default {
	name: 'ApplicationDetailActions',
	components: {
		CnActionButtons,
		PermissionsModal,
		PermissionHistoryModal,
		AppSettingsModal,
		CnEditSupportModal,
		GitHubSyncModal,
		DeleteAppDialog,
		SaveAsTemplateDialog,
		ExportDialog,
	},

	mixins: [applicationContext],

	props: {
		/**
		 * CnDetailPage's own record-edit opener, handed down through its
		 * `#actions` slot scope. Lets Edit live inside this component's action
		 * cluster instead of as a separate button beside it; the manifest sets
		 * `showEditAction: false` so the page renders none of its own.
		 */
		openEditForm: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			versions: [],
			publishing: false,
			deleting: false,
			githubOpen: false,
			settingsOpen: false,
			// The support-note editor mutates a working manifest copy in place
			// and emits `close`, so it needs its own copy to edit and a flag to
			// mount it. Held here rather than in the modal so a cancel simply
			// drops the copy.
			supportOpen: false,
			supportWorkingManifest: null,
			// Every flow on the instance, as picker options. Loaded lazily when
			// the settings modal opens — see onSettingsOpen().
			availableFlows: [],
			loadingFlows: false,
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
		 * The whole action cluster as CnActionButtons descriptors, in the order
		 * they should appear: Open app (primary, with the version list as its
		 * chevron), then the app-level actions, then the chrome editors, then
		 * Edit last so it collapses before anything the app owns.
		 *
		 * Built here rather than declared in the manifest because each one
		 * drives this component's own modals and the applicationContext mixin's
		 * gating — `onSelect` is the hook for exactly that. The `v-if`s the old
		 * markup carried become plain filters, so a non-owner still simply sees
		 * fewer entries.
		 *
		 * @return {Array<object>} Descriptors for CnActionButtons.
		 *
		 * @spec exclude assembles the already-specified actions (`canPublish`,
		 * `publish`, `builderUrl`, `onPermissionsSave`) into descriptors
		 */
		actionDescriptors() {
			const isOwner = this.obAppRole === 'owner'
			const out = []

			if (this.builderUrl) {
				out.push({
					id: 'open-app',
					label: t('buildiq', 'Open app'),
					icon: 'OpenInNew',
					variant: 'primary',
					href: this.builderUrl,
					target: '_blank',
					childrenLabel: t('buildiq', 'Open a version'),
					children: this.openableVersions.flatMap((v) => [
						{
							id: `open-${v.slug}`,
							label: this.versionLabel(v),
							icon: 'OpenInNew',
							href: this.versionUrl(v),
							target: '_blank',
						},
						...(this.canEditVersions
							? [
									{
										id: `edit-${v.slug}`,
										label: t('buildiq', 'Edit {name}', {
											name: this.versionLabel(v),
										}),
										icon: 'PencilRulerOutline',
										onSelect: () => this.editVersion(v),
									},
								]
							: []),
					]),
				})
			}

			// ── The two meant to stay BUTTONS, Edit last ──
			// CnActionButtons promotes the first N collapsible entries in
			// declaration order, so this block IS the inline set: whatever sits
			// here is what the header shows beside `···`. Keep it two long while
			// `inline` is 2, and keep Edit at the end of it — that is what puts
			// Edit immediately left of the trigger. With the never-collapsed
			// "Open app" that makes three buttons in the header.
			if (isOwner) {
				out.push({
					id: 'app-settings-action',
					label: t('buildiq', 'Settings'),
					icon: 'CogOutline',
					onSelect: () => this.onSettingsOpen(true),
				})
			}
			if (typeof this.openEditForm === 'function') {
				out.push({
					id: 'app-edit-record',
					label: t('buildiq', 'Edit'),
					icon: 'PencilOutline',
					onSelect: () => this.openEditForm(),
				})
			}

			// ── Everything below collapses into the `···` menu ──
			if (this.canEditVersions) {
				out.push(
					{
						id: 'app-edit-setup',
						label: t('buildiq', 'Setup wizard'),
						icon: 'MapMarkerPath',
						onSelect: () => this.openWalkthroughDesigner('setup'),
					},
					{
						id: 'app-edit-walkthrough',
						label: t('buildiq', 'Walkthrough'),
						icon: 'MapMarkerPath',
						onSelect: () => this.openWalkthroughDesigner('walkthrough'),
					},
				)
			}
			if (isOwner) {
				out.push({
					id: 'app-permissions',
					label: t('buildiq', 'Manage permissions'),
					icon: 'AccountMultipleOutline',
					onSelect: () => {
						this.permissionsOpen = true
					},
				})
			}
			if (this.canSaveAsTemplate) {
				out.push({
					id: 'app-save-as-template',
					label: this.saveTemplateLoading
						? t('buildiq', 'Preparing…')
						: t('buildiq', 'Save as template'),
					icon: 'ContentSaveOutline',
					onSelect: () => this.openSaveAsTemplate(),
				})
			}
			if (this.obApp && this.obApp.slug) {
				out.push({
					id: 'app-github',
					label: t('buildiq', 'GitHub'),
					icon: 'Github',
					onSelect: () => {
						this.githubOpen = true
					},
				})
			}
			if (isOwner) {
				out.push({
					id: 'app-permission-history',
					label: t('buildiq', 'Permission history'),
					icon: 'History',
					onSelect: () => {
						this.historyOpen = true
					},
				})
			}
			if (this.canEditVersions) {
				out.push({
					id: 'app-edit-support',
					label: t('buildiq', 'Support & donation'),
					icon: 'HeartOutline',
					onSelect: () => this.openSupportEditor(),
				})
			}
			out.push(
				{
					id: 'app-export',
					label: t('buildiq', 'Export'),
					icon: 'TrayArrowDown',
					onSelect: () => {
						this.exportOpen = true
					},
				},
				{
					id: 'app-documentation',
					label: t('buildiq', 'Documentation'),
					icon: 'HelpCircleOutline',
					href: 'https://openbuild.conduction.nl',
					target: '_blank',
				},
			)
			if (isOwner) {
				out.push({
					id: 'app-delete',
					label: t('buildiq', 'Delete'),
					icon: 'DeleteOutline',
					variant: 'error',
					onSelect: () => {
						this.deleteOpen = true
					},
				})
			}

			return out
		},

		/**
		 * URL of the app's own manifest runtime (the nested CnAppRoot host at
		 * /builder/{slug}). Shown as the primary "Open app" action whenever the
		 * app slug is known. Top-level URL — the runtime is a sibling route.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		builderUrl() {
			if (!this.obApp || !this.obApp.slug) {
				return ''
			}
			return generateUrl(`/apps/buildiq/builder/${this.obApp.slug}`)
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
					'/apps/buildiq/api/applications/{slug}/versions',
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
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		versionLabel(v) {
			const name = (v && (v.name || v.slug)) || ''
			const semver = v && v.semver ? ` (${v.semver})` : ''
			const prod = this.isProductionVersion(v)
				? ` — ${t('buildiq', 'Production')}`
				: ''
			return `${name}${semver}${prod}`
		},

		/**
		 * A version's URL in the live shell — production at the canonical URL,
		 * any other via `?_version=` (RBAC-gated server-side). Fed to the action
		 * descriptor's `href`, so the entry is a real link.
		 *
		 * @param {object} v The version row.
		 * @return {string|null} The URL, or null when there is no app slug.
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		versionUrl(v) {
			if (!this.obApp || !this.obApp.slug) {
				return null
			}
			const base = generateUrl(`/apps/buildiq/builder/${this.obApp.slug}`)
			return this.isProductionVersion(v)
				? base
				: `${base}?_version=${encodeURIComponent(v.slug)}`
		},

		/**
		 * Edit a version in the page designer, scoped via `?_version=` for
		 * non-production versions.
		 *
		 * Routed, not `window.location.href`: `/builder/{slug}/pages` is the SPA's
		 * own PageDesigner route, so the hard navigation only bought a full
		 * reload — which also silently ended any in-progress walkthrough.
		 *
		 * @param {object} v The version row.
		 * @return {void}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		editVersion(v) {
			if (!this.obApp || !this.obApp.slug) {
				return
			}
			this.$router
				.push(
					buildVersionedRoute(
						'PageDesigner',
						{ slug: this.obApp.slug },
						this.isProductionVersion(v) ? undefined : v.slug,
					),
				)
				.catch(() => {})
		},

		/**
		 * Publish or unpublish the app (owner-only, enforced again server-side).
		 * Publishing makes it appear in the Nextcloud app menu.
		 *
		 * @param {boolean} shouldPublish True to publish, false to unpublish.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
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
						`/apps/buildiq/api/applications/${this.obAppUuid}/${action}`,
					),
					{},
				)
				// Force a refetch — the `object` prop snapshot still carries the
				// pre-publish status, so the default load path would keep the
				// toggle/badge stale until a full page reload.
				await this.obLoadApp(true)
				this.toast = shouldPublish
					? t('buildiq', 'App published — it now appears in the app menu.')
					: t('buildiq', 'App unpublished — removed from the app menu.')
			} catch (e) {
				const detail =
					(e.response && e.response.data && e.response.data.detail)
					|| e.message
					|| e
				this.error = shouldPublish
					? `${t('buildiq', 'Publish failed')}: ${detail}`
					: `${t('buildiq', 'Unpublish failed')}: ${detail}`
			} finally {
				this.publishing = false
			}
		},

		/**
		 * Toggle per-user manifest overrides on the app.
		 *
		 * @param {boolean} allow Whether to allow per-user overrides.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async setAllowOverrides(allow) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ allowUserOverrides: allow })
			} catch (e) {
				this.error = `${t('buildiq', 'Failed to save settings')}: ${e.message || e}`
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
		/**
		 * Open/close the settings modal, loading the flow list on the way in.
		 *
		 * Loaded lazily rather than with the page: most visits never open
		 * settings, and an instance can hold many flows (86 on one dev box).
		 *
		 * @param {boolean} open Whether the modal is opening.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		/**
		 * Open the support-note editor.
		 *
		 * `CnEditSupportModal` edits `manifest.support` in place on a working
		 * copy, so this resolves the app's manifest first and hands over a
		 * clone. Editing a clone means cancelling costs nothing: the copy is
		 * simply dropped.
		 *
		 * The manifest is resolved from the ACTIVE VERSION through the app's
		 * own endpoint, not read off the Application record, because an
		 * Application carries no manifest — it lives on the ApplicationVersion.
		 * Reading `obApp.manifest` yields undefined and opens the editor on an
		 * empty object.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec exclude opens an existing library editor on the resolved manifest
		 */
		async openSupportEditor() {
			if (!this.obApp || !this.obApp.slug) {
				return
			}

			this.error = ''
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${encodeURIComponent(this.obApp.slug)}/manifest`,
				)
				const { data } = await axios.get(url)
				this.supportWorkingManifest = JSON.parse(JSON.stringify(data || {}))
				this.supportOpen = true
			} catch (e) {
				this.error = `${t('buildiq', 'Failed to load settings')}: ${e.message || e}`
			}
		},

		/**
		 * Close the support-note editor and drop the working copy.
		 *
		 * @return {void}
		 *
		 * @spec exclude closes a modal, no behaviour
		 */
		onSupportClose() {
			this.supportOpen = false
			this.supportWorkingManifest = null
		},

		/**
		 * Open the walkthrough designer for this app, on a chosen tab.
		 *
		 * The designer is a page (`WalkthroughDesigner` at
		 * `/builder/:slug/walkthrough`), not a modal, so this routes rather
		 * than opening a dialog. It hosts BOTH editors: the guided tour and
		 * the first-run setup wizard. `?mode=setup` selects the latter, so the
		 * Setup wizard button lands on its own tab rather than on the
		 * walkthrough with the user left to find it.
		 *
		 * @param {string} mode Which tab to open, `walkthrough` or `setup`.
		 *
		 * @return {void}
		 *
		 * @spec exclude routes to an existing page, no new behaviour
		 */
		openWalkthroughDesigner(mode) {
			const slug = this.obApp && this.obApp.slug
			if (!slug) {
				return
			}

			this.$router
				.push({
					name: 'WalkthroughDesigner',
					params: { slug },
					query: mode === 'setup' ? { mode: 'setup' } : {},
				})
				.catch(() => {})
		},

		/**
		 * Track the settings modal's open state and load the flow list once.
		 *
		 * The flows are fetched lazily rather than with the rest of the page:
		 * they are only ever read inside this modal, and most visits to an
		 * application never open it. The three guards make the fetch happen
		 * exactly once — not on close, not when a previous open already
		 * filled the list, and not while a request is still in flight.
		 *
		 * @param {boolean} open Whether the settings modal is now open.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async onSettingsOpen(open) {
			this.settingsOpen = open
			if (!open || this.availableFlows.length || this.loadingFlows) {
				return
			}

			this.loadingFlows = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/flows'),
				)
				const list = data.results || data.items || data || []
				// Label by NAME, value by UUID: the binding is a UUID because
				// the Flow entity has no slug, and a UUID in a picker is
				// unreadable.
				this.availableFlows = list
					.map((flow) => ({
						label: flow.name || flow.uuid,
						value: flow.uuid || (flow['@self'] && flow['@self'].id),
					}))
					.filter((option) => !!option.value)
			} catch (e) {
				this.error = `${t('buildiq', 'Failed to load flows')}: ${e.message || e}`
			} finally {
				this.loadingFlows = false
			}
		},

		/**
		 * Persist the app's `flows` bindings from the settings modal.
		 *
		 * @param {Array<{flow: string, label?: string}>} flows The full updated bindings array.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async setFlows(flows) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ flows })
			} catch (e) {
				this.error = `${t('buildiq', 'Failed to save settings')}: ${e.message || e}`
			}
		},

		/**
		 * Persist the app's data-register selection (owner only).
		 *
		 * @param {Array<string>} dataRegisters The selected register slugs.
		 * @return {Promise<void>}
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async setDataRegisters(dataRegisters) {
			if (this.obAppRole !== 'owner' || !this.obApp) {
				return
			}
			this.error = ''
			try {
				await this.obPatchApp({ dataRegisters })
			} catch (e) {
				this.error = `${t('buildiq', 'Failed to save settings')}: ${e.message || e}`
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
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async deleteApp(deleteData = false) {
			if (this.obAppRole !== 'owner' || !this.obApp || this.deleting) {
				return
			}
			this.deleting = true
			this.error = ''
			try {
				await axios.delete(
					generateUrl(`/apps/buildiq/api/applications/${this.obAppUuid}`),
					{
						params: { deleteData: deleteData ? 1 : 0 },
					},
				)
				this.deleteOpen = false
				if (this.$router) {
					this.$router.push({ name: 'VirtualApps' }).catch(() => {})
				} else {
					window.location.href = generateUrl('/apps/buildiq/applications')
				}
			} catch (e) {
				const detail =
					(e.response && e.response.data && e.response.data.detail)
					|| e.message
					|| e
				this.error = `${t('buildiq', 'Delete failed')}: ${detail}`
			} finally {
				this.deleting = false
			}
		},

		/**
		 * Persist edited permissions from the permissions modal.
		 *
		 * @param {object} permissions The new permissions block.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
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
				this.error = `${t('buildiq', 'Failed to save permissions')}: ${e.message || e}`
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
					`/apps/buildiq/api/applications/${encodeURIComponent(this.obApp.slug)}/manifest`,
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
				this.error = `${t('buildiq', 'Could not prepare template capture')}: ${e.message || e}`
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
					? t('buildiq', 'Template "{slug}" updated', {
							slug: payload.slug,
						})
					: t('buildiq', 'Saved as template "{slug}"', {
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
