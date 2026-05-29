<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - SchemaListPanel — lists every schema in the current virtual app's
  - register namespace (REQ-OBSD-001). Renders slug, title, version,
  - property count, and lifecycle-state count. Owns the Add Schema and
  - per-row Open / Rename / Delete actions. Delete is gated by the
  - DeleteSchemaDialog modal (REQ-OBSD-008).
  -->
<template>
	<div class="openbuild-schema-list">
		<header class="openbuild-schema-list__header">
			<h2>{{ t('openbuild', 'Schemas') }}</h2>
			<NcButton type="primary" @click="addOpen = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuild', 'Add schema') }}
			</NcButton>
		</header>

		<div v-if="loading" class="openbuild-schema-list__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="schemas.length === 0" class="openbuild-schema-list__empty">
			<NcEmptyContent
				:name="t('openbuild', 'No schemas yet')"
				:description="t('openbuild', 'Add your first schema to start designing the data model for this app.')">
				<template #icon>
					<DatabaseIcon :size="64" />
				</template>
				<template #action>
					<NcButton type="primary" @click="addOpen = true">
						{{ t('openbuild', 'Add schema') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<ul v-else class="openbuild-schema-list__rows">
			<li
				v-for="schema in schemas"
				:key="getSlug(schema)"
				class="openbuild-schema-list__row">
				<button
					type="button"
					class="openbuild-schema-list__row-main"
					@click="onOpen(schema)">
					<span class="openbuild-schema-list__row-title">
						{{ schema.title || getSlug(schema) }}
					</span>
					<span class="openbuild-schema-list__row-meta">
						<code>{{ getSlug(schema) }}</code>
						<span>{{ t('openbuild', 'v{version}', { version: schema.version || '—' }) }}</span>
						<span>{{ n('openbuild', '{n} property', '{n} properties', propertyCount(schema), { n: propertyCount(schema) }) }}</span>
						<span>{{ lifecycleLabel(schema) }}</span>
					</span>
				</button>
				<NcActions>
					<NcActionButton @click="onOpen(schema)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('openbuild', 'Open') }}
					</NcActionButton>
					<NcActionButton @click="requestDelete(schema)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('openbuild', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<AddSchemaDialog
			:open="addOpen"
			:submitting="addSubmitting"
			:slug-error="addSlugError"
			@confirm="onAddConfirm"
			@cancel="addOpen = false"
			@update:open="addOpen = $event" />

		<DeleteSchemaDialog
			:open="deleteOpen"
			:schema-slug="pendingDeleteSlug"
			@confirm="onDeleteConfirm"
			@cancel="cancelDelete"
			@update:open="deleteOpen = $event" />
	</div>
</template>

<script>
import { NcActionButton, NcActions, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import DatabaseIcon from 'vue-material-design-icons/Database.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

import AddSchemaDialog from '../../modals/AddSchemaDialog.vue'
import DeleteSchemaDialog from '../../modals/DeleteSchemaDialog.vue'

export default {
	name: 'SchemaListPanel',
	components: {
		AddSchemaDialog,
		DatabaseIcon,
		DeleteIcon,
		DeleteSchemaDialog,
		NcActionButton,
		NcActions,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PencilIcon,
		PlusIcon,
	},
	props: {
		schemas: { type: Array, default: () => [] },
		loading: { type: Boolean, default: false },
	},
	emits: ['add', 'open', 'delete'],
	data() {
		return {
			addOpen: false,
			addSubmitting: false,
			addSlugError: '',
			deleteOpen: false,
			pendingDeleteSlug: '',
		}
	},
	methods: {
		getSlug(schema) {
			return schema.slug || (schema['@self'] && schema['@self'].slug) || schema.id || ''
		},
		/**
		 * Count the declared properties on a schema row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} schema Schema record.
		 * @return {number} Property count.
		 */
		propertyCount(schema) {
			if (!schema || !schema.properties) {
				return 0
			}
			return Object.keys(schema.properties).length
		},
		/**
		 * Build a human-readable lifecycle-state-count label.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} schema Schema record.
		 * @return {string} Lifecycle label.
		 */
		lifecycleLabel(schema) {
			const lifecycle = schema && schema['x-openregister-lifecycle']
			if (!lifecycle || !Array.isArray(lifecycle.states) || lifecycle.states.length === 0) {
				return this.t('openbuild', 'No lifecycle')
			}
			return this.n('openbuild', '{n} lifecycle state', '{n} lifecycle states', lifecycle.states.length, { n: lifecycle.states.length })
		},
		/**
		 * Emit an open event for the activated schema row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} schema Schema record.
		 * @return {void}
		 */
		onOpen(schema) {
			this.$emit('open', this.getSlug(schema))
		},
		/**
		 * Confirm the add-schema dialog: emit add and surface slug conflicts.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} payload New schema payload.
		 * @return {Promise<*>} The add result.
		 */
		async onAddConfirm(payload) {
			this.addSubmitting = true
			this.addSlugError = ''
			try {
				const result = await this.$emit('add', payload)
				// Parent will close the dialog on success by toggling addOpen via prop.
				// We close locally to keep UX snappy unless parent signalled an error.
				this.addOpen = false
				return result
			} catch (e) {
				if (e && e.status === 409) {
					this.addSlugError = this.t('openbuild', 'A schema with this slug already exists in this app.')
				} else {
					this.addSlugError = (e && e.message) || this.t('openbuild', 'Failed to add schema.')
				}
			} finally {
				this.addSubmitting = false
			}
		},
		/**
		 * Open the delete-confirmation dialog for a schema.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} schema Schema record.
		 * @return {void}
		 */
		requestDelete(schema) {
			this.pendingDeleteSlug = this.getSlug(schema)
			this.deleteOpen = true
		},
		/**
		 * Confirm deletion: emit delete and reset the pending state.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		onDeleteConfirm() {
			this.$emit('delete', this.pendingDeleteSlug)
			this.deleteOpen = false
			this.pendingDeleteSlug = ''
		},
		/**
		 * Cancel the pending deletion.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		cancelDelete() {
			this.deleteOpen = false
			this.pendingDeleteSlug = ''
		},
	},
}
</script>

<style scoped>
.openbuild-schema-list {
	display: flex;
	flex-direction: column;
	padding: 16px;
	gap: 16px;
}

.openbuild-schema-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuild-schema-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.openbuild-schema-list__loading,
.openbuild-schema-list__empty {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.openbuild-schema-list__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.openbuild-schema-list__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.openbuild-schema-list__row-main {
	flex: 1 1 auto;
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 4px;
	background: transparent;
	border: 0;
	padding: 0;
	cursor: pointer;
	text-align: left;
	color: inherit;
	font: inherit;
}

.openbuild-schema-list__row-main:hover .openbuild-schema-list__row-title {
	color: var(--color-primary-element);
}

.openbuild-schema-list__row-title {
	font-size: 15px;
	font-weight: 600;
}

.openbuild-schema-list__row-meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.openbuild-schema-list__row-meta code {
	font-family: monospace;
}
</style>
