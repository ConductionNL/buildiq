<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - SchemaListPanel — lists every schema in the current virtual app's
  - register namespace (REQ-OBSD-001). Renders slug, title, version,
  - property count, and lifecycle-state count. Owns the Add Schema and
  - per-row Open / Rename / Delete actions. Delete is gated by the
  - DeleteSchemaDialog modal (REQ-OBSD-008).
  -
  - Rows also show a "Restricted" badge (REQ-OBDSA-005) for any schema
  - carrying a schema-level `authorization` block — the compact summary
  - is derived by the pure exported `scopeSummary()` helper so it stays
  - unit-testable without mounting.
  -->
<template>
	<div class="buildiq-schema-list">
		<header class="buildiq-schema-list__header">
			<h2>{{ t('buildiq', 'Schemas') }}</h2>
			<NcButton variant="primary" @click="addOpen = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('buildiq', 'Add schema') }}
			</NcButton>
		</header>

		<div v-if="loading" class="buildiq-schema-list__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<div v-else-if="schemas.length === 0" class="buildiq-schema-list__empty">
			<NcEmptyContent
				:name="t('buildiq', 'No schemas yet')"
				:description="
					t(
						'buildiq',
						'Add your first schema to start designing the data model for this app.',
					)
				">
				<template #icon>
					<DatabaseIcon :size="64" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="addOpen = true">
						{{ t('buildiq', 'Add schema') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<ul v-else class="buildiq-schema-list__rows">
			<li
				v-for="schema in schemas"
				:key="getSlug(schema)"
				class="buildiq-schema-list__row">
				<button
					type="button"
					class="buildiq-schema-list__row-main"
					@click="onOpen(schema)">
					<span class="buildiq-schema-list__row-title">
						{{ schema.title || getSlug(schema) }}
					</span>
					<span class="buildiq-schema-list__row-meta">
						<code>{{ getSlug(schema) }}</code>
						<span>{{
							t('buildiq', 'v{version}', {
								version: schema.version || '—',
							})
						}}</span>
						<span>{{
							n(
								'buildiq',
								'{n} property',
								'{n} properties',
								propertyCount(schema),
								{ n: propertyCount(schema) },
							)
						}}</span>
						<span>{{ lifecycleLabel(schema) }}</span>
						<span
							v-if="scopeSummary(schema)"
							class="buildiq-schema-list__badge"
							:title="scopeSummary(schema).title">
							{{ scopeSummary(schema).label }}
						</span>
					</span>
				</button>
				<NcActions>
					<NcActionButton @click="onOpen(schema)">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('buildiq', 'Open') }}
					</NcActionButton>
					<NcActionButton @click="requestDelete(schema)">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('buildiq', 'Delete') }}
					</NcActionButton>
				</NcActions>
			</li>
		</ul>

		<AddSchemaDialog
			:open="addOpen"
			:submitting="addSubmitting"
			:slugError="addSlugError"
			@confirm="onAddConfirm"
			@cancel="addOpen = false"
			@update:open="addOpen = $event" />

		<DeleteSchemaDialog
			:open="deleteOpen"
			:schemaSlug="pendingDeleteSlug"
			@confirm="onDeleteConfirm"
			@cancel="cancelDelete"
			@update:open="deleteOpen = $event" />
	</div>
</template>

<script>
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
} from '@nextcloud/vue'
import DatabaseIcon from 'vue-material-design-icons/Database.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import AddSchemaDialog from '../../modals/AddSchemaDialog.vue'
import DeleteSchemaDialog from '../../modals/DeleteSchemaDialog.vue'

/** Operations summarised by `scopeSummary`, in display order. */
const SCOPE_OPS = ['read', 'create', 'update', 'delete']

/**
 * Derive a compact "Restricted" badge for a schema carrying a
 * schema-level `authorization` block (REQ-OBDSA-005). Pure and
 * exported so it is unit-testable without mounting the panel.
 *
 * Returns `null` for a schema with no `authorization` block (or an
 * empty one). Otherwise returns `{ label, title }` where `title` is a
 * semicolon-joined per-operation summary (e.g. `read: vets; delete:
 * admin`) suitable for the badge's accessible title attribute — every
 * scope kind the Access sub-editor can produce is covered: plain group
 * lists, the `@creator` sentinel, and `authorization.conditions.<op>`.
 *
 * @param {object} schema Schema record (may carry `authorization`).
 * @return {{label: string, title: string}|null} Badge, or null.
 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-005
 */
export function scopeSummary(schema) {
	const auth = schema && schema.authorization
	if (!auth || typeof auth !== 'object' || Object.keys(auth).length === 0) {
		return null
	}
	const parts = []
	SCOPE_OPS.forEach((op) => {
		const list = auth[op]
		if (Array.isArray(list) && list.length > 0) {
			parts.push(`${op}: ${list.join(', ')}`)
			return
		}
		const condition = auth.conditions && auth.conditions[op]
		if (condition) {
			parts.push(`${op}: condition(${(condition && condition.field) || '?'})`)
		}
	})
	const title =
		parts.length > 0 ? parts.join('; ') : 'Custom authorization metadata'
	return { label: 'Restricted', title }
}

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
			return (
				schema.slug
				|| (schema['@self'] && schema['@self'].slug)
				|| schema.id
				|| ''
			)
		},

		/**
		 * Expose the pure `scopeSummary` helper as an instance method so
		 * the template can call it directly (REQ-OBDSA-005).
		 *
		 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-005
		 * @param {object} schema Schema record.
		 * @return {{label: string, title: string}|null} Badge, or null.
		 */
		scopeSummary(schema) {
			return scopeSummary(schema)
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
			if (
				!lifecycle
				|| !Array.isArray(lifecycle.states)
				|| lifecycle.states.length === 0
			) {
				return this.t('buildiq', 'No lifecycle')
			}
			return this.n(
				'buildiq',
				'{n} lifecycle state',
				'{n} lifecycle states',
				lifecycle.states.length,
				{ n: lifecycle.states.length },
			)
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
					this.addSlugError = this.t(
						'buildiq',
						'A schema with this slug already exists in this app.',
					)
				} else {
					this.addSlugError =
						(e && e.message)
						|| this.t('buildiq', 'Failed to add schema.')
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
.buildiq-schema-list {
	display: flex;
	flex-direction: column;
	padding: 16px;
	gap: 16px;
}

.buildiq-schema-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.buildiq-schema-list__header h2 {
	margin: 0;
	font-size: 22px;
	font-weight: 600;
}

.buildiq-schema-list__loading,
.buildiq-schema-list__empty {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.buildiq-schema-list__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.buildiq-schema-list__row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.buildiq-schema-list__row-main {
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

.buildiq-schema-list__row-main:hover .buildiq-schema-list__row-title {
	color: var(--color-primary-element);
}

.buildiq-schema-list__row-title {
	font-size: 15px;
	font-weight: 600;
}

.buildiq-schema-list__row-meta {
	display: flex;
	gap: 12px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.buildiq-schema-list__row-meta code {
	font-family: monospace;
}

.buildiq-schema-list__badge {
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 10px);
	background: var(--color-warning, #ffbb33);
	color: var(--color-main-background, #fff);
	font-weight: 600;
}
</style>
