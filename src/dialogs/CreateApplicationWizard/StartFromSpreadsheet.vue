<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Start from a spreadsheet — part of wizard step 1.
  The maker picks a CSV, XLS or XLSX file; it travels on the wizard payload as
  `fromFile: {name, mime, content}` (content base64). The server reads the
  header row into one schema and imports the rows through OpenRegister, and
  rolls the whole app back when the import fails.
  spec: capability-requirements-backfill REQ-BQCV-001
-->
<template>
	<div class="wizard-spreadsheet" data-testid="wizard-spreadsheet">
		<label class="wizard-spreadsheet__toggle">
			<input
				type="checkbox"
				data-testid="wizard-spreadsheet-toggle"
				:checked="enabled"
				:disabled="databaseChosen"
				@change="onToggle($event.target.checked)">
			{{ t('buildiq', 'Start from a spreadsheet') }}
		</label>
		<p v-if="databaseChosen" class="wizard-spreadsheet__hint">
			{{ t('buildiq', 'Turn off "Start from a database" to start from a spreadsheet.') }}
		</p>

		<div v-if="enabled" class="wizard-spreadsheet__body">
			<label class="wizard-spreadsheet__label" for="wizard-spreadsheet-file">
				{{ t('buildiq', 'Spreadsheet (CSV, XLS or XLSX)') }}
			</label>
			<input
				id="wizard-spreadsheet-file"
				type="file"
				accept=".csv,.xls,.xlsx"
				data-testid="wizard-spreadsheet-file"
				@change="onPickFile($event.target.files && $event.target.files[0])">
			<p class="wizard-spreadsheet__hint">
				{{ t('buildiq', 'The first row holds the column names. Each column becomes a field, each row a record, and the app opens on a list of them.') }}
			</p>
			<p v-if="error" class="wizard-spreadsheet__error" role="alert" data-testid="wizard-spreadsheet-error">
				{{ error }}
			</p>
			<p v-else-if="fileName" class="wizard-spreadsheet__hint" data-testid="wizard-spreadsheet-chosen">
				{{ t('buildiq', 'Chosen: {name}', { name: fileName }) }}
			</p>
			<p v-else class="wizard-spreadsheet__hint" role="status">
				{{ t('buildiq', 'Choose a file to continue.') }}
			</p>
		</div>
	</div>
</template>

<script>
/**
 * The largest file the wizard takes (10 MiB), the same limit the server checks.
 *
 * @type {number}
 */
export const MAX_SPREADSHEET_BYTES = 10 * 1024 * 1024

/**
 * The file extensions the wizard takes.
 *
 * @type {Array<string>}
 */
export const SPREADSHEET_EXTENSIONS = ['csv', 'xls', 'xlsx']

/**
 * Why a picked file cannot be used, or null when it can.
 *
 * @param {{name: string, size: number}|null} file The picked file.
 * @return {string|null}
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
export function spreadsheetError(file) {
	if (!file) {
		return t('buildiq', 'Choose a file to continue.')
	}
	const extension = String(file.name || '').split('.').pop().toLowerCase()
	if (!String(file.name || '').includes('.') || !SPREADSHEET_EXTENSIONS.includes(extension)) {
		return t('buildiq', 'Choose a CSV, XLS or XLSX file.')
	}
	if (!file.size) {
		return t('buildiq', 'The file is empty.')
	}
	if (file.size > MAX_SPREADSHEET_BYTES) {
		return t('buildiq', 'The file is larger than 10 MB.')
	}
	return null
}

/**
 * A file's contents as base64, without the data-URL prefix.
 *
 * @param {Blob} file The file.
 * @return {Promise<string>}
 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
 */
export function readAsBase64(file) {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => {
			const url = String(reader.result || '')
			resolve(url.slice(url.indexOf(',') + 1))
		}
		reader.onerror = () => reject(reader.error || new Error('The file could not be read.'))
		reader.readAsDataURL(file)
	})
}

export default {
	name: 'StartFromSpreadsheet',

	props: {
		/**
		 * The wizard payload; reads `fromFile` and `fromDatabase`.
		 */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	data() {
		return {
			error: null,
		}
	},

	computed: {
		/**
		 * Whether the maker chose to start from a spreadsheet.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
		 */
		enabled() {
			return !!this.payload.fromFile
		},

		/**
		 * Whether "Start from a database" is on; the two starting points exclude each other.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
		 */
		databaseChosen() {
			return !!this.payload.fromDatabase
		},

		/**
		 * The chosen file's name, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
		 */
		fileName() {
			return this.payload.fromFile?.content ? this.payload.fromFile.name : ''
		},
	},

	methods: {
		/**
		 * Turn the spreadsheet path on (no file yet) or off (no `fromFile`).
		 *
		 * @param {boolean} on Whether the box is ticked.
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
		 */
		onToggle(on) {
			this.error = null
			this.$emit('update:payload', { fromFile: on ? { name: '', mime: '', content: '' } : null })
		},

		/**
		 * Check and read a picked file onto the payload; a file that cannot be used leaves no contents.
		 *
		 * @param {File|null} file The picked file.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/data-import-wizard/spec.md#requirement-a-maker-creates-a-new-app-from-an-uploaded-spreadsheet-req-bqcv-001
		 */
		async onPickFile(file) {
			this.error = spreadsheetError(file)
			if (this.error !== null) {
				this.$emit('update:payload', { fromFile: { name: '', mime: '', content: '' } })
				return
			}
			try {
				const content = await readAsBase64(file)
				this.$emit('update:payload', { fromFile: { name: file.name, mime: file.type || '', content } })
			} catch {
				this.error = t('buildiq', 'The file could not be read.')
				this.$emit('update:payload', { fromFile: { name: '', mime: '', content: '' } })
			}
		},
	},
}
</script>

<style scoped>
.wizard-spreadsheet {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.wizard-spreadsheet__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
}

.wizard-spreadsheet__body {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wizard-spreadsheet__label {
	font-weight: 500;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.wizard-spreadsheet__hint {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.wizard-spreadsheet__error {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-error-text);
}
</style>
