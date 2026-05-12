<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - SchemaDesigner — the top-level view for the OpenBuilt schema
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
  - `openbuilt-{slug}` per the hybrid register model: system schemas
  - live in shared `openbuilt`, user-authored schemas live per-app.
  -->
<template>
	<div class="openbuilt-schema-designer">
		<!-- List mode -->
		<SchemaListPanel
			v-if="!schemaId"
			:schemas="schemas"
			:loading="loadingList"
			@add="addSchema"
			@open="openSchema"
			@delete="deleteSchema" />

		<!-- Detail mode -->
		<div v-else class="openbuilt-schema-designer__detail">
			<header class="openbuilt-schema-designer__detail-header">
				<div>
					<NcButton type="tertiary" @click="goToList">
						<template #icon>
							<ArrowLeftIcon :size="20" />
						</template>
						{{ t('openbuilt', 'Back to schemas') }}
					</NcButton>
					<h2 v-if="staged">
						{{ staged.title || schemaId }}
					</h2>
				</div>
				<div class="openbuilt-schema-designer__detail-actions">
					<NcButton :disabled="!hasStagedChanges || saving" @click="discardChanges">
						{{ t('openbuilt', 'Discard staged edits') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!canSave"
						@click="save">
						{{ saving ? t('openbuilt', 'Saving...') : t('openbuilt', 'Save') }}
					</NcButton>
				</div>
			</header>

			<div v-if="loadingDetail" class="openbuilt-schema-designer__loading">
				<NcLoadingIcon :size="32" />
			</div>

			<template v-else-if="staged">
				<NcNoteCard v-if="saveError" type="error">
					{{ saveError }}
				</NcNoteCard>

				<NcNoteCard v-if="!hasInitialLifecycleState && hasLifecycleStates" type="warning">
					{{ t('openbuilt', 'Exactly one lifecycle state must be marked as initial before you can save.') }}
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
				:name="t('openbuilt', 'Schema not found')"
				:description="t('openbuilt', 'No schema with this slug exists in the current virtual app.')">
				<template #action>
					<NcButton @click="goToList">
						{{ t('openbuilt', 'Back to schemas') }}
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
		}
	},
	computed: {
		appSlug() {
			return this.$route.params.slug
		},
		schemaId() {
			return this.$route.params.schemaId || ''
		},
		store() {
			// Re-creates the binding when appSlug changes; the store
			// factory re-registers the `schema` type to the per-app
			// register `openbuilt-{slug}` on every call (idempotent).
			return useSchemasStore(this.appSlug)
		},
		otherSchemaSlugs() {
			return this.schemas
				.map((s) => s.slug || (s['@self'] && s['@self'].slug) || s.id)
				.filter((slug) => slug && slug !== this.schemaId)
		},
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
		hasInitialLifecycleState() {
			if (!this.hasLifecycleStates) {
				return true
			}
			return this.staged.states.filter((s) => s.initial).length === 1
		},
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
			handler() {
				this.loadDetail()
			},
		},
		appSlug: {
			handler() {
				this.refreshList()
			},
		},
	},
	async mounted() {
		await this.refreshList()
		if (this.schemaId) {
			await this.loadDetail()
		}
	},
	methods: {
		async refreshList() {
			this.loadingList = true
			try {
				const results = await this.store.fetchCollection(SCHEMA_TYPE)
				this.schemas = Array.isArray(results) ? results : []
				const err = this.store.errors[SCHEMA_TYPE]
				if (err) {
					showError(this.t('openbuilt', 'Failed to load schemas: {error}', { error: err }))
				}
			} catch (e) {
				this.schemas = []
				showError(this.t('openbuilt', 'Failed to load schemas: {error}', { error: this.errorMessage(e) }))
			} finally {
				this.loadingList = false
			}
		},
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
						showError(this.t('openbuilt', 'Failed to load schema: {error}', { error: err }))
					}
					return
				}
				this.persisted = data
				this.staged = this.bodyToStaged(data)
			} catch (e) {
				this.staged = null
				this.persisted = null
				showError(this.t('openbuilt', 'Failed to load schema: {error}', { error: this.errorMessage(e) }))
			} finally {
				this.loadingDetail = false
			}
		},
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
		onHeaderChange(value) {
			this.staged = {
				...this.staged,
				title: value.title,
				description: value.description,
				version: value.version,
				// slug is locked on detail view
			}
		},
		onFieldsChange(fields) {
			this.staged = { ...this.staged, fields }
		},
		onStatesChange(states) {
			this.staged = { ...this.staged, states }
		},
		onTransitionsChange(transitions) {
			this.staged = { ...this.staged, transitions }
		},
		onRelationsChange(relations) {
			this.staged = { ...this.staged, relations }
		},
		onWidgetsChange(widgets) {
			this.staged = { ...this.staged, widgets }
		},
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
				const err = this.store.errors[SCHEMA_TYPE] || this.t('openbuilt', 'Unknown error')
				// Surface duplicate-slug specifically so the AddSchemaDialog
				// can render an inline field error per REQ-OBSD-002.
				if (typeof err === 'string' && /409|already exists|duplicate/i.test(err)) {
					const duplicate = new Error('duplicate slug')
					duplicate.status = 409
					throw duplicate
				}
				throw new Error(typeof err === 'string' ? err : this.t('openbuilt', 'Failed to create schema'))
			}
			const newSlug = (data && (data.slug || (data['@self'] && data['@self'].slug))) || payload.slug
			await this.refreshList()
			this.$router.push({
				name: 'SchemaDesigner',
				params: { slug: this.appSlug, schemaId: newSlug },
			})
			showSuccess(this.t('openbuilt', 'Schema {slug} created.', { slug: newSlug }))
		},
		openSchema(slug) {
			this.$router.push({
				name: 'SchemaDesigner',
				params: { slug: this.appSlug, schemaId: slug },
			})
		},
		goToList() {
			this.$router.push({
				name: 'SchemaDesignerList',
				params: { slug: this.appSlug },
			})
		},
		async deleteSchema(slug) {
			const ok = await this.store.deleteObject(SCHEMA_TYPE, slug)
			if (!ok) {
				const err = this.store.errors[SCHEMA_TYPE]
				showError(this.t('openbuilt', 'Failed to delete schema: {error}', { error: err || '' }))
				return
			}
			await this.refreshList()
			showSuccess(this.t('openbuilt', 'Schema {slug} deleted.', { slug }))
			if (this.schemaId === slug) {
				this.goToList()
			}
		},
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
						: this.t('openbuilt', 'Failed to save schema')
					return
				}
				this.persisted = data
				this.staged = this.bodyToStaged(data)
				showSuccess(this.t('openbuilt', 'Schema saved.'))
			} catch (e) {
				this.saveError = this.errorMessage(e)
			} finally {
				this.saving = false
			}
		},
		discardChanges() {
			if (this.persisted) {
				this.staged = this.bodyToStaged(this.persisted)
				this.saveError = ''
			}
		},
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
.openbuilt-schema-designer {
	padding: 16px;
	max-width: 1400px;
}

.openbuilt-schema-designer__detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.openbuilt-schema-designer__detail-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.openbuilt-schema-designer__detail-header h2 {
	margin: 8px 0 0;
	font-size: 22px;
	font-weight: 600;
}

.openbuilt-schema-designer__detail-actions {
	display: flex;
	gap: 8px;
}

.openbuilt-schema-designer__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}
</style>
