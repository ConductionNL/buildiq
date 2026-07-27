<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - SchemaDesigner — the top-level view for the OpenBuild schema
  - designer (REQ-OBSD-001 — REQ-OBSD-008). Mounted at
  - `/builder/:slug/schemas` (list mode) and
  - `/builder/:slug/schemas/:schemaId` (detail mode). Owns the staged
  - copy of the schema being edited; sub-editors emit `update:*`
  - events that mutate the staged copy; Save composes the JSON Schema
  - body and PUTs via the schemas store.
  -
  - Per ADR-031 every behaviour-shaping field is a typed declarative
  - record drawn from OR's declarative vocabulary; the editor itself
  - is code, but its output is declarative JSON.
  -
  - All OR CRUD goes through the `useSchemasStore` Pinia store (which
  - wraps `createObjectStore` from `@conduction/nextcloud-vue`) — never
  - via direct axios calls. The store hits the per-virtual-app register
  - `openbuild-{slug}` per the hybrid register model: system schemas
  - live in shared `openbuild`, user-authored schemas live per-app.
  -->
<template>
	<div class="openbuild-schema-designer">
		<!-- List mode -->
		<template v-if="!schemaId">
			<div v-if="canImport" class="openbuild-schema-designer__toolbar">
				<NcButton type="secondary" @click="showImportWizard = true">
					{{ t('openbuild', 'Import data') }}
				</NcButton>
			</div>
			<SchemaListPanel
				:schemas="schemas"
				:loading="loadingList"
				@add="addSchema"
				@open="openSchema"
				@delete="deleteSchema" />
		</template>

		<!-- Detail mode -->
		<div v-else class="openbuild-schema-designer__detail">
			<header class="openbuild-schema-designer__detail-header">
				<div>
					<NcButton type="tertiary" @click="goToList">
						<template #icon>
							<ArrowLeftIcon :size="20" />
						</template>
						{{ t('openbuild', 'Back to schemas') }}
					</NcButton>
					<h2 v-if="staged">
						{{ staged.title || schemaId }}
					</h2>
				</div>
				<div class="openbuild-schema-designer__detail-actions">
					<NcButton
						type="tertiary"
						:disabled="!canUndo"
						:title="t('openbuild', 'Undo (Ctrl+Z)')"
						@click="undo">
						<template #icon>
							<UndoIcon :size="20" />
						</template>
						{{ t('openbuild', 'Undo') }}
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="!canRedo"
						:title="t('openbuild', 'Redo (Ctrl+Shift+Z / Ctrl+Y)')"
						@click="redo">
						<template #icon>
							<RedoIcon :size="20" />
						</template>
						{{ t('openbuild', 'Redo') }}
					</NcButton>
					<NcButton :disabled="!hasStagedChanges || saving" @click="discardChanges">
						{{ t('openbuild', 'Discard staged edits') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!canSave"
						@click="save">
						{{ saving ? t('openbuild', 'Saving...') : t('openbuild', 'Save') }}
					</NcButton>
				</div>
			</header>

			<!--
				Show the spinner until the detail load has actually been ATTEMPTED,
				not merely while its request is in flight. `mounted()` awaits
				refreshList() before calling loadDetail(), so there is a window
				where a schemaId is present, `staged` is still null and
				`loadingDetail` is still false — during which the v-else below
				rendered "Schema not found" for a schema that exists and is about
				to load. That false empty state is what a freshly created schema
				lands on right after Add-schema navigates here.
			-->
			<div v-if="loadingDetail || !detailAttempted" class="openbuild-schema-designer__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else-if="staged">
				<NcNoteCard v-if="saveError" type="error">
					{{ saveError }}
				</NcNoteCard>

				<NcNoteCard v-if="!hasInitialLifecycleState && hasLifecycleStates" type="warning">
					{{ t('openbuild', 'Exactly one lifecycle state must be marked as initial before you can save.') }}
				</NcNoteCard>

				<SchemaHeaderForm
					:value="headerValue"
					:locked-slug="true"
					@input="onHeaderChange" />

				<FieldEditor
					:fields="staged.fields"
					:schema-slugs="otherSchemaSlugs"
					@update:fields="onFieldsChange" />

				<LifecycleEditor
					:states="staged.states"
					:transitions="staged.transitions"
					@update:states="onStatesChange"
					@update:transitions="onTransitionsChange" />

				<RelationEditor
					:relations="staged.relations"
					:schema-slugs="otherSchemaSlugs"
					@update:relations="onRelationsChange" />

				<NcNoteCard v-if="authorLockedOut" type="warning">
					{{ t('openbuild', 'Saving this read scope will make this schema\'s records invisible to you. Save remains available — this may be an intentional admin-assisted handover.') }}
				</NcNoteCard>

				<AccessEditor
					:access="staged.access"
					:field-names="fieldNames"
					:available-groups="availableGroups"
					:read-only="accessReadOnly"
					@update:access="onAccessChange" />

				<WidgetEditor
					:widgets="staged.widgets"
					@update:widgets="onWidgetsChange" />

				<AggregationEditor :aggregations="staged.aggregations" />
				<CalculationEditor :calculations="staged.calculations" />
				<NotificationEditor :notifications="staged.notifications" />
			</template>

			<NcEmptyContent
				v-else
				:name="t('openbuild', 'Schema not found')"
				:description="t('openbuild', 'No schema with this slug exists in the current app.')">
				<template #action>
					<NcButton @click="goToList">
						{{ t('openbuild', 'Back to schemas') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<ImportDataWizard
			v-if="showImportWizard"
			:register-id="importRegisterId"
			:schemas="schemas"
			:initial-schema="schemaId || ''"
			@imported="onSchemaImported"
			@close="showImportWizard = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import UndoIcon from 'vue-material-design-icons/Undo.vue'
import RedoIcon from 'vue-material-design-icons/Redo.vue'

import SchemaListPanel from '../components/schema-editor/SchemaListPanel.vue'
import SchemaHeaderForm from '../components/schema-editor/SchemaHeaderForm.vue'
import FieldEditor, { fieldsToSchema, schemaToFields } from '../components/schema-editor/FieldEditor.vue'
import LifecycleEditor, { editorToLifecycle, lifecycleToEditor } from '../components/schema-editor/LifecycleEditor.vue'
import RelationEditor, { editorToRelations, relationsToEditor } from '../components/schema-editor/RelationEditor.vue'
import AccessEditor, { accessToEditor, editorToAccess } from '../components/schema-editor/AccessEditor.vue'
import WidgetEditor, { editorToWidgets, widgetsToEditor } from '../components/schema-editor/WidgetEditor.vue'
import AggregationEditor from '../components/schema-editor/AggregationEditor.vue'
import CalculationEditor from '../components/schema-editor/CalculationEditor.vue'
import NotificationEditor from '../components/schema-editor/NotificationEditor.vue'

import ImportDataWizard from '../dialogs/ImportDataWizard.vue'

import { useSchemasStore, registerSlugForApp } from '../store/schemas.js'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import { useRole, getCurrentUserGroups } from '../composables/useRole.js'
import { useSessionHistory } from '../composables/useSessionHistory.js'
import { isEditableTarget } from '../utils/isEditableTarget.js'
import { buildVersionedRoute } from '../router/helpers.js'

/**
 * Schema object type slug as registered with the store factory.
 * See `src/store/schemas.js` for the URL shape.
 */
const SCHEMA_TYPE = 'schema'

export default {
	name: 'SchemaDesigner',
	components: {
		AccessEditor,
		AggregationEditor,
		ArrowLeftIcon,
		UndoIcon,
		RedoIcon,
		CalculationEditor,
		FieldEditor,
		LifecycleEditor,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NotificationEditor,
		RelationEditor,
		SchemaHeaderForm,
		SchemaListPanel,
		WidgetEditor,
		ImportDataWizard,
	},
	/**
	 * REQ-BUR-005: the same shared nc-vue history engine (depth 100) the
	 * page designer uses, here operating over the staged editor model
	 * (design.md D5) rather than the composed JSON-Schema body.
	 *
	 * @return {object}
	 */
	setup() {
		const history = useSessionHistory(null, { limit: 100 })
		return { history }
	},
	data() {
		return {
			schemas: [],
			loadingList: false,
			loadingDetail: false,
			// Whether loadDetail() has run to completion for the current
			// schemaId. Distinct from `loadingDetail` (which only covers the
			// request itself) so the "Schema not found" empty state can never be
			// shown before the load has actually been attempted.
			detailAttempted: false,
			saving: false,
			saveError: '',
			staged: null,
			persisted: null,
			// REQ-OBVR-004: reactive version state resolved by useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
			// Import-data wizard state (openbuild-data-import-wizard).
			applicationRecord: null,
			showImportWizard: false,
		}
	},
	computed: {
		/**
		 * Resolve the active application slug from the route (seed fallback).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {string} App slug.
		 */
		appSlug() {
			// Falls back to the hello-world seed app when reached via the
			// top-level /schemas shortcut (which carries no :slug param).
			return this.$route.params.slug || 'hello-world'
		},
		/**
		 * Resolve the active schema id from the route.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {string} Schema id.
		 */
		schemaId() {
			return this.$route.params.schemaId || ''
		},
		/**
		 * REQ-OBVR-004: read `?_version=` from the URL query.
		 * The underscore-prefix param name is OpenBuild's system-reserved marker
		 * to avoid colliding with user-defined `?version=` params.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {string|undefined}
		 */
		versionSlug() {
			return this.$route.query._version || undefined
		},
		/**
		 * Resolve the schemas store bound to the active app/version register.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {object} Pinia store instance.
		 */
		store() {
			// Re-creates the binding when appSlug changes; the store
			// factory re-registers the `schema` type to the per-app
			// register `openbuild-{slug}` on every call (idempotent).
			// REQ-OBVR-007: pass versionSlug so the store targets the correct register.
			return useSchemasStore(this.appSlug, this.versionSlug)
		},
		/**
		 * The active version's own per-version register — the ONLY import
		 * target the wizard writes into (ADR-002).
		 *
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 * @return {string} Register slug.
		 */
		importRegisterId() {
			return registerSlugForApp(this.appSlug, this.versionSlug)
		},
		/**
		 * Whether the caller holds a build/manage role (owner or editor) on the
		 * Application. Gates the "Import data" affordance; the write is
		 * independently re-gated server-side by OpenRegister's own register
		 * manage-permission.
		 *
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 * @return {boolean}
		 */
		canImport() {
			const role = useRole(this.applicationRecord)
			return role === 'owner' || role === 'editor'
		},
		/**
		 * List the slugs of the other schemas (relation targets).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {Array<string>} Other schema slugs.
		 */
		otherSchemaSlugs() {
			return this.schemas
				.map((s) => s.slug || (s['@self'] && s['@self'].slug) || s.id)
				.filter((slug) => slug && slug !== this.schemaId)
		},
		/**
		 * Project the staged schema header fields for the header form.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {object} Header value object.
		 */
		headerValue() {
			if (!this.staged) {
				return { slug: '', title: '', description: '', version: '0.1.0' }
			}
			return {
				slug: this.staged.slug,
				title: this.staged.title,
				description: this.staged.description,
				version: this.staged.version,
			}
		},
		hasLifecycleStates() {
			return this.staged && this.staged.states && this.staged.states.length > 0
		},
		/**
		 * Field names from the staged FieldEditor model, fed to
		 * `AccessEditor`'s condition-row field picker.
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-001
		 * @return {string[]} Staged field names.
		 */
		fieldNames() {
			if (!this.staged) {
				return []
			}
			return this.staged.fields.map((f) => f.name).filter((name) => !!name)
		},
		/**
		 * Group ids already referenced by the Application's `permissions`
		 * block, seeding the AccessEditor group picker without a new
		 * full-group-directory endpoint (design.md Decision 2).
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-001
		 * @return {string[]} Deduplicated group ids.
		 */
		availableGroups() {
			const perms = (this.applicationRecord && this.applicationRecord.permissions) || {}
			const all = [
				...(Array.isArray(perms.owners) ? perms.owners : []),
				...(Array.isArray(perms.editors) ? perms.editors : []),
				...(Array.isArray(perms.viewers) ? perms.viewers : []),
			]
			const groups = all.filter((p) => typeof p === 'string' && !p.startsWith('user:'))
			return [...new Set(groups)]
		},
		/**
		 * Whether the caller is a Nextcloud admin (bypasses OR enforcement,
		 * so admins are never subject to the author lock-out warning).
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-004
		 * @return {boolean}
		 */
		isNcAdmin() {
			return !!(typeof OC !== 'undefined' && OC.isUserAdmin && OC.isUserAdmin())
		},
		/**
		 * Whether the active ApplicationVersion is the Application's
		 * `productionVersion`.
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-007
		 * @return {boolean}
		 */
		isProductionVersion() {
			if (!this.applicationVersion || !this.applicationRecord) {
				return false
			}
			const pv = this.applicationRecord.productionVersion
			const productionUuid = typeof pv === 'string' ? pv : ((pv && (pv.uuid || pv.id)) || null)
			return !!productionUuid && productionUuid === this.applicationVersion.uuid
		},
		/**
		 * Gate the Access sub-editor read-only on the production version
		 * for editors — owners and NC admins retain edit access
		 * (REQ-OBDSA-007). Mirrors the owner-only release rule
		 * (REQ-OBRBAC-004 / `ApplicationVersionOwnerGuard`); the
		 * authoritative write gate remains OR's register manage-permission
		 * plus the publish guard, this is a consistency surface only.
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-007
		 * @return {boolean}
		 */
		accessReadOnly() {
			if (!this.isProductionVersion) {
				return false
			}
			return useRole(this.applicationRecord) === 'editor'
		},
		/**
		 * Advisory warning: the staged `read` scope is group-based, the
		 * groups do not intersect the caller's own groups, and the caller
		 * is not an NC admin — saving would hide this schema's own records
		 * from the author. Save stays enabled regardless (REQ-OBDSA-004).
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-004
		 * @return {boolean}
		 */
		authorLockedOut() {
			if (!this.staged || !this.staged.access || this.isNcAdmin) {
				return false
			}
			const readRow = this.staged.access.rows.find((r) => r.op === 'read')
			if (!readRow || readRow.kind !== 'group') {
				return false
			}
			const groups = Array.isArray(readRow.groups) ? readRow.groups : []
			if (groups.length === 0) {
				return false
			}
			const userGroups = getCurrentUserGroups()
			return !groups.some((g) => userGroups.includes(g))
		},
		/**
		 * Validate that exactly one initial lifecycle state is set.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {boolean} True when valid (or no lifecycle states).
		 */
		hasInitialLifecycleState() {
			if (!this.hasLifecycleStates) {
				return true
			}
			return this.staged.states.filter((s) => s.initial).length === 1
		},
		/**
		 * Validate that all staged field names are present and unique.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {boolean} True when unique.
		 */
		fieldNamesUnique() {
			if (!this.staged) {
				return true
			}
			const seen = new Set()
			for (const field of this.staged.fields) {
				if (!field.name) {
					return false
				}
				if (seen.has(field.name)) {
					return false
				}
				seen.add(field.name)
			}
			return true
		},
		/**
		 * Gate Save on dirty-state plus all validation gates.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {boolean} True when the staged schema may be saved.
		 */
		canSave() {
			if (!this.staged || this.saving) {
				return false
			}
			if (!this.hasInitialLifecycleState) {
				return false
			}
			if (!this.fieldNamesUnique) {
				return false
			}
			// Widget editor JSON parse errors block Save.
			if (this.staged.widgets.some((w) => w.configError)) {
				return false
			}
			return this.hasStagedChanges
		},
		/**
		 * Detect whether the staged body differs from the persisted one.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {boolean} True when there are unsaved changes.
		 */
		hasStagedChanges() {
			if (!this.staged || !this.persisted) {
				return false
			}
			return JSON.stringify(this.composeSchemaBody(this.staged))
				!== JSON.stringify(this.persisted)
		},
		/**
		 * REQ-BUR-002 / REQ-BUR-005: whether an earlier staged state exists.
		 *
		 * @return {boolean}
		 */
		canUndo() {
			return !!(this.history && this.history.canUndo.value)
		},
		/**
		 * REQ-BUR-002 / REQ-BUR-005: whether an undone staged state exists.
		 *
		 * @return {boolean}
		 */
		canRedo() {
			return !!(this.history && this.history.canRedo.value)
		},
	},
	watch: {
		schemaId: {
			/**
			 * Reload schema detail when the route schema id changes.
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
			 * @return {void}
			 */
			handler() {
				// New schemaId — the previous attempt says nothing about this one,
				// so fall back to the spinner rather than briefly showing the old
				// state or a false "Schema not found".
				this.detailAttempted = false
				this.loadDetail()
			},
		},
		appSlug: {
			/**
			 * Re-resolve version and refresh the list when the app changes.
			 * REQ-BUR-004: an app switch is a session boundary — reset the
			 * undo/redo history (design.md D3/D5).
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
			 * @spec openspec/changes/builder-undo-redo/specs/builder-undo-redo/spec.md#req-bur-005
			 * @return {void}
			 */
			handler() {
				this.resolveVersion()
				this.refreshList()
				if (this.history) {
					this.history.reset(this.staged)
				}
			},
		},
		versionSlug: {
			/**
			 * Re-resolve version and refresh the list when the version changes.
			 * REQ-BUR-004: a version switch is a session boundary — reset the
			 * undo/redo history (design.md D3/D5).
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
			 * @spec openspec/changes/builder-undo-redo/specs/builder-undo-redo/spec.md#req-bur-005
			 * @return {void}
			 */
			handler() {
				this.resolveVersion()
				this.refreshList()
				if (this.history) {
					this.history.reset(this.staged)
				}
			},
		},
	},
	/**
	 * On mount: resolve version, load the list, and load detail if a schema
	 * is selected in the route.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
	 * @return {Promise<void>}
	 */
	async mounted() {
		// REQ-OBVR-004: resolve the active ApplicationVersion via useApplicationVersion.
		this.resolveVersion()
		this.loadApplicationRecord()
		await this.refreshList()
		if (this.schemaId) {
			await this.loadDetail()
		}
		// REQ-BUR-003/-005: document-level undo/redo shortcuts. `onKeydown`
		// itself no-ops outside detail mode (no staged model to act on), so
		// the listener is safe to keep attached across list/detail navigation
		// on this same component instance.
		document.addEventListener('keydown', this.onKeydown)
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.onKeydown)
	},
	methods: {
		/**
		 * Resolve the caller's Application record (from the "my applications"
		 * list) so the "Import data" affordance can be gated by the caller's
		 * build/manage role. The list only returns apps the caller has a role
		 * on; `useRole` then derives owner/editor/viewer from its permissions.
		 *
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 * @return {Promise<void>}
		 */
		async loadApplicationRecord() {
			try {
				const url = generateUrl('/apps/openbuild/api/applications')
				const { data } = await axios.get(url, { headers: { 'OCS-APIREQUEST': 'true' } })
				const list = (data && (data.results || data)) || []
				const apps = Array.isArray(list) ? list : []
				this.applicationRecord = apps.find((a) => a && a.slug === this.appSlug) || null
			} catch (e) {
				this.applicationRecord = null
			}
		},
		/**
		 * Refresh the schema list after a successful import (a create-from-file
		 * import may have added a new schema to the register).
		 *
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.2
		 * @return {Promise<void>}
		 */
		async onSchemaImported() {
			await this.refreshList()
		},
		/**
		 * Resolve the active ApplicationVersion via useApplicationVersion composable
		 * (REQ-OBVR-004 / REQ-OBVR-005). Called on mount and when appSlug / versionSlug
		 * change. Wires the reactive version state into component data so the template
		 * and store can read it.
		 *
		 * NOTE: we do NOT call $router.replace() here — that would strip ?_version=
		 * and break bookmarkability (REQ-OBVR-008). We just read what the URL contains.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {void}
		 */
		resolveVersion() {
			const { applicationVersion, loading, error } = useApplicationVersion(
				this.appSlug,
				this.versionSlug,
			)
			// Watch the reactive refs and mirror them into component data.
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			this.versionError = error.value
			// Set up watchers to keep component data in sync as the fetch resolves.
			const unwatch = this.$watch(() => applicationVersion.value, (v) => {
				this.applicationVersion = v
			})
			const unwatchLoading = this.$watch(() => loading.value, (v) => {
				this.versionLoading = v
				if (!v) {
					// Fetch complete — clean up watchers to avoid leaks.
					unwatch()
					unwatchLoading()
					this.versionError = error.value
				}
			})
		},
		/**
		 * Fetch the schema collection and filter to this app/version register.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {Promise<void>}
		 */
		async refreshList() {
			this.loadingList = true
			try {
				const results = await this.store.fetchCollection(SCHEMA_TYPE)
				const all = Array.isArray(results) ? results : []
				// OR's schemas endpoint returns every schema in the
				// organisation. Filter to the namespaced subset that
				// belongs to this app+version register so the designer
				// only shows the user's relevant schemas. Per the wizard
				// (issue #71) seed slugs are `{appSlug}-{versionSlug}-X`.
				const prefix = this.versionSlug
					? `${this.appSlug}-${this.versionSlug}-`
					: `${this.appSlug}-`
				this.schemas = all.filter((s) => {
					const slug = s.slug || (s['@self'] && s['@self'].slug) || ''
					return typeof slug === 'string' && slug.startsWith(prefix)
				})
				const err = this.store.errors[SCHEMA_TYPE]
				if (err) {
					showError(this.t('openbuild', 'Failed to load schemas: {error}', { error: err }))
				}
			} catch (e) {
				this.schemas = []
				showError(this.t('openbuild', 'Failed to load schemas: {error}', { error: this.errorMessage(e) }))
			} finally {
				this.loadingList = false
			}
		},
		/**
		 * Load a single schema's detail and stage it for editing.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {Promise<void>}
		 */
		async loadDetail() {
			if (!this.schemaId) {
				this.staged = null
				this.persisted = null
				// REQ-BUR-005: no schema selected — reset to an empty session.
				if (this.history) {
					this.history.reset(null)
				}
				return
			}
			this.loadingDetail = true
			this.saveError = ''
			try {
				const data = await this.store.fetchObject(SCHEMA_TYPE, this.schemaId)
				if (!data) {
					this.staged = null
					this.persisted = null
					const err = this.store.errors[SCHEMA_TYPE]
					if (err) {
						showError(this.t('openbuild', 'Failed to load schema: {error}', { error: err }))
					}
					return
				}
				this.persisted = data
				this.staged = this.bodyToStaged(data)
			} catch (e) {
				this.staged = null
				this.persisted = null
				showError(this.t('openbuild', 'Failed to load schema: {error}', { error: this.errorMessage(e) }))
			} finally {
				this.loadingDetail = false
				// The load has now been attempted for this schemaId — from here on
				// a null `staged` genuinely means "not found" and the empty state
				// may render.
				this.detailAttempted = true
				// REQ-BUR-005: a `schemaId` route change is a session boundary —
				// re-baseline the undo/redo history to the freshly staged model
				// (or `null` on a load failure / not-found) regardless of which
				// branch above ran.
				if (this.history) {
					this.history.reset(this.staged)
				}
			}
		},
		/**
		 * Convert a persisted schema body into the staged editor model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} body Persisted schema body.
		 * @return {object} Staged editor model.
		 */
		bodyToStaged(body) {
			const fields = schemaToFields(body)
			const lifecycle = body['x-openregister-lifecycle']
			const { states, transitions } = lifecycleToEditor(lifecycle)
			return {
				slug: body.slug || (body['@self'] && body['@self'].slug) || this.schemaId,
				title: body.title || '',
				description: body.description || '',
				version: body.version || '0.1.0',
				fields,
				states,
				transitions,
				relations: relationsToEditor(body['x-openregister-relations']),
				// REQ-OBDSA-002: preserve the raw persisted `authorization` block
				// alongside the parsed editor rows so composeSchemaBody() can
				// merge edits back over it losslessly — this is also the fix
				// for the pre-existing strip-on-save bug (composeSchemaBody()
				// used to never carry `authorization` through at all).
				access: accessToEditor(body.authorization),
				rawAuthorization: body.authorization || null,
				widgets: widgetsToEditor(body['x-openregister-widgets']),
				aggregations: body['x-openregister-aggregations'] || null,
				calculations: body['x-openregister-calculations'] || null,
				notifications: body['x-openregister-notifications'] || null,
			}
		},
		/**
		 * Compose a canonical schema body from the staged editor model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} staged Staged editor model.
		 * @return {object} Canonical schema body.
		 */
		composeSchemaBody(staged) {
			const { properties, required, order } = fieldsToSchema(staged.fields)
			const body = {
				slug: staged.slug,
				title: staged.title,
				description: staged.description || '',
				version: staged.version,
				type: 'object',
				properties,
				...(required.length > 0 ? { required } : {}),
				...(order.length > 0 ? { 'x-property-order': order } : {}),
			}
			const lifecycle = editorToLifecycle(staged.states, staged.transitions)
			if (lifecycle) {
				body['x-openregister-lifecycle'] = lifecycle
			}
			const relations = editorToRelations(staged.relations)
			if (relations) {
				body['x-openregister-relations'] = relations
			}
			// REQ-OBDSA-002: compile the Access sub-editor rows back over the
			// preserved raw block so a Save never strips or reorders an
			// `authorization` block set outside the designer (fixes the
			// pre-existing strip bug — this used to be entirely absent).
			const authorization = editorToAccess(staged.access, staged.rawAuthorization)
			if (authorization) {
				body.authorization = authorization
			}
			const widgets = editorToWidgets(staged.widgets)
			if (widgets) {
				body['x-openregister-widgets'] = widgets
			}
			// v1.1 stubs pass through any pre-existing block unchanged.
			if (staged.aggregations) {
				body['x-openregister-aggregations'] = staged.aggregations
			}
			if (staged.calculations) {
				body['x-openregister-calculations'] = staged.calculations
			}
			if (staged.notifications) {
				body['x-openregister-notifications'] = staged.notifications
			}
			return body
		},
		/**
		 * REQ-BUR-005 (design.md D5): the single commit point every staged
		 * mutation — and `discardChanges()` — routes through. Replaces
		 * `this.staged` and pushes the new snapshot onto the undo/redo
		 * history in one step, so a discard is recorded as exactly one
		 * history entry alongside every other staged edit.
		 *
		 * @param {object} next The new staged editor model.
		 * @return {void}
		 */
		commitStaged(next) {
			this.staged = next
			if (this.history) {
				this.history.push(next)
			}
		},
		/**
		 * Apply a header-form change into the staged model (slug locked).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} value Header values.
		 * @return {void}
		 */
		onHeaderChange(value) {
			this.commitStaged({
				...this.staged,
				title: value.title,
				description: value.description,
				version: value.version,
				// slug is locked on detail view
			})
		},
		/**
		 * Apply a fields-editor change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} fields Updated fields.
		 * @return {void}
		 */
		onFieldsChange(fields) {
			this.commitStaged({ ...this.staged, fields })
		},
		/**
		 * Apply a states change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} states Updated states.
		 * @return {void}
		 */
		onStatesChange(states) {
			this.commitStaged({ ...this.staged, states })
		},
		/**
		 * Apply a transitions change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} transitions Updated transitions.
		 * @return {void}
		 */
		onTransitionsChange(transitions) {
			this.commitStaged({ ...this.staged, transitions })
		},
		/**
		 * Apply a relations change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} relations Updated relations.
		 * @return {void}
		 */
		onRelationsChange(relations) {
			this.commitStaged({ ...this.staged, relations })
		},
		/**
		 * Apply an Access sub-editor change into the staged model. Routed
		 * through `commitStaged` (builder-undo-redo) like every other
		 * staged mutation, even though AccessEditor postdates the original
		 * design.md D5 list — data-scopes-authoring landed after that spec
		 * was written, and an access-scope edit is a staged-model mutation
		 * like any other; leaving it out of undo/redo would be an
		 * inconsistent gap in the same feature.
		 *
		 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-001
		 * @param {object} access Updated access editor model ({ rows, extraKeys }).
		 * @return {void}
		 */
		onAccessChange(access) {
			this.commitStaged({ ...this.staged, access })
		},
		/**
		 * Apply a widgets change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} widgets Updated widgets.
		 * @return {void}
		 */
		onWidgetsChange(widgets) {
			this.commitStaged({ ...this.staged, widgets })
		},
		/**
		 * Namespace a user-typed schema slug to this app+version, matching the
		 * convention the creation wizard seeds with (`{appSlug}-{versionSlug}-X`,
		 * or `{appSlug}-X` on the legacy no-version register) and which
		 * refreshList() filters the global schema collection by. Already-prefixed
		 * input is returned unchanged so re-entering a full slug is idempotent.
		 *
		 * @param {string} slug The user-typed slug.
		 * @return {string} The namespaced slug.
		 */
		namespacedSlug(slug) {
			const raw = String(slug || '').trim()
			const prefix = this.versionSlug
				? `${this.appSlug}-${this.versionSlug}-`
				: `${this.appSlug}-`
			if (raw === '' || raw.startsWith(prefix)) {
				return raw
			}
			return `${prefix}${raw}`
		},
		/**
		 * Attach a freshly created schema to this app+version's OpenRegister
		 * register, so it is owned by the app rather than floating in the
		 * organisation. OpenRegister exposes the register-scoped schema route
		 * read-only (POST is 405), so association is done by PATCHing the
		 * register's `schemas` array — the same shape the creation wizard writes.
		 *
		 * @param {object} data The created schema as returned by the store.
		 * @return {Promise<void>}
		 */
		async attachSchemaToRegister(data) {
			const schemaId = (data && (data.id || (data['@self'] && data['@self'].id))) || null
			if (schemaId === null) {
				return
			}
			const register = this.importRegisterId
			try {
				// OpenRegister resolves GET /api/registers/{id} by slug, but its
				// PATCH counterpart resolves ONLY by the numeric id (slug and uuid
				// both 404), so read the register by slug first and PATCH by id.
				const readUrl = generateUrl(`/apps/openregister/api/registers/${encodeURIComponent(register)}`)
				const { data: current } = await axios.get(readUrl)
				const existing = Array.isArray(current && current.schemas) ? current.schemas : []
				// Compare as strings — the array mixes numeric ids and uuids.
				if (existing.some((id) => String(id) === String(schemaId))) {
					return
				}
				const numericId = current && current.id
				if (numericId === undefined || numericId === null) {
					throw new Error('register has no id')
				}
				const writeUrl = generateUrl(`/apps/openregister/api/registers/${encodeURIComponent(numericId)}`)
				await axios.patch(writeUrl, { schemas: [...existing, schemaId] })
			} catch (e) {
				// Non-fatal: the schema exists and is editable; it just is not
				// listed on the register yet. Surface it so the builder knows.
				showError(this.t(
					'openbuild',
					'Schema created, but could not be attached to register {register}: {error}',
					{ register, error: this.errorMessage(e) },
				))
			}
		},
		/**
		 * Create a new schema via the store, surfacing duplicate-slug errors.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} payload New schema payload.
		 * @return {Promise<void>}
		 */
		async addSchema(payload) {
			// The designer's list is the global schema collection filtered to the
			// slugs owned by this app+version (see refreshList()'s `prefix`), and
			// OpenRegister only exposes the register-scoped schema route for
			// reading (GET /api/registers/{register}/schemas — POST there is 405).
			// So a schema created with the raw user-typed slug was invisible in
			// the list it was created from AND unattached to the app's register,
			// leaving the follow-on detail navigation on "Schema not found"
			// (openbuild#41). Namespace the slug to the same convention the
			// wizard uses, then attach the new schema to the app's register.
			const body = {
				slug: this.namespacedSlug(payload.slug),
				title: payload.title,
				description: payload.description || '',
				version: payload.version,
				type: 'object',
				properties: {},
			}
			// No `id` field on the payload — store treats this as a POST.
			const data = await this.store.saveObject(SCHEMA_TYPE, body)
			if (!data) {
				const err = this.store.errors[SCHEMA_TYPE] || this.t('openbuild', 'Unknown error')
				// Surface duplicate-slug specifically so the AddSchemaDialog
				// can render an inline field error per REQ-OBSD-002.
				if (typeof err === 'string' && /409|already exists|duplicate/i.test(err)) {
					const duplicate = new Error('duplicate slug')
					duplicate.status = 409
					throw duplicate
				}
				throw new Error(typeof err === 'string' ? err : this.t('openbuild', 'Failed to create schema'))
			}
			const newSlug = (data && (data.slug || (data['@self'] && data['@self'].slug))) || body.slug
			await this.attachSchemaToRegister(data)
			await this.refreshList()
			// REQ-OBVR-006: use buildVersionedRoute to forward ?_version= on navigation.
			this.$router.push(buildVersionedRoute(
				'SchemaDesigner',
				{ slug: this.appSlug, schemaId: newSlug },
				this.versionSlug,
			))
			showSuccess(this.t('openbuild', 'Schema {slug} created.', { slug: newSlug }))
		},
		/**
		 * Navigate to a schema's detail, preserving ?_version=.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {string} slug Schema slug.
		 * @return {void}
		 */
		openSchema(slug) {
			// REQ-OBVR-006: use buildVersionedRoute to forward ?_version= on navigation.
			this.$router.push(buildVersionedRoute(
				'SchemaDesigner',
				{ slug: this.appSlug, schemaId: slug },
				this.versionSlug,
			))
		},
		/**
		 * Navigate back to the schema list, preserving ?_version=.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {void}
		 */
		goToList() {
			// REQ-OBVR-006: use buildVersionedRoute to forward ?_version= on navigation.
			this.$router.push(buildVersionedRoute(
				'SchemaDesignerList',
				{ slug: this.appSlug },
				this.versionSlug,
			))
		},
		/**
		 * Delete a schema via the store and refresh the list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {string} slug Schema slug.
		 * @return {Promise<void>}
		 */
		async deleteSchema(slug) {
			const ok = await this.store.deleteObject(SCHEMA_TYPE, slug)
			if (!ok) {
				const err = this.store.errors[SCHEMA_TYPE]
				showError(this.t('openbuild', 'Failed to delete schema: {error}', { error: err || '' }))
				return
			}
			await this.refreshList()
			showSuccess(this.t('openbuild', 'Schema {slug} deleted.', { slug }))
			if (this.schemaId === slug) {
				// goToList uses buildVersionedRoute internally — ?_version= is preserved.
				this.goToList()
			}
		},
		/**
		 * Persist the composed schema body via the store (PUT on existing).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.staged || this.saving) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const body = this.composeSchemaBody(this.staged)
				// `saveObject` switches to PUT when `id` is present.
				// We piggyback the current `schemaId` as `id` so the
				// store's `_buildUrl` puts it on the URL tail.
				const data = await this.store.saveObject(SCHEMA_TYPE, { ...body, id: this.schemaId })
				if (!data) {
					const err = this.store.errors[SCHEMA_TYPE]
					this.saveError = typeof err === 'string'
						? err
						: this.t('openbuild', 'Failed to save schema')
					return
				}
				this.persisted = data
				this.staged = this.bodyToStaged(data)
				// REQ-BUR-005: a successful save is a session boundary —
				// re-baseline the undo/redo history to the freshly saved
				// staged model, disabling both Undo and Redo.
				if (this.history) {
					this.history.reset(this.staged)
				}
				showSuccess(this.t('openbuild', 'Schema saved.'))
			} catch (e) {
				this.saveError = this.errorMessage(e)
			} finally {
				this.saving = false
			}
		},
		/**
		 * Revert staged edits back to the persisted body. Routed through
		 * `commitStaged` (REQ-BUR-005 / design.md D5) so the discard itself
		 * is recorded as exactly one history entry — a single undo brings
		 * the discarded staged edits back.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @spec openspec/changes/builder-undo-redo/specs/builder-undo-redo/spec.md#req-bur-005
		 * @return {void}
		 */
		discardChanges() {
			if (this.persisted) {
				this.commitStaged(this.bodyToStaged(this.persisted))
				this.saveError = ''
			}
		},
		/**
		 * REQ-BUR-005: step back one staged state (or no-op at the bottom).
		 *
		 * @return {void}
		 */
		undo() {
			if (!this.history) {
				return
			}
			const prev = this.history.undo()
			if (prev !== null) {
				this.staged = prev
			}
		},
		/**
		 * REQ-BUR-005: step forward one staged state (or no-op at the top).
		 *
		 * @return {void}
		 */
		redo() {
			if (!this.history) {
				return
			}
			const next = this.history.redo()
			if (next !== null) {
				this.staged = next
			}
		},
		/**
		 * REQ-BUR-003: document-level Undo/Redo shortcut handler, mirroring
		 * `PageDesigner.vue`'s guard (design.md D4). No-ops outside detail
		 * mode (no staged model to act on) and inside editable fields.
		 *
		 * @param {KeyboardEvent} event - the keydown event.
		 * @return {void}
		 * @spec openspec/changes/builder-undo-redo/specs/builder-undo-redo/spec.md#req-bur-003
		 */
		onKeydown(event) {
			if (!this.schemaId || !this.staged) {
				return
			}
			if (!event || !(event.ctrlKey || event.metaKey)) {
				return
			}
			if (isEditableTarget(event)) {
				return
			}
			const key = (event.key || '').toLowerCase()
			if (key === 'z' && !event.shiftKey) {
				event.preventDefault()
				this.undo()
			} else if ((key === 'z' && event.shiftKey) || key === 'y') {
				event.preventDefault()
				this.redo()
			}
		},
		/**
		 * Extract a human-readable message from an error/response.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {*} e Error.
		 * @return {string} Message.
		 */
		errorMessage(e) {
			if (!e) {
				return ''
			}
			if (e.response && e.response.data) {
				if (typeof e.response.data === 'string') {
					return e.response.data
				}
				if (e.response.data.message) {
					return e.response.data.message
				}
				if (e.response.data.error) {
					return e.response.data.error
				}
			}
			return e.message || String(e)
		},
	},
}
</script>

<style scoped>
.openbuild-schema-designer {
	padding: 16px;
	max-width: 1400px;
}

.openbuild-schema-designer__detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.openbuild-schema-designer__detail-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.openbuild-schema-designer__detail-header h2 {
	margin: 8px 0 0;
	font-size: 22px;
	font-weight: 600;
}

.openbuild-schema-designer__detail-actions {
	display: flex;
	gap: 8px;
}

.openbuild-schema-designer__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.openbuild-schema-designer__toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}
</style>
