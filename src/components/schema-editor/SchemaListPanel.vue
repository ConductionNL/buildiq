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
	<div class="openbuilt-schema-list">
		<header class="openbuilt-schema-list__header">
			<h2>{{ t('openbuilt', 'Schemas') }}</h2>
			<NcButton type="primary" @click="addOpen = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuilt', 'Add schema') }}
			</NcButton>
		</header>

		<div v-if="loading" class="openbuilt-schema-list__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="schemas.length === 0" class="openbuilt-schema-list__empty">
			<NcEmptyContent
				:name="t('openbuilt', 'No schemas yet')"
				:description="t('openbuilt', 'Add your first schema to start designing the data model for this app.')">
				<template #icon>
					<DatabaseIcon :size="64" />
				</template>
				<template #action>
					<NcButton type="primary" @click="addOpen = true">
						{{ t('openbuilt', 'Add schema') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<ul v-else class="openbuilt-schema-list__rows">
			<li
				v-for="schema in schemas"
				:key="getSlug(schema)"
				class="openbuilt-schema-list__row">
				<button
					type="button"
					class="openbuilt-schema-list__row-main"
					@click="onOpen(schema)">
					<span class="openbuilt-schema-list__row-title">
						{{ schema.title || getSlug(schema) }}
					</span>
					<span class="openbuilt-schema-list__row-meta">
						<code>{{ getSlug(schema) }}</code>
						<span>{{ t('openbuilt', 'v{version}', { version: schema.version || '—' }) }}</span>
						<span>{{ n('openbuilt', '{n} property', '{n} properties', propertyCount(schema), { n: propertyCount(schema) }) }}</span>
						<span>{{ lifecycleLabel(schema) }}</span>
					</span>
				</button>
				<NcActions>
					<NcActionButton @click="onOpen(schema)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('openbuilt', 'Open') }}
					</NcActionButton>
					<NcActionButton @click="requestDelete(schema)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('openbuilt', 'Delete') }}
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
		propertyCount(schema) {
			if (!schema || !schema.properties) {
				return 0
			}
			return Object.keys(schema.properties).length
		},
		lifecycleLabel(schema) {
			const lifecycle = schema && schema['x-openregister-lifecycle']
			if (!lifecycle || !Array.isArray(lifecycle.states) || lifecycle.states.length === 0) {
				return this.t('openbuilt', 'No lifecycle')
			}
			return this.n('openbuilt', '{n} lifecycle state', '{n} lifecycle states', lifecycle.states.length, { n: lifecycle.states.length })
		},
		onOpen(schema) {
			this.$emit('open', this.getSlug(schema))
		},
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
					this.addSlugError = this.t('openbuilt', 'A schema with this slug already exists in this app.')
				} else {
					this.addSlugError = (e && e.message) || this.t('openbuilt', 'Failed to add schema.')
				}
			} finally {
				this.addSubmitting = false
			}
		},
		requestDelete(schema) {
			this.pendingDeleteSlug = this.getSlug(schema)
			this.deleteOpen = true
		},
		onDeleteConfirm() {
			this.$emit('delete', this.pendingDeleteSlug)
			this.deleteOpen = false
			this.pendingDeleteSlug = ''
		},
		cancelDelete() {
			this.deleteOpen = false
			this.pendingDeleteSlug = ''
		},
	},
}
</script>

<style scoped>
.openbuilt-schema-list {
	display: flex;
	flex-direction: column;
	padding: 16px;
	gap: 16px;
}

.openbuilt-schema-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuilt-schema-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.openbuilt-schema-list__loading,
.openbuilt-schema-list__empty {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.openbuilt-schema-list__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.openbuilt-schema-list__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.openbuilt-schema-list__row-main {
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

.openbuilt-schema-list__row-main:hover .openbuilt-schema-list__row-title {
	color: var(--color-primary-element);
}

.openbuilt-schema-list__row-title {
	font-size: 15px;
	font-weight: 600;
}

.openbuilt-schema-list__row-meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.openbuilt-schema-list__row-meta code {
	font-family: monospace;
}
</style>
