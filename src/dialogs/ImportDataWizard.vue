<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ImportDataWizard — a stepped "Import data" dialog that lets a maker seed a
	virtual app's data from an uploaded xlsx/xls/csv/json file. ALL file
	parsing, schema inference, and object creation are delegated to
	OpenRegister's shipped register-import (via `useDataImport`); this dialog
	only collects the target + file, shows a preview, POSTs to OR, and renders
	the summary OR returns (ADR-022, one write path). It writes into the ACTIVE
	version's own per-version register only (`registerId` prop) — shared bound
	`dataRegisters` are excluded upstream, so they never appear as targets.

	Steps: (1) choose target — existing schema OR create-from-file; (2) upload;
	(3) preview (target columns for an existing schema; header cells for
	create-from-file) + a display-only first-rows sample for CSV; (4) confirm +
	import; (5) result summary with Undo (rollback).

	@spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
-->
<template>
	<NcDialog
		:name="t('openbuild', 'Import data')"
		:can-close="!importing"
		size="large"
		@closing="onClose">
		<div class="ob-import-wizard">
			<!-- Step rail -->
			<ol class="ob-import-wizard__steps" aria-hidden="true">
				<li
					v-for="s in stepLabels"
					:key="s.n"
					class="ob-import-wizard__step"
					:class="{
						'ob-import-wizard__step--active': step === s.n,
						'ob-import-wizard__step--done': step > s.n,
					}">
					<span class="ob-import-wizard__step-n">{{ s.n }}</span>
					<span class="ob-import-wizard__step-label">{{ s.label }}</span>
				</li>
			</ol>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Step 1: choose target -->
			<section v-if="step === 1" class="ob-import-wizard__panel">
				<h3 class="ob-import-wizard__heading">
					{{ t('openbuild', 'Where should the data go?') }}
				</h3>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					value="existing"
					name="ob-import-target"
					type="radio">
					{{ t('openbuild', 'Add rows to an existing schema') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="targetMode"
					value="create"
					name="ob-import-target"
					type="radio">
					{{ t('openbuild', 'Create a new schema from the file') }}
				</NcCheckboxRadioSwitch>

				<template v-if="targetMode === 'existing'">
					<NcSelect
						v-model="selectedSchema"
						class="ob-import-wizard__schema-select"
						:input-label="t('openbuild', 'Target schema')"
						:options="schemaOptions"
						:placeholder="
							schemaOptions.length
								? t('openbuild', 'Choose a schema')
								: t('openbuild', 'No schemas in this version yet')
						"
						label="label"
						:disabled="!schemaOptions.length" />
					<NcButton
						v-if="selectedSchema"
						type="tertiary"
						class="ob-import-wizard__template-btn"
						@click="downloadTemplate">
						<template #icon>
							<DownloadIcon :size="20" />
						</template>
						{{ t('openbuild', 'Download a matching template') }}
					</NcButton>
				</template>
				<p v-else class="ob-import-wizard__hint">
					{{
						t(
							'openbuild',
							"OpenRegister reads the file's header row to infer the fields and creates the schema for you.",
						)
					}}
				</p>
			</section>

			<!-- Step 2: upload -->
			<section v-else-if="step === 2" class="ob-import-wizard__panel">
				<h3 class="ob-import-wizard__heading">
					{{ t('openbuild', 'Choose a file') }}
				</h3>
				<input
					ref="fileInput"
					:aria-label="t('openbuild', 'Choose a file')"
					type="file"
					class="ob-import-wizard__file-input"
					accept=".xlsx,.xls,.csv,.json"
					@change="onFileChosen" />
				<NcButton type="secondary" @click="pickFile">
					<template #icon>
						<UploadIcon :size="20" />
					</template>
					{{
						file
							? t('openbuild', 'Choose a different file')
							: t('openbuild', 'Select xlsx, xls, csv or json')
					}}
				</NcButton>
				<div v-if="file" class="ob-import-wizard__file-meta">
					<strong>{{ file.name }}</strong>
					<span>{{ detectedTypeLabel }} · {{ humanSize }}</span>
				</div>
				<NcNoteCard v-if="file && isLargeFile" type="warning">
					{{
						t(
							'openbuild',
							'Large files import synchronously and may take a while. Consider splitting very large spreadsheets.',
						)
					}}
				</NcNoteCard>
			</section>

			<!-- Step 3: preview -->
			<section v-else-if="step === 3" class="ob-import-wizard__panel">
				<h3 class="ob-import-wizard__heading">
					{{ t('openbuild', 'Preview') }}
				</h3>
				<p class="ob-import-wizard__hint">
					{{
						targetMode === 'existing'
							? t(
									'openbuild',
									'Rows will be mapped onto these schema fields by matching column headers.',
								)
							: t(
									'openbuild',
									'OpenRegister will infer these fields from the file and create the schema.',
								)
					}}
				</p>
				<ul v-if="previewColumns.length" class="ob-import-wizard__columns">
					<li
						v-for="col in previewColumns"
						:key="col.name"
						class="ob-import-wizard__column">
						<span class="ob-import-wizard__column-name">{{
							col.name
						}}</span>
						<span
							v-if="col.type"
							class="ob-import-wizard__column-type"
							>{{ col.type }}</span
						>
					</li>
				</ul>
				<p v-else class="ob-import-wizard__hint">
					{{
						t(
							'openbuild',
							'Field preview is not available for this file type; OpenRegister will parse it on import.',
						)
					}}
				</p>

				<template v-if="sampleRows.length">
					<h4 class="ob-import-wizard__subheading">
						{{ t('openbuild', 'First rows') }}
					</h4>
					<div class="ob-import-wizard__sample-scroll">
						<!--
							`sampleRows` is populated only by readCsvSample(), and a
							CSV's first line IS its header row — it was being rendered
							as ordinary <td> data, so the table had no <th> at all.
							Promoting row 0 to a <thead> of <th scope="col"> is both
							the WCAG 1.3.1 fix (a screen reader can now announce which
							column a cell belongs to) and a truer rendering of the file.
						-->
						<table class="ob-import-wizard__sample">
							<thead v-if="sampleHeader.length">
								<tr>
									<th
										v-for="(cell, ci) in sampleHeader"
										:key="'h-' + ci"
										scope="col">
										{{ cell }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(row, ri) in sampleBody" :key="ri">
									<td v-for="(cell, ci) in row" :key="ci">
										{{ cell }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</template>
			</section>

			<!-- Step 4: confirm -->
			<section v-else-if="step === 4" class="ob-import-wizard__panel">
				<h3 class="ob-import-wizard__heading">
					{{ t('openbuild', 'Ready to import') }}
				</h3>
				<p class="ob-import-wizard__hint">
					{{ confirmText }}
				</p>
				<div v-if="importing" class="ob-import-wizard__importing">
					<NcLoadingIcon :size="32" />
					<span>{{ t('openbuild', 'Importing via OpenRegister…') }}</span>
				</div>
			</section>

			<!-- Step 5: result -->
			<section v-else-if="step === 5" class="ob-import-wizard__panel">
				<h3 class="ob-import-wizard__heading">
					{{ t('openbuild', 'Import complete') }}
				</h3>
				<div class="ob-import-wizard__counts">
					<div class="ob-import-wizard__count">
						<span class="ob-import-wizard__count-n">{{
							result.created
						}}</span>
						<span class="ob-import-wizard__count-label">{{
							t('openbuild', 'created')
						}}</span>
					</div>
					<div class="ob-import-wizard__count">
						<span class="ob-import-wizard__count-n">{{
							result.updated
						}}</span>
						<span class="ob-import-wizard__count-label">{{
							t('openbuild', 'updated')
						}}</span>
					</div>
					<div class="ob-import-wizard__count">
						<span class="ob-import-wizard__count-n">{{
							result.skipped
						}}</span>
						<span class="ob-import-wizard__count-label">{{
							t('openbuild', 'skipped')
						}}</span>
					</div>
				</div>
				<NcNoteCard v-if="result.errors.length" type="warning">
					{{
						n(
							'openbuild',
							'%n row could not be imported.',
							'%n rows could not be imported.',
							result.errors.length,
						)
					}}
				</NcNoteCard>
				<ul v-if="result.errors.length" class="ob-import-wizard__errors">
					<li v-for="(err, i) in result.errors.slice(0, 10)" :key="i">
						{{ err.message || err.error || JSON.stringify(err) }}
					</li>
				</ul>
				<NcButton
					v-if="result.errorsCsv"
					type="tertiary"
					@click="downloadErrorCsv">
					<template #icon>
						<DownloadIcon :size="20" />
					</template>
					{{ t('openbuild', 'Download error report') }}
				</NcButton>
			</section>
		</div>

		<template #actions>
			<NcButton
				v-if="step > 1 && step < 5 && !importing"
				type="tertiary"
				@click="back">
				{{ t('openbuild', 'Back') }}
			</NcButton>
			<NcButton
				v-if="step < 4"
				type="primary"
				:disabled="!canAdvance"
				@click="next">
				{{ t('openbuild', 'Next') }}
			</NcButton>
			<NcButton
				v-else-if="step === 4"
				type="primary"
				:disabled="importing"
				@click="runImport">
				{{ t('openbuild', 'Import') }}
			</NcButton>
			<template v-else>
				<NcButton
					v-if="result.importJobId"
					type="warning"
					:disabled="undoing"
					@click="undo">
					{{
						undoing
							? t('openbuild', 'Undoing…')
							: t('openbuild', 'Undo import')
					}}
				</NcButton>
				<NcButton type="primary" @click="onClose">
					{{ t('openbuild', 'Done') }}
				</NcButton>
			</template>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import DownloadIcon from 'vue-material-design-icons/Download.vue'
import UploadIcon from 'vue-material-design-icons/Upload.vue'

import { useDataImport } from '../composables/useDataImport.js'

const MAX_SAMPLE_ROWS = 5
const LARGE_FILE_BYTES = 5 * 1024 * 1024

export default {
	name: 'ImportDataWizard',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcNoteCard,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
		DownloadIcon,
		UploadIcon,
	},
	props: {
		/**
		 * The target register slug — ALWAYS the active version's own
		 * per-version register. Shared bound `dataRegisters` are excluded by
		 * the caller, so only this register is ever an import target.
		 */
		registerId: { type: String, required: true },
		/**
		 * Schemas available in the target register (id/slug/name/title/type).
		 * These are the only selectable existing-schema targets.
		 */
		schemas: { type: Array, default: () => [] },
		/** Pre-selected schema slug when opened from the Schema Designer. */
		initialSchema: { type: String, default: '' },
		/**
		 * Injectable data-import client (tests pass a stub). Defaults to the
		 * real `useDataImport()` bound to `@nextcloud/axios`.
		 */
		importClient: { type: Object, default: null },
	},
	emits: ['close', 'imported'],
	data() {
		return {
			step: 1,
			targetMode: this.initialSchema ? 'existing' : 'existing',
			selectedSchema: null,
			file: null,
			previewColumns: [],
			sampleRows: [],
			importing: false,
			undoing: false,
			error: '',
			result: {
				importJobId: null,
				created: 0,
				updated: 0,
				skipped: 0,
				errors: [],
				errorsCsv: null,
				errorsCsvFilename: null,
			},
			importer: this.importClient || useDataImport(),
		}
	},
	computed: {
		/**
		 * The sample table's header cells — the CSV's own header line.
		 *
		 * `sampleRows` is filled only by `readCsvSample()`, and a CSV's first
		 * line is its header row, so row 0 is the header by construction. It
		 * used to render as ordinary `<td>` data, which left the sample table
		 * with no `<th>` at all (WCAG 2.2 AA 1.3.1 Info and Relationships).
		 *
		 * @return {Array<string>} Header cells, empty when there is no sample.
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		sampleHeader() {
			return this.sampleRows.length > 0 ? this.sampleRows[0] : []
		},
		/**
		 * The sample table's data rows — everything after the header line.
		 *
		 * @return {Array<Array<string>>} Data rows, empty when the sample is
		 *                                header-only or absent.
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		sampleBody() {
			return this.sampleRows.slice(1)
		},
		/**
		 * Step-rail labels.
		 *
		 * @return {Array<{n: number, label: string}>}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		stepLabels() {
			return [
				{ n: 1, label: t('openbuild', 'Target') },
				{ n: 2, label: t('openbuild', 'File') },
				{ n: 3, label: t('openbuild', 'Preview') },
				{ n: 4, label: t('openbuild', 'Confirm') },
				{ n: 5, label: t('openbuild', 'Result') },
			]
		},
		/**
		 * Schema options for the NcSelect, projected from the schemas prop.
		 * Only the active version register's schemas are passed in, so shared
		 * bound `dataRegisters` never appear as targets.
		 *
		 * @return {Array<{id: string, label: string, type: object}>}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		schemaOptions() {
			return (this.schemas || [])
				.map((s) => ({
					id: String(
						s.slug
							|| (s['@self'] && s['@self'].slug)
							|| s.id
							|| s.uuid
							|| '',
					),
					label: String(s.title || s.name || s.slug || s.id || ''),
					type: s,
				}))
				.filter((o) => o.id)
		},
		/**
		 * OR import type inferred from the file extension (display only; OR
		 * re-detects server-side).
		 *
		 * @return {string}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		detectedType() {
			if (!this.file) {
				return ''
			}
			const ext = String(this.file.name || '')
				.split('.')
				.pop()
				.toLowerCase()
			if (ext === 'xlsx' || ext === 'xls') {
				return 'excel'
			}
			if (ext === 'csv') {
				return 'csv'
			}
			if (ext === 'json') {
				return 'json'
			}
			return ''
		},
		/**
		 * Human label for the detected file type.
		 *
		 * @return {string}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		detectedTypeLabel() {
			const map = {
				excel: t('openbuild', 'Excel spreadsheet'),
				csv: t('openbuild', 'CSV file'),
				json: t('openbuild', 'JSON export'),
			}
			return map[this.detectedType] || t('openbuild', 'Unsupported file type')
		},
		/**
		 * Human-readable file size.
		 *
		 * @return {string}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		humanSize() {
			const b = this.file ? this.file.size : 0
			if (b < 1024) {
				return `${b} B`
			}
			if (b < 1024 * 1024) {
				return `${Math.round(b / 1024)} KB`
			}
			return `${(b / (1024 * 1024)).toFixed(1)} MB`
		},
		/**
		 * Whether the chosen file exceeds the synchronous-import size hint.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		isLargeFile() {
			return !!this.file && this.file.size > LARGE_FILE_BYTES
		},
		/**
		 * Confirmation-step summary text.
		 *
		 * @return {string}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		confirmText() {
			const fileName = this.file ? this.file.name : ''
			if (this.targetMode === 'existing') {
				const label = this.selectedSchema ? this.selectedSchema.label : ''
				return t(
					'openbuild',
					'Import "{file}" into the "{schema}" schema. OpenRegister parses the file and writes the rows.',
					{ file: fileName, schema: label },
				)
			}
			return t(
				'openbuild',
				'Import "{file}" as a new schema. OpenRegister infers the fields from the header row and writes the rows.',
				{ file: fileName },
			)
		},
		/**
		 * Whether the current step's requirements are met to advance.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		canAdvance() {
			if (this.step === 1) {
				return this.targetMode === 'create' || !!this.selectedSchema
			}
			if (this.step === 2) {
				return !!this.file && this.detectedType !== ''
			}
			return true
		},
	},
	watch: {
		/**
		 * Clear any stale error when the target schema changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		selectedSchema() {
			this.error = ''
		},
	},
	/**
	 * Pre-select the schema the wizard was opened on (Schema Designer entry).
	 *
	 * @return {void}
	 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
	 */
	mounted() {
		if (this.initialSchema) {
			const match = this.schemaOptions.find((o) => o.id === this.initialSchema)
			if (match) {
				this.selectedSchema = match
			}
		}
	},
	methods: {
		/**
		 * Open the native file picker.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		pickFile() {
			if (this.$refs.fileInput) {
				this.$refs.fileInput.click()
			}
		},
		/**
		 * Store the chosen file. For CSV we read the header + first rows for a
		 * DISPLAY-ONLY preview (no schema inference, no transform, no persist —
		 * OpenRegister still performs the authoritative parse + write).
		 *
		 * @param {Event} evt The file input change event.
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		onFileChosen(evt) {
			const f = evt && evt.target && evt.target.files && evt.target.files[0]
			this.file = f || null
			this.error = ''
			this.previewColumns = []
			this.sampleRows = []
		},
		/**
		 * Build the preview: target-schema columns (from OR metadata) for the
		 * existing-schema path, plus a display-only CSV sample.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		buildPreview() {
			// Existing-schema target: show the schema's own fields as the
			// columns rows are mapped onto (sourced from OR-provided schema
			// metadata, never from parsing the file).
			if (
				this.targetMode === 'existing'
				&& this.selectedSchema
				&& this.selectedSchema.type
			) {
				const schema = this.selectedSchema.type
				const props =
					schema.properties
					|| (schema.schema && schema.schema.properties)
					|| {}
				this.previewColumns = Object.keys(props).map((name) => ({
					name,
					type: (props[name] && props[name].type) || '',
				}))
			}
			// CSV: a display-only glimpse of the file's own header + first rows.
			if (this.detectedType === 'csv') {
				this.readCsvSample()
			}
		},
		/**
		 * Read the first lines of a CSV for a display-only sample. This does
		 * NOT infer a schema or write anything — OpenRegister owns the
		 * authoritative parse. For create-from-file it also surfaces the header
		 * cells as the columns OR will create.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		readCsvSample() {
			if (!this.file || typeof FileReader === 'undefined') {
				return
			}
			const reader = new FileReader()
			reader.onload = () => {
				const text = String(reader.result || '')
				const lines = text
					.split(/\r?\n/)
					.filter((l) => l.length > 0)
					.slice(0, MAX_SAMPLE_ROWS + 1)
				const rows = lines.map((l) => l.split(','))
				this.sampleRows = rows
				if (this.targetMode === 'create' && rows.length > 0) {
					this.previewColumns = rows[0].map((name) => ({
						name: name.trim(),
						type: '',
					}))
				}
			}
			reader.onerror = () => {
				// Non-fatal: the sample is display-only; import still works.
				this.sampleRows = []
			}
			// Only read a small slice — the sample is cosmetic.
			reader.readAsText(this.file.slice(0, 64 * 1024))
		},
		/**
		 * Advance to the next step, building the preview when leaving upload.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		next() {
			if (!this.canAdvance) {
				return
			}
			if (this.step === 2) {
				this.buildPreview()
			}
			this.step += 1
		},
		/**
		 * Go back one step.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		back() {
			if (this.step > 1) {
				this.step -= 1
			}
		},
		/**
		 * Run the import by delegating to OpenRegister. OpenBuild uploads the
		 * file bytes only; OR parses, infers, and writes.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		async runImport() {
			this.importing = true
			this.error = ''
			try {
				const summary = await this.importer.importFile({
					registerId: this.registerId,
					file: this.file,
					schema:
						this.targetMode === 'existing' && this.selectedSchema
							? this.selectedSchema.id
							: undefined,
					includeObjects: true,
				})
				this.result = summary
				this.step = 5
				this.$emit('imported', summary)
			} catch (e) {
				this.error = this.readError(e)
			} finally {
				this.importing = false
			}
		},
		/**
		 * Undo the import via OpenRegister's rollback endpoint.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		async undo() {
			if (!this.result.importJobId) {
				return
			}
			this.undoing = true
			this.error = ''
			try {
				await this.importer.rollbackImport(this.result.importJobId)
				this.result = {
					...this.result,
					importJobId: null,
					created: 0,
					updated: 0,
					skipped: 0,
				}
				this.$emit('imported', { rolledBack: true })
			} catch (e) {
				this.error = this.readError(e)
			} finally {
				this.undoing = false
			}
		},
		/**
		 * Download the offline import template for the selected schema.
		 *
		 * @param {string} [format] `xlsx` (default) or `csv`.
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		downloadTemplate(format = 'xlsx') {
			if (!this.selectedSchema) {
				return
			}
			const url = this.importer.templateUrl({
				registerId: this.registerId,
				schema: this.selectedSchema.id,
				format,
			})
			if (url && typeof window !== 'undefined') {
				window.location.href = url
			}
		},
		/**
		 * Download the per-row error report OR returned (base64 CSV).
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		downloadErrorCsv() {
			if (!this.result.errorsCsv || typeof document === 'undefined') {
				return
			}
			const a = document.createElement('a')
			a.href = `data:text/csv;base64,${this.result.errorsCsv}`
			a.download = this.result.errorsCsvFilename || 'import_errors.csv'
			document.body.appendChild(a)
			a.click()
			document.body.removeChild(a)
		},
		/**
		 * Extract a human error message from an OR error (e.g. 403 manage gate).
		 *
		 * @param {Error|object} e The rejected error/response.
		 * @return {string}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		readError(e) {
			const data = e && e.response && e.response.data
			if (data && data.error) {
				return String(data.error)
			}
			return e && e.message
				? String(e.message)
				: t('openbuild', 'Import failed.')
		},
		/**
		 * Close the wizard.
		 *
		 * @return {void}
		 * @spec openspec/changes/openbuild-data-import-wizard/tasks.md#2.1
		 */
		onClose() {
			this.$emit('close')
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-import-wizard {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 4px;
	min-height: 260px;
}

.ob-import-wizard__steps {
	list-style: none;
	display: flex;
	gap: 8px;
	margin: 0;
	padding: 0;
}

.ob-import-wizard__step {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 1;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-import-wizard__step-n {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	border-radius: 50%;
	background: var(--color-background-dark, #f0f0f0);
	font-weight: 600;
}

.ob-import-wizard__step--active .ob-import-wizard__step-n {
	background: var(--color-primary-element, #21468b);
	color: var(--color-primary-element-text, #fff);
}

.ob-import-wizard__step--done .ob-import-wizard__step-n {
	background: var(--color-success, #2d7d46);
	color: #fff;
}

.ob-import-wizard__panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ob-import-wizard__heading {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-import-wizard__subheading {
	margin: 8px 0 0;
	font-size: 13px;
	font-weight: 600;
}

.ob-import-wizard__hint {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}

.ob-import-wizard__file-input {
	display: none;
}

.ob-import-wizard__file-meta {
	display: flex;
	flex-direction: column;
	font-size: 13px;
}

.ob-import-wizard__columns {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.ob-import-wizard__column {
	display: inline-flex;
	gap: 6px;
	align-items: baseline;
	padding: 3px 10px;
	border-radius: 12px;
	background: var(--color-background-hover, #f5f5f5);
	border: 1px solid var(--color-border, #ddd);
	font-size: 13px;
}

.ob-import-wizard__column-type {
	font-size: 11px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-import-wizard__sample-scroll {
	overflow-x: auto;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.ob-import-wizard__sample {
	border-collapse: collapse;
	font-size: 12px;
	width: 100%;
}

.ob-import-wizard__sample td {
	border: 1px solid var(--color-border, #ddd);
	padding: 4px 8px;
	white-space: nowrap;
}

.ob-import-wizard__counts {
	display: flex;
	gap: 24px;
}

.ob-import-wizard__count {
	display: flex;
	flex-direction: column;
	align-items: center;
}

.ob-import-wizard__count-n {
	font-size: 28px;
	font-weight: 700;
}

.ob-import-wizard__count-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.ob-import-wizard__errors {
	margin: 0;
	padding-left: 18px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-import-wizard__importing {
	display: flex;
	align-items: center;
	gap: 12px;
	color: var(--color-text-maxcontrast, #666);
}
</style>
