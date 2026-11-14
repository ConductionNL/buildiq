<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RecordGridPage — the runtime page behind a "Grid" page of a built app
  - (manifest `type: "custom"`, `component: "record-grid"`). It shows one
  - schema's records as a spreadsheet-like grid over nextcloud-vue's
  - CnDataMatrix and saves each committed cell as a single-property PATCH on
  - its record.
  -
  - A column is editable only when the maker marked it editable and the
  - schema does not declare the property `readOnly`; whether the user may
  - change the record is the server's call, and a refused save restores the
  - old value and says why. Before the first save on a row the page takes
  - the record's lock (ADR-033); a row a colleague holds is refused and names
  - the colleague.
  -->
<template>
	<div class="record-grid-page">
		<h2 v-if="title" class="record-grid-page__title">
			{{ title }}
		</h2>
		<NcNoteCard v-if="!configured" type="info">
			{{ t('buildiq', 'This grid has no register and schema yet.') }}
		</NcNoteCard>
		<template v-else>
			<NcNoteCard v-if="loadError" type="error">
				{{ loadError }}
			</NcNoteCard>
			<NcNoteCard v-if="notice" :type="notice.type">
				<span role="alert">{{ notice.text }}</span>
			</NcNoteCard>
			<CnDataMatrix
				:rows="rows"
				:columns="matrixColumns"
				rowIdKey="id"
				rowLabelKey="label"
				rowSublabelKey="lockNote"
				:rowHeader="t('buildiq', 'Record')"
				:emptyLabel="
					loading
						? t('buildiq', 'Loading records…')
						: t('buildiq', 'No records yet.')
				"
				@cellEdit="onCellEdit" />
			<nav
				v-if="pages > 1"
				class="record-grid-page__pager"
				:aria-label="t('buildiq', 'Grid pages')">
				<NcButton :disabled="page <= 1 || loading" @click="goTo(page - 1)">
					{{ t('buildiq', 'Previous') }}
				</NcButton>
				<span>{{
					t('buildiq', 'Page {page} of {pages}', { page, pages })
				}}</span>
				<NcButton
					:disabled="page >= pages || loading"
					@click="goTo(page + 1)">
					{{ t('buildiq', 'Next') }}
				</NcButton>
			</nav>
		</template>
	</div>
</template>

<script>
import {
	CnDataMatrix,
	LockConflictError,
	useObjectLock,
	useObjectStore,
} from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { columnKey } from '../../utils/gridColumns.js'

// Records per grid page; the grid pages like an index page and never loads
// the whole schema.
export const GRID_PAGE_SIZE = 50

/**
 * The lock a record carries in its `@self` block, when it is still running.
 *
 * @param {object} record - an OpenRegister object.
 * @return {?{user?: string, displayName?: string, expiresAt?: string}} the lock, or null.
 */
export function activeLock(record) {
	const lock = (record && record['@self'] && record['@self'].locked) || null
	if (!lock || typeof lock !== 'object') {
		return null
	}
	if (lock.expiresAt) {
		const expires = new Date(lock.expiresAt).getTime()
		if (Number.isFinite(expires) && expires <= Date.now()) {
			return null
		}
	}
	return lock
}

/**
 * The name a grid row is shown under: the record's own name or title,
 * else its id.
 *
 * @param {object} record - an OpenRegister object.
 * @return {string} the row label.
 */
function recordLabel(record) {
	const self = record['@self'] || {}
	return String(self.name || record.name || record.title || record.id || '')
}

