// SPDX-License-Identifier: EUPL-1.2
import { databaseWriteSetting, fetchConnectedDatabases } from '../composables/useConnectedDatabases.js'

/**
 * What a page editor needs to follow a connected database's write setting:
 * the registers OpenRegister serves from a database source, read every time
 * the editor opens and never stored on the Application, and the write
 * actions switched off on the page config while the source takes no writes.
 *
 * The editor names the config keys it locks in `writeActionKeys`.
 *
 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
 */
export const pageEditorDatabaseMixin = {
	data() {
		return {
			databases: [],
		}
	},

	computed: {
		/**
		 * Whether the bound register is a connected database, and whether it takes writes.
		 *
		 * @return {{database: boolean, writable: boolean}}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
		 */
		databaseSetting() {
			return databaseWriteSetting(this.databases, this.config.register || '')
		},

		/**
		 * Whether the bound register is a connected database that takes no writes.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
		 */
		databaseReadOnly() {
			return this.databaseSetting.database && !this.databaseSetting.writable
		},

		/**
		 * The registers served from a database, for the register select's mark.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
		 */
		databaseRegisters() {
			return this.databases.map((database) => database.register)
		},
	},

	watch: {
		databaseReadOnly: {
			/**
			 * Switch the write actions off as soon as the page binds a read-only database.
			 *
			 * @param {boolean} readOnly Whether the bound database takes no writes.
			 * @return {void}
			 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#scenario-a-read-only-database-gets-no-add-button
			 */
			handler(readOnly) {
				if (readOnly) {
					this.lockWriteActions()
				}
			},
		},
	},

	created() {
		this.loadDatabases()
	},

	methods: {
		/**
		 * Read the connected databases and their write setting from OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#scenario-an-administrator-turns-writes-on
		 */
		async loadDatabases() {
			this.databases = await fetchConnectedDatabases()
		},

		/**
		 * Set every write action of this page type to `false` on the config, emitting only when one was not off yet.
		 *
		 * @return {void}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#scenario-a-read-only-database-gets-no-add-button
		 */
		lockWriteActions() {
			const open = this.writeActionKeys.filter((key) => this.config[key] !== false)
			if (open.length === 0) {
				return
			}
			const next = { ...this.config }
			open.forEach((key) => {
				next[key] = false
			})
			this.$emit('update:config', next)
		},

		/**
		 * Show or hide one write action: shown is the default and leaves the key out, hidden stores `false`.
		 *
		 * @param {string} key The config key, such as `showAdd`.
		 * @param {boolean} shown Whether the action shows.
		 * @return {void}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
		 */
		setWriteAction(key, shown) {
			const next = { ...this.config }
			if (shown) {
				delete next[key]
			} else {
				next[key] = false
			}
			this.$emit('update:config', next)
		},
	},
}
