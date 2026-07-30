<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - RelationEditor — authors `x-openregister-relations` declaratively
  - (REQ-OBSD-005 relations slice — v1). Target is a picker over
  - namespace schemas (no free-text slug); cardinality is a fixed enum.
  -->
<template>
	<section class="openbuild-relation-editor">
		<header class="openbuild-relation-editor__header">
			<h3>{{ t('openbuild', 'Relations') }}</h3>
			<NcButton @click="addRelation">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuild', 'Add relation') }}
			</NcButton>
		</header>

		<p v-if="relations.length === 0" class="openbuild-relation-editor__empty">
			{{ t('openbuild', 'No relations yet.') }}
		</p>

		<ul v-else class="openbuild-relation-editor__rows">
			<li
				v-for="(relation, index) in relations"
				:key="relation._key"
				class="openbuild-relation-editor__row">
				<NcTextField
					:model-value="relation.name"
					:label="t('openbuild', 'Relation name')"
					@update:modelValue="updateRelation(index, 'name', $event)" />
				<NcSelect
					:input-label="t('openbuild', 'Target schema')"
					:model-value="schemaOption(relation.target)"
					:options="schemaOptions"
					:clearable="false"
					label="label"
					track-by="value"
					@update:modelValue="updateRelation(index, 'target', $event ? $event.value : '')" />
				<NcSelect
					:input-label="t('openbuild', 'Cardinality')"
					:model-value="cardinalityOption(relation.cardinality)"
					:options="cardinalityOptions"
					:clearable="false"
					label="label"
					track-by="value"
					@update:modelValue="updateRelation(index, 'cardinality', $event ? $event.value : 'one')" />
				<NcTextField
					:model-value="relation.inverseOf || ''"
					:label="t('openbuild', 'Inverse-of (optional)')"
					@update:modelValue="updateRelation(index, 'inverseOf', $event)" />
				<NcButton
					type="error"
					:aria-label="t('openbuild', 'Remove relation')"
					@click="removeRelation(index)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

const CARDINALITIES = ['one', 'many']

let keyCounter = 0
function nextKey() {
	keyCounter += 1
	return `rel-${keyCounter}`
}

export default {
	name: 'RelationEditor',
	components: { DeleteIcon, NcButton, NcSelect, NcTextField, PlusIcon },
	props: {
		relations: { type: Array, default: () => [] },
		schemaSlugs: { type: Array, default: () => [] },
	},
	emits: ['update:relations'],
	computed: {
		/**
		 * Build target-schema picker options from the available slugs.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @return {Array} Option objects.
		 */
		schemaOptions() {
			return this.schemaSlugs.map((slug) => ({ value: slug, label: slug }))
		},
		/**
		 * Build cardinality picker options (one/many) with translated labels.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @return {Array} Option objects.
		 */
		cardinalityOptions() {
			return CARDINALITIES.map((value) => ({
				value,
				label: value === 'one'
					? this.t('openbuild', 'One')
					: this.t('openbuild', 'Many'),
			}))
		},
	},
	methods: {
		/**
		 * Resolve the selected target-schema option for a value.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @param {string} value Schema slug.
		 * @return {object|null} The matching option.
		 */
		schemaOption(value) {
			return this.schemaOptions.find((o) => o.value === value) || null
		},
		/**
		 * Resolve the selected cardinality option for a value.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @param {string} value Cardinality.
		 * @return {object} The matching option (defaults to first).
		 */
		cardinalityOption(value) {
			return this.cardinalityOptions.find((o) => o.value === value) || this.cardinalityOptions[0]
		},
		/**
		 * Emit the updated relations array to the parent.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @param {Array} next Next relations array.
		 * @return {void}
		 */
		emitRelations(next) {
			this.$emit('update:relations', next)
		},
		/**
		 * Append a new blank relation row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @return {void}
		 */
		addRelation() {
			const next = this.relations.slice()
			next.push({
				_key: nextKey(),
				name: '',
				target: this.schemaSlugs[0] || '',
				cardinality: 'one',
				inverseOf: '',
			})
			this.emitRelations(next)
		},
		/**
		 * Update a single field of a relation row.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @param {number} index Row index.
		 * @param {string} key Field key.
		 * @param {*} value New value.
		 * @return {void}
		 */
		updateRelation(index, key, value) {
			const next = this.relations.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitRelations(next)
		},
		/**
		 * Remove a relation row by index.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @param {number} index Row index.
		 * @return {void}
		 */
		removeRelation(index) {
			const next = this.relations.slice()
			next.splice(index, 1)
			this.emitRelations(next)
		},
	},
}

/**
 * Convert an `x-openregister-relations` block into editor rows.
 *
 * @param {Array} block Existing relations block (array of typed records).
 * @return {Array} Editor relation rows.
 */
export function relationsToEditor(block) {
	if (!Array.isArray(block)) {
		return []
	}
	return block.map((r) => ({
		_key: nextKey(),
		name: r.name || '',
		target: r.target || '',
		cardinality: r.cardinality || 'one',
		inverseOf: r.inverseOf || r.inverse_of || '',
	}))
}

/**
 * Reduce editor relation rows back into an
 * `x-openregister-relations` block.
 *
 * @param {Array} relations Editor relation rows.
 * @return {Array|null} The serialised block, or null when empty.
 */
export function editorToRelations(relations) {
	if (!relations || relations.length === 0) {
		return null
	}
	return relations
		.filter((r) => r.name && r.target)
		.map((r) => ({
			name: r.name,
			target: r.target,
			cardinality: r.cardinality,
			...(r.inverseOf ? { inverseOf: r.inverseOf } : {}),
		}))
}
</script>

<style scoped>
.openbuild-relation-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-relation-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuild-relation-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuild-relation-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuild-relation-editor__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-relation-editor__row {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr 1fr auto;
	gap: 8px;
	align-items: center;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}
</style>
