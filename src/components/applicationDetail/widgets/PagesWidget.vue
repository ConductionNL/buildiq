<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	PagesWidget — list manifest.pages[] entries with deep-link rows.
	REQ-OBADO-009.
-->
<template>
	<div class="ob-pages-widget">
		<header class="ob-pages-widget__header">
			<h3 class="ob-pages-widget__title">
				{{ t('buildiq', 'Pages') }}
			</h3>
			<!-- Mirrors SchemasWidget's header action: the rows only deep-link
			     EXISTING pages, so an app with none had no way into the designer
			     but the "Edit {version}" entry nested in the Open app menu.
			     data-walkthrough-id: the tour's `open-page-designer` step. -->
			<NcButton
				variant="tertiary"
				data-walkthrough-id="app-add-page"
				@click="addPage">
				{{ t('buildiq', '+ Add page') }}
			</NcButton>
		</header>
		<ul v-if="pages && pages.length > 0" class="ob-pages-widget__list">
			<li
				v-for="page in pages"
				:key="page.id"
				class="ob-pages-widget__row"
				role="button"
				tabindex="0"
				@click="openPage(page)"
				@keyup.enter="openPage(page)"
				@keyup.space="openPage(page)">
				<span class="ob-pages-widget__row-id">{{ page.id }}</span>
				<span class="ob-pages-widget__row-route"
					><code>{{ page.route || '—' }}</code></span
				>
				<span class="ob-pages-widget__row-type">{{ page.type || '—' }}</span>
				<span class="ob-pages-widget__row-title">{{
					page.title || '—'
				}}</span>
			</li>
		</ul>
		<p v-else class="ob-pages-widget__empty">
			{{ t('buildiq', 'No pages configured.') }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import { buildVersionedRoute } from '../../../router/helpers.js'

export default {
	name: 'PagesWidget',
	components: { NcButton },
	props: {
		appSlug: { type: String, required: true },
		versionSlug: { type: String, default: '' },
		pages: { type: Array, default: () => [] },
	},

	emits: ['addPage'],

	methods: {
		/**
		 * Ask the dashboard to open the page designer, mirroring `addSchema`.
		 *
		 * @return {void}
		 *
		 * @spec exclude emit-only affordance; REQ-OBADO-009 covers the rows, not Add
		 */
		addPage() {
			this.$emit('addPage')
		},

		/**
		 * Open the page designer for the clicked row, preserving `?_version=`
		 * and passing `pageId` as a query param (REQ-OBADO-009).
		 *
		 * @param {object} page The manifest page entry.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-2
		 */
		openPage(page) {
			if (!page || !page.id) {
				return
			}
			const route = buildVersionedRoute(
				'PageDesigner',
				{ slug: this.appSlug },
				this.versionSlug || undefined,
			)
			route.query = { ...(route.query || {}), pageId: page.id }
			this.$router.push(route)
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-pages-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-pages-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.ob-pages-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-pages-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-pages-widget__row {
	display: grid;
	grid-template-columns: 1fr 2fr 1fr 2fr;
	gap: 12px;
	align-items: center;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
	&:hover,
	&:focus {
		background: var(--color-background-hover, #f5f5f5);
		outline: none;
	}
}

.ob-pages-widget__row-id {
	font-weight: 600;
}

.ob-pages-widget__row-type {
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}

.ob-pages-widget__empty {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-style: italic;
}
</style>
