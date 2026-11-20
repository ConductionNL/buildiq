<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Start from a database — part of wizard step 1.
  Offers the connected databases (registers OpenRegister serves from a
  `database` source) and a checklist of their tables. The choice travels on the
  wizard payload as `fromDatabase: {register, schemas, tables}`; the server
  checks it again before anything is created.
  spec: data-external-database-sources REQ-BQDB-003
-->
<template>
	<div v-if="databases.length > 0" class="wizard-database" data-testid="wizard-database">
		<label class="wizard-database__toggle">
			<input
				type="checkbox"
				data-testid="wizard-database-toggle"
				:checked="enabled"
				@change="onToggle($event.target.checked)">
			{{ t('buildiq', 'Start from a database') }}
		</label>

		<div v-if="enabled" class="wizard-database__body">
			<label class="wizard-database__label" for="wizard-database-register">
				{{ t('buildiq', 'Connected database') }}
			</label>
			<select
				id="wizard-database-register"
				class="wizard-database__select"
				data-testid="wizard-database-register"
				:value="register"
				@change="onPickDatabase($event.target.value)">
				<option value="">
					{{ t('buildiq', 'Choose a database') }}
				</option>
				<option
					v-for="database in databases"
					:key="database.register"
					:value="database.register">
					{{ database.label }}
				</option>
			</select>

			<fieldset v-if="register !== ''" class="wizard-database__tables">
				<legend class="wizard-database__label">
					{{ t('buildiq', 'Tables to make pages for') }}
				</legend>
				<p v-if="loading" class="wizard-database__hint">
					{{ t('buildiq', 'Loading tables…') }}
				</p>
				<p v-else-if="tables.length === 0" class="wizard-database__hint">
					{{ t('buildiq', 'This database has no tables yet.') }}
				</p>
				<label
					v-for="table in tables"
					v-else
					:key="table.slug"
					class="wizard-database__table">
					<input
						type="checkbox"
						:value="table.slug"
						:checked="chosen.includes(table.slug)"
						:data-testid="'wizard-database-table-' + table.slug"
						@change="onTickTable(table.slug, $event.target.checked)">
					{{ table.title }}
				</label>
			</fieldset>

			<p v-if="!complete" class="wizard-database__hint" role="status">
				{{ t('buildiq', 'Pick a database and at least one table to continue.') }}
			</p>
			<p v-if="!writable && register !== ''" class="wizard-database__hint">
				{{ t('buildiq', 'This database does not take writes, so its pages only show data.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { fetchConnectedDatabases } from '../../composables/useConnectedDatabases.js'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'

/**
 * A table as the checklist shows it: its slug and a readable title.
 *
 * @param {object} schema A schema from OpenRegister's register schema list.
 * @return {{slug: string, title: string}}
 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
 */
export function tableOption(schema) {
	const slug = String(schema?.slug || schema?.id || '')
	return { slug, title: String(schema?.title || slug) }
}

export default {
	name: 'StartFromDatabase',

	props: {
		/**
		 * The wizard payload; reads `fromDatabase`.
		 */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	data() {
		return {
			databases: [],
			tables: [],
			loading: false,
		}
	},

	computed: {
		/**
		 * Whether the maker chose to start from a database.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		enabled() {
			return !!this.payload.fromDatabase
		},

		/**
		 * The picked database register, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		register() {
			return this.payload.fromDatabase?.register || ''
		},

		/**
		 * The ticked table slugs, in the order ticked.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		chosen() {
			return this.payload.fromDatabase?.schemas || []
		},

		/**
		 * Whether a database and at least one table are chosen.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		complete() {
			return this.register !== '' && this.chosen.length > 0
		},

		/**
		 * Whether the picked database takes writes.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		writable() {
			const match = this.databases.find((database) => database.register === this.register)
			return !match || match.writable === true
		},
	},

	/**
	 * Read the connected databases; with none the section does not show.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
	 */
	async created() {
		this.databases = await fetchConnectedDatabases()
		if (this.register !== '') {
			await this.loadTables(this.register)
		}
	},

	methods: {
		/**
		 * Turn the database path on (empty choice) or off (no `fromDatabase`).
		 *
		 * @param {boolean} on Whether the box is ticked.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		onToggle(on) {
			this.tables = []
			this.$emit('update:payload', { fromDatabase: on ? { register: '', schemas: [], tables: [] } : null })
		},

		/**
		 * Pick a database: clear the ticked tables and load its tables.
		 *
		 * @param {string} register The register slug.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		async onPickDatabase(register) {
			this.$emit('update:payload', { fromDatabase: { register, schemas: [], tables: [] } })
			this.tables = []
			if (register !== '') {
				await this.loadTables(register)
			}
		},

		/**
		 * Tick or untick a table; the payload keeps the ticked tables' titles for the review step.
		 *
		 * @param {string} slug The table slug.
		 * @param {boolean} on Whether it is ticked.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		onTickTable(slug, on) {
			const schemas = this.chosen.filter((entry) => entry !== slug)
			if (on) {
				schemas.push(slug)
			}
			const tables = schemas
				.map((entry) => this.tables.find((table) => table.slug === entry))
				.filter(Boolean)
			this.$emit('update:payload', { fromDatabase: { register: this.register, schemas, tables } })
		},

		/**
		 * Load a database register's tables.
		 *
		 * @param {string} register The register slug.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-generate-an-app-from-a-connected-database-req-bqdb-003
		 */
		async loadTables(register) {
			this.loading = true
			try {
				const schemas = await useRegisterPicker().fetchSchemas(register)
				this.tables = schemas.map(tableOption).filter((table) => table.slug !== '')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.wizard-database {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.wizard-database__toggle,
.wizard-database__table {
	display: flex;
	align-items: center;
	gap: 8px;
}

.wizard-database__body {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wizard-database__label {
	font-weight: 500;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast);
}

.wizard-database__select {
	padding: 8px 10px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.wizard-database__tables {
	display: flex;
	flex-direction: column;
	gap: 4px;
	border: none;
	padding: 0;
	margin: 0;
}

.wizard-database__hint {
	margin: 0;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
