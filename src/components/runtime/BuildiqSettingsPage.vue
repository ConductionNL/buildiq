<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - BuildiqSettingsPage: the settings page of a running virtual app. It
  - renders nextcloud-vue's CnSettingsPage with every prop and listener passed
  - through, and keeps what its users save (REQ-BQCV-011):
  -
  - - the values saved earlier are loaded from
  -   `GET /api/applications/{slug}/page-settings/{pageId}` and handed to
  -   CnSettingsPage as `initialValues`, so the next visit shows them;
  - - a save PUTs to the same address (CnSettingsPage's `saveEndpoint`);
  - - a user who may not save (a viewer) gets no save bar.
  -
  - A page whose maker typed its own `saveEndpoint` keeps it and is not
  - loaded from buildiq: that endpoint belongs to someone else.
  -->
<template>
	<CnSettingsPage
		v-bind="$attrs"
		:initialValues="values"
		:saveEndpoint="endpoint"
		:showSaveBar="showSaveBar && canSave">
		<template v-for="(_, name) in $slots" #[name]="slotProps">
			<slot :name="name" v-bind="slotProps || {}" />
		</template>
	</CnSettingsPage>
</template>

<script>
import { CnSettingsPage } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import {
	fetchPageSettings,
	pageSettingsUrl,
} from '../../services/pageSettings.js'

export default {
	name: 'BuildiqSettingsPage',

	components: { CnSettingsPage },

	inheritAttrs: false,

	props: {
		/** A save address the maker typed; empty means buildiq keeps the values. */
		saveEndpoint: {
			type: String,
			default: '',
		},

		/** Values the manifest passes; buildiq's saved values go over them. */
		initialValues: {
			type: Object,
			default: () => ({}),
		},

		/** Whether the page shows its save bar at all. */
		showSaveBar: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			saved: {},
			canSave: true,
		}
	},

	computed: {
		/**
		 * The running app's slug.
		 *
		 * @return {string}
		 */
		slug() {
			return loadState('buildiq', 'builderSlug', '')
		},

		/**
		 * The settings page's id, from the route it is shown on.
		 *
		 * @return {string}
		 */
		pageId() {
			const route = this.$route || {}
			const fromMeta = route.meta && route.meta.cnPageId
			if (typeof fromMeta === 'string' && fromMeta !== '') {
				return fromMeta
			}
			return typeof route.name === 'string' ? route.name : ''
		},

		/**
		 * Whether buildiq keeps this page's values.
		 *
		 * @return {boolean}
		 */
		kept() {
			return this.saveEndpoint === '' && this.slug !== '' && this.pageId !== ''
		},

		/**
		 * Where CnSettingsPage PUTs a save.
		 *
		 * @return {string}
		 */
		endpoint() {
			return this.kept ? pageSettingsUrl(this.slug, this.pageId) : this.saveEndpoint
		},

		/**
		 * The values CnSettingsPage starts from.
		 *
		 * @return {object}
		 */
		values() {
			return { ...(this.initialValues || {}), ...this.saved }
		},
	},

	watch: {
		endpoint: {
			handler() {
				this.load()
			},

			immediate: true,
		},
	},

	methods: {
		/**
		 * Load the values saved on this page.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-page-designer/spec.md#requirement-a-maker-adds-a-settings-page-req-bqcv-011
		 */
		async load() {
			this.saved = {}
			this.canSave = true
			if (!this.kept) {
				return
			}
			const answer = await fetchPageSettings(this.slug, this.pageId)
			this.saved = answer.values
			this.canSave = answer.canSave
		},
	},
}
</script>
