<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DataModelDiagram — an app version's data model as a diagram: one box per
  - schema with its fields, one line per relation with its name and a `1` or
  - `n` marker (REQ-BQMD-001 .. REQ-BQMD-004). Read only: relations are made
  - in the relation editor, never by drawing a line. Under the canvas the same
  - picture is a table, so every box and line is reachable without a pointer.
  -
  - The model comes from OpenRegister (`GET /api/registers/{id}/model`) and is
  - mapped by `services/dataModelGraph.js`; this component only draws it and
  - emits `open` with the slug of the schema a maker chose.
  -->
<template>
	<section class="buildiq-data-model" :aria-label="t('buildiq', 'Data model')">
		<div v-if="loading" class="buildiq-data-model__loading">
			<NcLoadingIcon :size="32" />
		</div>
		<NcEmptyContent
			v-else-if="error"
			:name="t('buildiq', 'The data model could not be loaded')"
			:description="error" />
		<NcEmptyContent
			v-else-if="diagram.nodes.length === 0"
			:name="t('buildiq', 'This register has no schemas yet')"
			:description="t('buildiq', 'Add a schema in the list to see it in the diagram.')" />
		<template v-else>
			<NcNoteCard v-if="diagram.folded" type="info">
				{{ t('buildiq', 'This register has more than {count} schemas, so the boxes show their titles only. The table below lists every relation.', { count: foldThreshold }) }}
			</NcNoteCard>
			<!-- The canvas runs read only, which also silences its own Enter
			     handling; Enter on a focused box is caught here instead. -->
			<div
				class="buildiq-data-model__canvas"
				data-testid="data-model-canvas"
				@keydown.enter="onCanvasEnter">
				<CnGraphCanvas
					:nodes="diagram.nodes"
					:edges="diagram.edges"
					:readOnly="true"
					:showMiniMap="false"
					@nodeSelect="onNodeSelect">
					<template #node="{ node }">
						<div
							class="buildiq-data-model__box"
							:class="{
								'buildiq-data-model__box--external': node.data && node.data.external,
								'buildiq-data-model__box--locked': node.data && !node.data.readable,
								'buildiq-data-model__box--dangling': node.data && node.data.dangling,
							}"
							data-testid="data-model-box">
							<strong class="buildiq-data-model__box-title">
								{{ boxTitle(node.data) }}
							</strong>
							<span v-if="node.data && node.data.dangling" class="buildiq-data-model__box-note">
								{{ t('buildiq', 'Not found') }}
							</span>
							<span v-else-if="node.data && node.data.external && node.data.readable" class="buildiq-data-model__box-note">
								{{ t('buildiq', 'Other register') }}
							</span>
							<ul v-if="node.data && node.data.fields.length > 0" class="buildiq-data-model__fields">
								<li v-for="field in node.data.fields" :key="field.name" data-testid="data-model-field">
									<span>{{ field.name }}</span>
									<span v-if="field.type" class="buildiq-data-model__field-type">{{ field.type }}</span>
								</li>
							</ul>
							<span v-if="node.data && node.data.more > 0" class="buildiq-data-model__more" data-testid="data-model-more">
								{{ t('buildiq', 'and {count} more', { count: node.data.more }) }}
							</span>
						</div>
					</template>
					<template #edge-label="{ edge }">
						<span class="buildiq-data-model__edge-label">{{ edge.data && edge.data.labelAriaLabel }}</span>
					</template>
				</CnGraphCanvas>
			</div>

			<table class="buildiq-data-model__table" data-testid="data-model-table">
				<caption>{{ t('buildiq', 'Schemas and their relations') }}</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('buildiq', 'From') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Relation') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'To') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'One or many') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{ t('buildiq', 'Actions') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in diagram.relations" :key="row.id" data-testid="data-model-relation-row">
						<td>{{ boxTitle(row.from) }}</td>
						<td>
							{{ row.property }}<template v-if="row.inverse">
								/ {{ row.inverse }}
							</template>
						</td>
						<td>{{ boxTitle(row.to) }}</td>
						<td>{{ row.cardinality === 'many' ? t('buildiq', 'many') : t('buildiq', 'one') }}</td>
						<td>
							<NcButton
								v-if="canOpen(row.from)"
								variant="tertiary"
								:aria-label="t('buildiq', 'Open {schema}', { schema: boxTitle(row.from) })"
								@click="open(row.from)">
								{{ t('buildiq', 'Open') }}
							</NcButton>
						</td>
					</tr>
					<tr v-for="schema in diagram.unlinked" :key="`unlinked:${schema.id}`" data-testid="data-model-unlinked-row">
						<td>{{ boxTitle(schema) }}</td>
						<td>{{ t('buildiq', 'No relations') }}</td>
						<td />
						<td />
						<td>
							<NcButton
								v-if="canOpen(schema)"
								variant="tertiary"
								:aria-label="t('buildiq', 'Open {schema}', { schema: boxTitle(schema) })"
								@click="open(schema)">
								{{ t('buildiq', 'Open') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import { CnGraphCanvas } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { canOpen, FOLD_THRESHOLD, toDiagram } from '../../services/dataModelGraph.js'

export default {
	name: 'DataModelDiagram',

	components: {
		CnGraphCanvas,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** OpenRegister's register model, `{nodes, edges}`; null until loaded. */
		model: {
			type: Object,
			default: null,
		},

		/** Whether the model is being loaded. */
		loading: {
			type: Boolean,
			default: false,
		},

		/** Why the model could not be loaded; empty when it could. */
		error: {
			type: String,
			default: '',
		},

		/** Fold the boxes to their titles above this many schemas. */
		foldThreshold: {
			type: Number,
			default: FOLD_THRESHOLD,
		},
	},

	emits: ['open'],

	computed: {
		/**
		 * The canvas nodes and edges and the table rows.
		 *
		 * @spec openspec/changes/data-model-diagram/tasks.md#T03
		 * @return {object} The diagram.
		 */
		diagram() {
			return toDiagram(this.model, {
				foldAbove: this.foldThreshold,
				untitled: this.t('buildiq', 'Schema you may not read'),
			})
		},
	},

	methods: {
		canOpen,

		/**
		 * The title a box or a table cell shows.
		 *
		 * @param {object|null} box The node data.
		 * @return {string} The title.
		 */
		boxTitle(box) {
			if (!box) {
				return ''
			}
			return box.title === null ? this.t('buildiq', 'Schema you may not read') : box.title
		},

		/**
		 * Open a schema the maker may read; anything else does nothing.
		 *
		 * @spec openspec/changes/data-model-diagram/tasks.md#T03
		 * @param {object|null} box The node data.
		 * @return {void}
		 */
		open(box) {
			if (canOpen(box)) {
				this.$emit('open', box.slug)
			}
		},

		/**
		 * The box with this id.
		 *
		 * @param {string} id The node id.
		 * @return {object|null} Its data.
		 */
		boxById(id) {
			const node = this.diagram.nodes.find((candidate) => candidate.id === id)
			return node ? node.data : null
		},

		/**
		 * A click on a box opens its schema.
		 *
		 * @spec openspec/changes/data-model-diagram/tasks.md#T03
		 * @param {{node: {id: string}}} event The canvas' node event.
		 * @return {void}
		 */
		onNodeSelect(event) {
			const id = event && event.node ? event.node.id : null
			if (id !== null) {
				this.open(this.boxById(String(id)))
			}
		},

		/**
		 * Enter on a focused box opens its schema.
		 *
		 * @spec openspec/changes/data-model-diagram/tasks.md#T03
		 * @param {KeyboardEvent} event The key event.
		 * @return {void}
		 */
		onCanvasEnter(event) {
			const target = event && event.target
			if (!target || typeof target.closest !== 'function') {
				return
			}
			const wrapper = target.closest('[data-id]')
			if (!wrapper || !target.classList.contains('cn-flow-node')) {
				return
			}
			event.preventDefault()
			this.open(this.boxById(wrapper.getAttribute('data-id')))
		},
	},
}
</script>

<style scoped>
.buildiq-data-model {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.buildiq-data-model__loading {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline, 4px) * 8);
}

.buildiq-data-model__canvas {
	height: 60vh;
	min-height: 360px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
}

.buildiq-data-model__box {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 200px;
	max-width: 260px;
	text-align: start;
	color: var(--color-main-text);
}

.buildiq-data-model__box--external {
	border-inline-start: 3px dashed var(--color-border-dark);
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 2);
}

.buildiq-data-model__box--locked .buildiq-data-model__box-title {
	font-style: italic;
	color: var(--color-text-maxcontrast);
}

.buildiq-data-model__box--dangling .buildiq-data-model__box-note {
	color: var(--color-text-error, var(--color-error));
}

.buildiq-data-model__box-note,
.buildiq-data-model__field-type,
.buildiq-data-model__more {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
}

.buildiq-data-model__fields {
	margin: 0;
	padding: 0;
	list-style: none;
}

.buildiq-data-model__fields li {
	display: flex;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.buildiq-data-model__edge-label {
	font-size: var(--font-size-small, 13px);
	color: var(--color-main-text);
}

.buildiq-data-model__table {
	width: 100%;
	border-collapse: collapse;
}

.buildiq-data-model__table caption {
	text-align: start;
	font-weight: bold;
	padding-block-end: var(--default-grid-baseline, 4px);
}

.buildiq-data-model__table th,
.buildiq-data-model__table td {
	text-align: start;
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2);
	border-bottom: 1px solid var(--color-border);
}
</style>
