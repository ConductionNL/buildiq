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
		<SchemaListPanel
			v-if="!schemaId"
			:schemas="schemas"
			:loading="loadingList"
			@add="addSchema"
			@open="openSchema"
			@delete="deleteSchema" />

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

			<div v-if="loadingDetail" class="openbuild-schema-designer__loading">
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
				:description="t('openbuild', 'No schema with this slug exists in the current virtual app.')">
				<template #action>
					<NcButton @click="goToList">
						{{ t('openbuild', 'Back to schemas') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'

import SchemaListPanel from '../components/schema-editor/SchemaListPanel.vue'
import SchemaHeaderForm from '../components/schema-editor/SchemaHeaderForm.vue'
import FieldEditor, { fieldsToSchema, schemaToFields } from '../components/schema-editor/FieldEditor.vue'
import LifecycleEditor, { editorToLifecycle, lifecycleToEditor } from '../components/schema-editor/LifecycleEditor.vue'
import RelationEditor, { editorToRelations, relationsToEditor } from '../components/schema-editor/RelationEditor.vue'
import WidgetEditor, { editorToWidgets, widgetsToEditor } from '../components/schema-editor/WidgetEditor.vue'
import AggregationEditor from '../components/schema-editor/AggregationEditor.vue'
import CalculationEditor from '../components/schema-editor/CalculationEditor.vue'
import NotificationEditor from '../components/schema-editor/NotificationEditor.vue'

import { useSchemasStore } from '../store/schemas.js'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import { buildVersionedRoute } from '../router/helpers.js'

/**
 * Schema object type slug as registered with the store factory.
 * See `src/store/schemas.js` for the URL shape.
 */
const SCHEMA_TYPE = 'schema'

export default {
	name: 'SchemaDesigner',
	components: {
		AggregationEditor,
		ArrowLeftIcon,
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
	},
	data() {
		return {
			schemas: [],
			loadingList: false,
			loadingDetail: false,
			saving: false,
			saveError: '',
			staged: null,
			persisted: null,
			// REQ-OBVR-004: reactive version state resolved by useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
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
				this.loadDetail()
			},
		},
		appSlug: {
			/**
			 * Re-resolve version and refresh the list when the app changes.
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
			 * @return {void}
			 */
			handler() {
				this.resolveVersion()
				this.refreshList()
			},
		},
		versionSlug: {
			/**
			 * Re-resolve version and refresh the list when the version changes.
			 *
			 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
			 * @return {void}
			 */
			handler() {
				this.resolveVersion()
				this.refreshList()
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
		await this.refreshList()
		if (this.schemaId) {
			await this.loadDetail()
		}
	},
	methods: {
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
		 * Apply a header-form change into the staged model (slug locked).
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} value Header values.
		 * @return {void}
		 */
		onHeaderChange(value) {
			this.staged = {
				...this.staged,
				title: value.title,
				description: value.description,
				version: value.version,
				// slug is locked on detail view
			}
		},
		/**
		 * Apply a fields-editor change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} fields Updated fields.
		 * @return {void}
		 */
		onFieldsChange(fields) {
			this.staged = { ...this.staged, fields }
		},
		/**
		 * Apply a states change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} states Updated states.
		 * @return {void}
		 */
		onStatesChange(states) {
			this.staged = { ...this.staged, states }
		},
		/**
		 * Apply a transitions change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} transitions Updated transitions.
		 * @return {void}
		 */
		onTransitionsChange(transitions) {
			this.staged = { ...this.staged, transitions }
		},
		/**
		 * Apply a relations change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} relations Updated relations.
		 * @return {void}
		 */
		onRelationsChange(relations) {
			this.staged = { ...this.staged, relations }
		},
		/**
		 * Apply a widgets change into the staged model.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {Array} widgets Updated widgets.
		 * @return {void}
		 */
		onWidgetsChange(widgets) {
			this.staged = { ...this.staged, widgets }
		},
		/**
		 * Create a new schema via the store, surfacing duplicate-slug errors.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @param {object} payload New schema payload.
		 * @return {Promise<void>}
		 */
		async addSchema(payload) {
			const body = {
				slug: payload.slug,
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
			const newSlug = (data && (data.slug || (data['@self'] && data['@self'].slug))) || payload.slug
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
				showSuccess(this.t('openbuild', 'Schema saved.'))
			} catch (e) {
				this.saveError = this.errorMessage(e)
			} finally {
				this.saving = false
			}
		},
		/**
		 * Revert staged edits back to the persisted body.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
		 * @return {void}
		 */
		discardChanges() {
			if (this.persisted) {
				this.staged = this.bodyToStaged(this.persisted)
				this.saveError = ''
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
</style>
