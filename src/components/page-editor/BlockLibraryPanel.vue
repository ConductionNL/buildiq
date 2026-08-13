<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	BlockLibraryPanel — org-scoped list/filter/preview/insert/export/import/
	delete surface for `ComponentBlock`s, rendered inside an `NcAppSidebar`
	panel in the page designer (design.md Open Question, resolved: sidebar
	panel, not a designer tab). Insert always deep-copies via
	`blockInsert.js#insertBlock`; a schema-dependency mismatch against the
	current app opens `BlockRemapDialog` before the widgets are handed back
	to the parent for merge (`mergeManifestDelta`, the app's existing
	structural-merge engine).
-->
<template>
	<div class="block-library-panel">
		<p class="block-library-panel__intro">
			{{
				t(
					'openbuild',
					'Blocks saved by anyone in your organisation. Insert deep-copies a block onto this page — editing the source later never changes an already-inserted copy.',
				)
			}}
		</p>

		<NcSelect
			v-model="categoryFilter"
			:input-label="t('openbuild', 'Filter by category')"
			:options="categoryOptions"
			:clearable="true"
			:placeholder="t('openbuild', 'All categories')" />

		<label class="block-library-panel__import">
			{{ t('openbuild', 'Import a block') }}
			<input type="file" accept="application/json" @change="onImportFile" />
		</label>

		<p v-if="importError" class="block-library-panel__error" role="alert">
			{{ importError }}
		</p>

		<div v-if="loading" class="block-library-panel__loading">
			<NcLoadingIcon :size="24" />
		</div>

		<NcEmptyContent
			v-else-if="filteredBlocks.length === 0"
			:name="t('openbuild', 'No blocks yet')"
			:description="
				t(
					'openbuild',
					'Save a widget or section from the designer to build your first block.',
				)
			" />

		<ul v-else class="block-library-panel__list">
			<li v-for="block in filteredBlocks" :key="block.slug" class="block-card">
				<div class="block-card__body">
					<h4 class="block-card__title">
						{{ block.name }}
					</h4>
					<span v-if="block.category" class="block-card__category">{{
						block.category
					}}</span>
					<p class="block-card__description">
						{{ block.description }}
					</p>
					<p class="block-card__preview">
						{{ previewLabel(block) }}
					</p>
				</div>
				<div class="block-card__actions">
					<NcButton type="primary" @click="onInsert(block)">
						{{ t('openbuild', 'Insert') }}
					</NcButton>
					<NcButton @click="onExport(block)">
						{{ t('openbuild', 'Export') }}
					</NcButton>
					<template v-if="confirmDeleteSlug === block.slug">
						<NcButton type="error" @click="onDelete(block)">
							{{ t('openbuild', 'Confirm delete') }}
						</NcButton>
						<NcButton @click="confirmDeleteSlug = ''">
							{{ t('openbuild', 'Cancel') }}
						</NcButton>
					</template>
					<NcButton v-else @click="confirmDeleteSlug = block.slug">
						{{ t('openbuild', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<BlockRemapDialog
			:open="remapOpen"
			:dependencies="remapDependencies"
			:target-schema-slugs="targetSchemaSlugs"
			@update:open="remapOpen = $event"
			@resolved="onRemapResolved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'

import BlockRemapDialog from '../../dialogs/BlockRemapDialog.vue'
import { isSectionFragment } from '../../services/blockCapture.js'
import {
	computeSchemaMismatches,
	insertBlock,
	remapBlockRecord,
} from '../../services/blockInsert.js'
import {
	downloadBlockExport,
	BlockImportError,
	parseBlockImport,
} from '../../services/blockExport.js'

const OR_BLOCKS = '/apps/openregister/api/objects/openbuild/component-block'

export default {
	name: 'BlockLibraryPanel',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		BlockRemapDialog,
	},
	props: {
		// Whether the panel is currently visible — controls the fetch.
		open: { type: Boolean, default: false },
		// Schema slugs declared by the current app (used to detect a
		// schema-dependency mismatch on insert AND on import).
		targetSchemaSlugs: { type: Array, default: () => [] },
		// The currently-selected page's widgets — used to avoid id
		// collisions when insert mints fresh widget ids.
		targetWidgets: { type: Array, default: () => [] },
	},
	emits: ['insert-widgets'],
	data() {
		return {
			blocks: [],
			loading: false,
			categoryFilter: null,
			importError: '',
			confirmDeleteSlug: '',
			remapOpen: false,
			remapDependencies: [],
			// The block currently pending remap resolution, and whether it is
			// for an insert (produces widgets) or an import (finalises a record).
			pendingRemap: null,
		}
	},
	computed: {
		/**
		 * Distinct categories present across the loaded blocks, for the filter.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		categoryOptions() {
			const seen = new Set()
			return this.blocks
				.map((b) => b && b.category)
				.filter((c) => c && !seen.has(c) && seen.add(c))
				.map((c) => ({ id: c, label: c }))
		},
		/**
		 * The visible blocks after the category filter is applied.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		filteredBlocks() {
			const selected =
				this.categoryFilter
				&& (this.categoryFilter.id ?? this.categoryFilter)
			if (!selected) {
				return this.blocks
			}
			return this.blocks.filter((b) => b && b.category === selected)
		},
	},
	watch: {
		/**
		 * Fetch the block list whenever the sidebar panel is opened.
		 *
		 * @param {boolean} value - the new `open` prop value.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		open(value) {
			if (value) {
				this.fetchBlocks()
			}
		},
	},
	/**
	 * Fetch the block list when already open on mount.
	 *
	 * @return {void}
	 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
	 */
	mounted() {
		if (this.open) {
			this.fetchBlocks()
		}
	},
	methods: {
		/**
		 * Fetch every `ComponentBlock` visible to the caller (org-scoped by
		 * OR's standard object listing — REQ "Library lists org-wide blocks").
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async fetchBlocks() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(OR_BLOCKS))
				this.blocks = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (e) {
				this.blocks = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * One-line preview label for a block card.
		 *
		 * @param {object} block - a `ComponentBlock` record.
		 * @return {string}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		previewLabel(block) {
			const fragment = block && block.fragment
			if (isSectionFragment(fragment)) {
				const count = Array.isArray(fragment.widgets)
					? fragment.widgets.length
					: 0
				return t('openbuild', '{count} widget(s) in this section', { count })
			}
			return (
				(fragment && fragment.widgetKey) || t('openbuild', 'Single widget')
			)
		},
		/**
		 * Begin the insert flow for a block: compute schema-dependency
		 * mismatches against the current app; open `BlockRemapDialog` when
		 * any exist, otherwise insert directly.
		 *
		 * @param {object} block - the block to insert.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onInsert(block) {
			const mismatches = computeSchemaMismatches(
				block.schemaDependencies,
				this.targetSchemaSlugs,
			)
			if (mismatches.length === 0) {
				this.emitInsert(block, {})
				return
			}
			this.pendingRemap = { mode: 'insert', block }
			this.remapDependencies = mismatches
			this.remapOpen = true
		},
		/**
		 * Finish the insert flow: build the widgetEntry objects and hand
		 * them to the parent (which merges them via `mergeManifestDelta`).
		 *
		 * @param {object} block - the block being inserted.
		 * @param {{remapMap?: object, unresolvedDependencies?: string[]}} resolution - the resolved remap.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		emitInsert(block, resolution) {
			const widgets = insertBlock(block, {
				remapMap: resolution.remapMap || {},
				unresolvedDependencies: resolution.unresolvedDependencies || [],
				targetWidgets: this.targetWidgets,
			})
			this.$emit('insert-widgets', widgets)
		},
		/**
		 * Trigger a browser download of a block's export JSON.
		 *
		 * @param {object} block - the block to export.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onExport(block) {
			downloadBlockExport(block)
		},
		/**
		 * Read an imported block export file, resolve any schema-dependency
		 * mismatch against the current app's schemas, then create the new
		 * `ComponentBlock`.
		 *
		 * @param {Event} event - the file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async onImportFile(event) {
			this.importError = ''
			const file =
				event && event.target && event.target.files && event.target.files[0]
			event.target.value = ''
			if (!file) {
				return
			}
			try {
				const text = await file.text()
				const record = parseBlockImport(text)
				const mismatches = computeSchemaMismatches(
					record.schemaDependencies,
					this.targetSchemaSlugs,
				)
				if (mismatches.length === 0) {
					await this.createBlock(record)
					return
				}
				this.pendingRemap = { mode: 'import', record }
				this.remapDependencies = mismatches
				this.remapOpen = true
			} catch (e) {
				this.importError =
					e instanceof BlockImportError
						? t('openbuild', 'That file is not a valid block export.')
						: t('openbuild', 'Import failed: {message}', {
								message: e && e.message ? e.message : String(e),
							})
			}
		},
		/**
		 * `BlockRemapDialog` resolution handler — dispatches to the insert
		 * or import completion path depending on which flow opened it.
		 *
		 * @param {{remapMap: object, unresolvedDependencies: string[]}} resolution - the resolved remap.
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async onRemapResolved(resolution) {
			const pending = this.pendingRemap
			this.pendingRemap = null
			if (!pending) {
				return
			}
			if (pending.mode === 'insert') {
				this.emitInsert(pending.block, resolution)
				return
			}
			const finalRecord = remapBlockRecord(
				pending.record,
				resolution.remapMap,
				resolution.unresolvedDependencies,
			)
			await this.createBlock(finalRecord)
		},
		/**
		 * POST a new `ComponentBlock` record and refresh the list.
		 *
		 * @param {object} record - the block record to create.
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async createBlock(record) {
			try {
				await axios.post(generateUrl(OR_BLOCKS), record)
				await this.fetchBlocks()
			} catch (e) {
				const data = e?.response?.data
				this.importError =
					data?.detail
					|| data?.error
					|| e?.message
					|| t('openbuild', 'Import failed.')
			}
		},
		/**
		 * Delete a block after inline confirmation.
		 *
		 * @param {object} block - the block to delete.
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async onDelete(block) {
			this.confirmDeleteSlug = ''
			try {
				const uuid =
					(block['@self'] && block['@self'].id) || block.uuid || block.id
				await axios.delete(
					generateUrl(`${OR_BLOCKS}/${encodeURIComponent(uuid)}`),
				)
				this.blocks = this.blocks.filter((b) => b.slug !== block.slug)
			} catch (e) {
				this.importError = t('openbuild', 'Deleting the block failed.')
			}
		},
	},
}
</script>

<style scoped>
.block-library-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px;
}

.block-library-panel__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.block-library-panel__import {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.85rem;
}

.block-library-panel__error {
	color: var(--color-error);
	margin: 0;
	font-size: 0.85rem;
}

.block-library-panel__loading {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.block-library-panel__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.block-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.block-card__title {
	margin: 0;
	font-size: 0.95rem;
}

.block-card__category {
	font-size: 0.7rem;
	color: var(--color-primary-element);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.block-card__description {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.82rem;
}

.block-card__preview {
	margin: 0;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.block-card__actions {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
}
</style>
