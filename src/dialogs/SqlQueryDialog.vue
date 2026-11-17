<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	SqlQueryDialog — standalone dialog (gate-modal-isolation) opened from the
	schema list by an administrator: "Add a query table".

	Picks a connected database, takes one SQL query, previews at most 50 rows
	through OpenRegister's `POST /api/sources/{id}/query-preview`, and saves the
	query as a read-only table of that database's register through
	OpenRegister's schema API. The query runs in OpenRegister on the source's own
	connection, never in buildiq; OpenRegister refuses anything but one
	read-only statement and anyone but an administrator, whatever this dialog
	shows. A refusal is shown as a plain sentence, never with SQL fragments or
	connection details.

	spec: data-external-database-sources REQ-BQDB-005, REQ-BQDB-006
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Add a query table')"
		size="large"
		@update:open="$emit('update:open', $event)">
		<div class="sql-query">
			<NcSelect
				v-model="database"
				data-testid="sql-query-database"
				:inputLabel="t('buildiq', 'Connected database')"
				:options="databases"
				label="label"
				:clearable="false" />

			<label class="sql-query__label" for="sql-query-text">
				{{ t('buildiq', 'Query') }}
			</label>
			<textarea
				id="sql-query-text"
				v-model="query"
				data-testid="sql-query-text"
				class="sql-query__text"
				rows="6"
				spellcheck="false" />

			<div class="sql-query__actions">
				<NcButton
					data-testid="sql-query-preview"
					variant="secondary"
					:disabled="!canPreview"
					@click="runPreview">
					{{ t('buildiq', 'Run preview') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="error" type="error" data-testid="sql-query-error">
				{{ error }}
			</NcNoteCard>

			<template v-if="preview">
				<p class="sql-query__hint">
					{{ t('buildiq', 'The first rows of the query, at most 50.') }}
				</p>
				<CnDataTable
					data-testid="sql-query-rows"
					:rows="previewRows"
					:columns="previewColumns"
					:loading="false"
					:selectable="false" />
			</template>

			<NcTextField
				:modelValue="name"
				data-testid="sql-query-name"
				:label="t('buildiq', 'Table name')"
				@update:modelValue="name = $event" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('update:open', false)">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				data-testid="sql-query-save"
				variant="primary"
				:disabled="!canSave"
				@click="saveAsTable">
				{{ t('buildiq', 'Save as table') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import { fetchConnectedDatabases } from '../composables/useConnectedDatabases.js'
import { toKebabCase } from '../utils/slugPattern.js'

/**
 * The most rows the preview shows.
 */
export const PREVIEW_ROWS = 50

/**
 * The sentence for a refused preview or save, by HTTP status. OpenRegister's
 * own message is used only where it is a fixed sentence (502, 503); a 400
 * names the rule with a keyword of the query, so it gets the fixed sentence
 * the spec asks for instead.
 *
 * @param {object} [response] The axios error response.
 * @return {string}
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-only-a-safe-query-from-an-administrator-runs-req-bqdb-006
 */
export function refusalMessage(response) {
	const status = response?.status
	const fixed = {
		400: t('buildiq', 'Only a single read-only query can run.'),
		422: t('buildiq', 'Only a single read-only query can run.'),
		401: t('buildiq', 'Only an administrator can run a query.'),
		403: t('buildiq', 'Only an administrator can run a query.'),
		404: t('buildiq', 'This database is no longer connected.'),
	}
	if (status in fixed) {
		return fixed[status]
	}
	if (upstreamSentence(response) !== '') {
		return response.data.error
	}
	return t('buildiq', 'The query could not be run. Try again later.')
}

/**
 * OpenRegister's own sentence for a 502 or 503, or '' for any other answer.
 *
 * @param {object} [response] The axios error response.
 * @return {string}
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-only-a-safe-query-from-an-administrator-runs-req-bqdb-006
 */
function upstreamSentence(response) {
	const error = response?.data?.error
	if (![502, 503].includes(response?.status) || typeof error !== 'string') {
		return ''
	}
	return error
}

/**
 * The table's properties from the preview's columns; a column without a known type is a string.
 *
 * @param {Array<{name: string, type?: string}>} columns The preview columns.
 * @return {object}
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
 */
export function columnProperties(columns) {
	const known = ['string', 'integer', 'number', 'boolean']
	const properties = {}
	for (const column of columns) {
		properties[column.name] = { type: known.includes(column.type) ? column.type : 'string' }
	}
	return properties
}

export default {
	name: 'SqlQueryDialog',

	components: { CnDataTable, NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** Whether the dialog is shown. */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'saved'],

	data() {
		return {
			databases: [],
			database: null,
			query: '',
			name: '',
			preview: null,
			previewedQuery: '',
			error: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * Whether a preview can run: a database and a query, and nothing running.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		canPreview() {
			return !this.busy && this.database !== null && this.query.trim() !== ''
		},

		/**
		 * Whether "Save as table" is on: only after a preview of this very query succeeded, with a name.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-only-a-safe-query-from-an-administrator-runs-req-bqdb-006
		 */
		canSave() {
			return !this.busy
				&& this.preview !== null
				&& this.previewedQuery === this.query
				&& this.name.trim() !== ''
		},

		/**
		 * The preview's columns for the result grid.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		previewColumns() {
			return (this.preview?.columns || []).map((column) => ({ key: column.name, label: column.name }))
		},

		/**
		 * The preview's rows, at most 50.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		previewRows() {
			return (this.preview?.rows || []).slice(0, PREVIEW_ROWS)
		},
	},

	watch: {
		/**
		 * A different database or query invalidates the preview.
		 *
		 * @return {void}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		database() {
			this.preview = null
			this.error = ''
		},
	},

	/**
	 * Read the connected databases for the picker.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
	 */
	async created() {
		this.databases = await fetchConnectedDatabases()
		if (this.databases.length === 1) {
			this.database = this.databases[0]
		}
	},

	methods: {
		/**
		 * Preview the query through OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		async runPreview() {
			const query = this.query
			this.busy = true
			this.error = ''
			this.preview = null
			try {
				const url = generateUrl(`/apps/openregister/api/sources/${this.database.sourceId}/query-preview`)
				const { data } = await axios.post(url, { query })
				this.preview = { columns: Array.isArray(data?.columns) ? data.columns : [], rows: Array.isArray(data?.rows) ? data.rows : [] }
				this.previewedQuery = query
			} catch (err) {
				this.error = refusalMessage(err?.response)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Save the previewed query as a read-only table of the database's register.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-administrator-saves-a-sql-query-as-a-table-req-bqdb-005
		 */
		async saveAsTable() {
			this.busy = true
			this.error = ''
			try {
				const title = this.name.trim()
				const { data: schema } = await axios.post(generateUrl('/apps/openregister/api/schemas'), {
					title,
					slug: toKebabCase(title),
					properties: columnProperties(this.preview.columns),
					configuration: {
						'x-openregister-object-source': {
							provider: 'dbal-source',
							readOnly: true,
							config: { sourceId: this.database.sourceId, query: this.previewedQuery },
						},
					},
				})
				const register = this.database.register
				const { data: current } = await axios.get(generateUrl(`/apps/openregister/api/registers/${register}`))
				const schemas = Array.isArray(current?.schemas) ? current.schemas.map((entry) => entry?.id ?? entry) : []
				await axios.patch(generateUrl(`/apps/openregister/api/registers/${register}`), { schemas: [...schemas, schema.id] })
				this.$emit('saved', { register, schema: schema.slug || toKebabCase(title) })
				this.$emit('update:open', false)
			} catch (err) {
				this.error = refusalMessage(err?.response)
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.sql-query {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.sql-query__label {
	font-weight: 500;
}

.sql-query__text {
	font-family: var(--font-face-monospace, monospace);
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.sql-query__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
