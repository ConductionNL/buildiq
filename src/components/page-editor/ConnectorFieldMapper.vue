<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ConnectorFieldMapper — schema-mapping editor for a connector binding.
  -
  - Renders the sample response as a collapsible JSON tree; clicking an array
  - node sets `itemsPath`, clicking item leaves appends `fields` entries (name
  - pre-filled from the leaf key). The mapped field list shows live sample
  - values. "Re-fetch sample" re-runs the call and flags selectors that no
  - longer resolve — without silently mutating the mapping (REQ-OCAS-003).
  -->
<template>
	<div class="connector-field-mapper">
		<div class="connector-field-mapper__toolbar">
			<NcButton
				type="tertiary"
				:disabled="refreshing"
				@click="$emit('refetch-sample')">
				{{ t('openbuild', 'Re-fetch sample') }}
			</NcButton>
			<span v-if="itemsPath" class="connector-field-mapper__items-path">
				{{ t('openbuild', 'List root: {path}', { path: itemsPath }) }}
			</span>
		</div>

		<div v-if="treeNodes.length" class="connector-field-mapper__tree">
			<ul class="connector-field-mapper__tree-list">
				<li
					v-for="node in treeNodes"
					:key="node.path || '(root)'"
					class="connector-field-mapper__tree-node"
					:style="{ paddingLeft: node.depth * 12 + 'px' }">
					<button
						v-if="node.isArray"
						type="button"
						class="connector-field-mapper__node-btn"
						@click="setItemsPath(node.path)">
						{{ nodeLabel(node) }}
						<span class="connector-field-mapper__node-type"
							>[array]</span
						>
					</button>
					<button
						v-else-if="node.isLeaf"
						type="button"
						class="connector-field-mapper__node-btn"
						@click="addField(node)">
						{{ nodeLabel(node) }}:
						<span class="connector-field-mapper__node-value">{{
							String(node.value)
						}}</span>
					</button>
					<span v-else class="connector-field-mapper__node-obj">{{
						nodeLabel(node)
					}}</span>
				</li>
			</ul>
		</div>
		<p v-else class="connector-field-mapper__hint">
			{{
				t(
					'openbuild',
					'Select an endpoint and fetch a sample to start mapping fields.',
				)
			}}
		</p>

		<table v-if="fieldRows.length" class="connector-field-mapper__fields">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openbuild', 'Field') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Selector') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Sample value') }}
					</th>
					<!-- Row-actions column: no visible caption, but still a column
					     header, so it keeps `scope="col"` and an sr-only name. -->
					<th scope="col">
						<span class="hidden-visually">{{
							t('openbuild', 'Actions')
						}}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in fieldRows"
					:key="row.name"
					:class="{ 'connector-field-mapper__row--dead': row.dead }">
					<td>{{ row.name }}</td>
					<td>
						<code>{{ row.selector }}</code>
					</td>
					<td>
						<span v-if="row.dead" class="connector-field-mapper__warn">
							{{
								t(
									'openbuild',
									'Selector resolved to no value in the latest sample',
								)
							}}
						</span>
						<span v-else>{{ String(row.sample) }}</span>
					</td>
					<td>
						<NcButton
							type="tertiary"
							:aria-label="t('openbuild', 'Remove field')"
							@click="removeField(row.name)">
							{{ t('openbuild', 'Remove') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<PromptTextDialog
			v-model:open="promptOpen"
			:name="t('openbuild', 'Add field')"
			:label="t('openbuild', 'Display field name')"
			:initial-value="promptSuggestion"
			:confirm-label="t('openbuild', 'Confirm')"
			@submit="onPromptSubmit" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { flattenSample, resolveSelector } from '../../services/selectors.js'
import PromptTextDialog from '../../dialogs/PromptTextDialog.vue'

export default {
	name: 'ConnectorFieldMapper',
	components: { NcButton, PromptTextDialog },
	props: {
		// The `dataSource.connector` binding being edited.
		binding: {
			type: Object,
			default: () => ({}),
		},
		// The sample response payload fetched from the endpoint.
		sample: {
			type: [Object, Array, String, Number, Boolean],
			default: null,
		},
		refreshing: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:itemsPath', 'update:fields'],
	data() {
		return {
			promptOpen: false,
			promptSuggestion: '',
			pendingNode: null,
		}
	},
	computed: {
		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003 */
		itemsPath() {
			return (this.binding && this.binding.itemsPath) || ''
		},
		/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003 */
		fields() {
			return (this.binding && this.binding.fields) || {}
		},
		/**
		 * The sub-tree the field selectors are relative to — the first item
		 * under `itemsPath` when set, otherwise the sample root.
		 *
		 * @return {*}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		itemContext() {
			if (!this.itemsPath) {
				return this.sample
			}
			const list = resolveSelector(this.sample, this.itemsPath)
			return Array.isArray(list) && list.length ? list[0] : null
		},
		/**
		 * Flattened tree nodes for display, computed against the item context
		 * so leaf selectors are relative to a single item.
		 *
		 * @return {Array}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		treeNodes() {
			const root = this.itemsPath ? this.itemContext : this.sample
			if (root === null || root === undefined) {
				return []
			}
			return flattenSample(root).map((n) => ({
				...n,
				depth: n.path ? n.path.split('.').length : 0,
			}))
		},
		/**
		 * The mapped field list with live sample values + dead-selector flags.
		 *
		 * @return {Array<{name: string, selector: string, sample: *, dead: boolean}>}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		fieldRows() {
			const ctx = this.itemContext
			return Object.entries(this.fields).map(([name, selector]) => {
				const resolved = resolveSelector(ctx, selector)
				return {
					name,
					selector,
					sample: resolved === undefined ? null : resolved,
					dead:
						ctx !== null && ctx !== undefined && resolved === undefined,
				}
			})
		},
	},
	methods: {
		/**
		 * @param {object} node - tree node.
		 * @return {string}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		nodeLabel(node) {
			if (!node.path) {
				return t('openbuild', '(root)')
			}
			const segs = node.path.split('.')
			return segs[segs.length - 1]
		},
		/**
		 * Set the list-root selector from a clicked array node. The clicked
		 * path is relative to the current root, which for the initial mapping
		 * is the sample root.
		 *
		 * @param {string} path - dot-path of the array node.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.1
		 */
		setItemsPath(path) {
			this.$emit('update:itemsPath', path)
		},
		/**
		 * Append a field mapping from a clicked leaf node. The display-field
		 * name is pre-filled from the leaf key; the selector is the leaf's
		 * path relative to the item context.
		 *
		 * @param {object} node - leaf tree node.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.1
		 */
		addField(node) {
			this.pendingNode = node
			this.promptSuggestion = this.nodeLabel(node)
			this.promptOpen = true
		},
		/**
		 * Add the mapping once the user has named the display field.
		 *
		 * The dialog disables its submit while the value is blank and emits
		 * nothing on cancel, so the empty-name guard window.prompt needed
		 * (`if (!name) return`) is now enforced at the source.
		 *
		 * @param {string} name - the entered display-field name.
		 * @return {void}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.1
		 */
		onPromptSubmit(name) {
			const node = this.pendingNode
			this.promptOpen = false
			this.pendingNode = null
			if (!node || !name) {
				return
			}
			const next = { ...this.fields, [name]: node.path }
			this.$emit('update:fields', next)
		},
		/**
		 * Remove a mapped field.
		 *
		 * @param {string} name - field name to remove.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		removeField(name) {
			const next = { ...this.fields }
			delete next[name]
			this.$emit('update:fields', next)
		},
	},
}
</script>

<style scoped>
.connector-field-mapper__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.connector-field-mapper__tree {
	max-height: 240px;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.connector-field-mapper__tree-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.connector-field-mapper__node-btn {
	background: none;
	border: none;
	cursor: pointer;
	padding: 2px 4px;
	text-align: left;
	color: var(--color-main-text);
}

.connector-field-mapper__node-btn:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.connector-field-mapper__node-type,
.connector-field-mapper__node-value {
	color: var(--color-text-maxcontrast);
}

.connector-field-mapper__fields {
	width: 100%;
	margin-top: 12px;
	border-collapse: collapse;
}

.connector-field-mapper__fields th,
.connector-field-mapper__fields td {
	text-align: left;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.connector-field-mapper__row--dead .connector-field-mapper__warn {
	color: var(--color-warning-text, var(--color-warning));
}

.connector-field-mapper__hint {
	color: var(--color-text-maxcontrast);
}
</style>
