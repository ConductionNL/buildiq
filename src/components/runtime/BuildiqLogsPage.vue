<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - BuildiqLogsPage: the logs page of a running virtual app. A logs page
  - bound to a register and schema lists the recent changes to that schema's
  - records, newest first (REQ-BQCV-010): it renders nextcloud-vue's
  - CnLogsPage in `source` mode on OpenRegister's caller-scoped audit trail,
  - narrowed to the schema, with the columns of a change (when, who, what,
  - which record).
  -
  - A page with its own `source` renders exactly as CnLogsPage. When the
  - register or schema cannot be resolved, the page falls back to listing the
  - schema's records, as before, rather than showing nothing.
  -->
<template>
	<CnLogsPage
		v-if="ready"
		v-bind="{ ...$attrs, ...pageProps }">
		<template v-for="(_, name) in $slots" #[name]="slotProps">
			<slot :name="name" v-bind="slotProps || {}" />
		</template>
	</CnLogsPage>
</template>

<script>
import { CnLogsPage } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { changeFeedUrl } from '../../services/changeFeed.js'

export default {
	name: 'BuildiqLogsPage',

	components: { CnLogsPage },

	inheritAttrs: false,

	props: {
		/** The bound register (id, uuid or slug). */
		register: {
			type: String,
			default: '',
		},

		/** The bound schema (id, uuid or slug). */
		schema: {
			type: String,
			default: '',
		},

		/** A log source the maker set; wins over the binding. */
		source: {
			type: String,
			default: '',
		},

		/** Columns the maker set; used when the page lists records. */
		columns: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			feed: null,
			ready: false,
		}
	},

	computed: {
		/**
		 * Whether the page lists the changes to a bound schema.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
		 */
		bound() {
			return this.source === '' && this.register !== '' && this.schema !== ''
		},

		/**
		 * What the feed depends on.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
		 */
		bindingKey() {
			return [this.register, this.schema, this.source].join('|')
		},

		/**
		 * The data-source props CnLogsPage gets.
		 *
		 * @return {object}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
		 */
		pageProps() {
			if (this.feed === null) {
				return { register: this.register, schema: this.schema, source: this.source, columns: this.columns }
			}
			return { register: '', schema: '', source: this.feed, columns: this.changeColumns() }
		},
	},

	watch: {
		bindingKey: {
			/**
			 * Resolve the change feed again when the binding changes.
			 *
			 * @return {void}
			 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
			 */
			handler() {
				this.resolveFeed()
			},

			immediate: true,
		},
	},

	methods: {
		/**
		 * Work out where the page reads from.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
		 */
		async resolveFeed() {
			this.ready = false
			this.feed = null
			if (this.bound) {
				this.feed = await changeFeedUrl(this.register, this.schema)
			}
			this.ready = true
		},

		/**
		 * The columns of one change.
		 *
		 * @return {Array<{key: string, label: string, sortable?: boolean}>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-log-page-req-bqcv-010
		 */
		changeColumns() {
			return [
				{ key: 'created', label: t('buildiq', 'When'), sortable: true },
				{ key: 'userName', label: t('buildiq', 'Who'), sortable: true },
				{ key: 'action', label: t('buildiq', 'What'), sortable: true },
				{ key: 'objectUuid', label: t('buildiq', 'Record') },
			]
		},
	},
}
</script>