export default {
	name: 'RecordGridPage',
	components: { CnDataMatrix, NcButton, NcNoteCard },

	props: {
		/** Page title from the manifest (lifted by CnPageRenderer). */
		title: { type: String, default: '' },
		/** Register slug the records live in. */
		register: { type: String, default: '' },
		/** Schema slug of the records. */
		schema: { type: String, default: '' },
		/** Columns: property keys, or `{ key, label }`. */
		columns: { type: Array, default: () => [] },
		/** Keys of the columns the maker marked editable. */
		editable: { type: Array, default: () => [] },
	},

	/**
	 * One lock handle follows the row being edited: its id is swapped when
	 * the user moves to another row, after the previous lock is released.
	 *
	 * @param {object} props - the component props.
	 * @return {object} the store, the lock handle and the locked row id.
	 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t02
	 */
	setup(props) {
		const store = useObjectStore()
		const lockedRowId = ref('')
		const typeSlug = computed(() => `${props.register}-${props.schema}`)
		const lock = useObjectLock(
			store,
			computed(() => props.register),
			typeSlug,
			lockedRowId,
			{ schemaSlug: computed(() => props.schema) },
		)
		return { store, lock, lockedRowId }
	},

	data() {
		return {
			records: [],
			schemaProperties: {},
			loading: false,
			loadError: '',
			notice: null,
			page: 1,
			pages: 1,
			// Locks met while saving, by record id: the holder's name.
			lockConflicts: {},
		}
	},

	computed: {
		/**
		 * Whether the page names a register and a schema.
		 *
		 * @return {boolean}
		 */
		configured() {
			return Boolean(this.register && this.schema)
		},

		/**
		 * The store's type slug for this register and schema.
		 *
		 * @return {string}
		 */
		typeSlug() {
			return `${this.register}-${this.schema}`
		},

		/**
		 * The matrix columns: label, number type from the schema, and
		 * read-only unless the maker marked the column editable and the schema
		 * lets the property be written.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t01
		 */
		matrixColumns() {
			return this.columns
				.map((column) => {
					const key = columnKey(column)
					if (!key) {
						return null
					}
					const property = this.schemaProperties[key] || {}
					const label =
						(typeof column === 'object' && column.label)
						|| property.title
						|| key
					return {
						key,
						label,
						type: ['number', 'integer'].includes(property.type)
							? 'number'
							: 'string',
						readOnly: !this.isEditableColumn(key),
						aggregate: 'none',
					}
				})
				.filter(Boolean)
		},

		/**
		 * One matrix row per record: its id, label, one value per column and,
		 * when a colleague holds it, a note naming them.
		 *
		 * @return {Array<object>}
		 */
		rows() {
			return this.records.map((record) => {
				const row = { id: record.id, label: recordLabel(record) }
				for (const column of this.matrixColumns) {
					row[column.key] = record[column.key]
				}
				const holder = this.holderOf(record)
				if (holder) {
					row.lockNote = t('buildiq', '{user} is editing this record', {
						user: holder,
					})
				}
				return row
			})
		},
	},

	watch: {
		typeSlug: {
			immediate: true,
			/**
			 * Reload when the page points at another register or schema.
			 */
			handler() {
				if (this.configured) {
					this.page = 1
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Whether the maker marked a column editable and the schema allows
		 * writing it.
		 *
		 * @param {string} key - the property key.
		 * @return {boolean}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t01
		 */
		isEditableColumn(key) {
			if (!this.editable.includes(key)) {
				return false
			}
			const property = this.schemaProperties[key] || {}
			return property.readOnly !== true
		},

		/**
		 * The colleague holding a record's lock, or '' when it is free or held
		 * by the current user.
		 *
		 * @param {object} record - an OpenRegister object.
		 * @return {string}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t02
		 */
		holderOf(record) {
			if (this.lockConflicts[record.id]) {
				return this.lockConflicts[record.id]
			}
			const lock = activeLock(record)
			if (!lock) {
				return ''
			}
			const me = getCurrentUser()?.uid
			if (lock.user && me && lock.user === me) {
				return ''
			}
			return String(
				lock.displayName || lock.user || t('buildiq', 'A colleague'),
			)
		},

		/**
		 * Load the schema's properties and one page of its records.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t01
		 */
		async load() {
			const slug = this.typeSlug
			if (
				!this.store.objectTypeRegistry
				|| !this.store.objectTypeRegistry[slug]
			) {
				this.store.registerObjectType(slug, this.schema, this.register)
			}
			this.loading = true
			this.loadError = ''
			try {
				const schema = await this.store.fetchSchema(slug)
				if (slug !== this.typeSlug) {
					return
				}
				this.schemaProperties = (schema && schema.properties) || {}
				const records = await this.store.fetchCollection(slug, {
					_limit: GRID_PAGE_SIZE,
					_page: this.page,
				})
				if (slug !== this.typeSlug) {
					return
				}
				const error = this.store.errors && this.store.errors[slug]
				if (error) {
					this.loadError = t(
						'buildiq',
						'Could not load the records: {reason}',
						{
							reason: error.message || String(error),
						},
					)
				}
				this.records = Array.isArray(records)
					? records.map((r) => ({ ...r }))
					: []
				const pagination = this.store.getPagination
					? this.store.getPagination(slug)
					: null
				this.pages = Math.max(1, Number(pagination && pagination.pages) || 1)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show another page of records.
		 *
		 * @param {number} page - the 1-based page.
		 * @return {Promise<void>}
		 */
		async goTo(page) {
			this.page = page
			await this.load()
		},

		/**
		 * Make sure the current user holds the lock on a record before
		 * writing it, releasing the lock on the previously edited row.
		 *
		 * @param {string} id - the record id.
		 * @return {Promise<string>} '' when the lock is held, else the holder's name.
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t02
		 */
		async lockRow(id) {
			if (this.lockedRowId === id) {
				return ''
			}
			if (this.lockedRowId) {
				try {
					await this.lock.release()
				} catch {
					// The old lock expires on its own; it must not block this edit.
				}
			}
			this.lockedRowId = id
			try {
				await this.lock.acquire()
				return ''
			} catch (error) {
				this.lockedRowId = ''
				if (error instanceof LockConflictError) {
					const holder = String(
						error.lockedBy || t('buildiq', 'A colleague'),
					)
					this.lockConflicts = { ...this.lockConflicts, [id]: holder }
					return holder
				}
				// No lock endpoint or no right to lock: the save itself decides.
				return ''
			}
		},

		/**
		 * Save one committed cell: the single property on that record. A
		 * locked row or a refused save puts the old value back and says why.
		 *
		 * @param {{rowId: string, colKey: string, value: (string|number|null)}} edit - the matrix commit.
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t01
		 */
		async onCellEdit({ rowId, colKey, value }) {
			const index = this.records.findIndex((r) => r.id === rowId)
			if (index < 0 || !this.isEditableColumn(colKey)) {
				return
			}
			const record = this.records[index]
			const previous = record[colKey]
			if (previous === value) {
				return
			}
			const label = recordLabel(record)
			const column = this.matrixColumns.find((c) => c.key === colKey)
			const field = (column && column.label) || colKey
			this.notice = null

			const holder = this.holderOf(record) || (await this.lockRow(rowId))
			if (holder) {
				this.notice = {
					type: 'warning',
					text: t(
						'buildiq',
						'{user} is editing {record}; your change was not saved.',
						{
							user: holder,
							record: label,
						},
					),
				}
				return
			}

			this.setValue(rowId, colKey, value)
			const saved = await this.store.patchObject(this.typeSlug, rowId, {
				[colKey]: value,
			})
			if (!saved) {
				this.setValue(rowId, colKey, previous)
				const error = this.store.errors && this.store.errors[this.typeSlug]
				this.notice = {
					type: 'error',
					text: t(
						'buildiq',
						'Could not save {field} of {record}: {reason}',
						{
							field,
							record: label,
							reason:
								(error && error.message)
								|| t('buildiq', 'the server refused the change'),
						},
					),
				}
				return
			}
			if (Object.hasOwn(saved, colKey)) {
				this.setValue(rowId, colKey, saved[colKey])
			}
		},

		/**
		 * Write one value into the shown records without mutating the array
		 * in place.
		 *
		 * @param {string} id - the record id.
		 * @param {string} key - the property key.
		 * @param {string|number|null} value - the value.
		 * @return {void}
		 */
		setValue(id, key, value) {
			this.records = this.records.map((r) =>
				r.id === id ? { ...r, [key]: value } : r,
			)
		},
	},
}
</script>

<style scoped lang="scss">
.record-grid-page {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.record-grid-page__title {
	margin: 0;
}

.record-grid-page__pager {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
