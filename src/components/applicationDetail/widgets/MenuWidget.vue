<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	MenuWidget — list manifest.menu[] entries with deep-link rows.
	REQ-OBADO-010.
-->
<template>
	<div class="ob-menu-widget">
		<header class="ob-menu-widget__header">
			<h3 class="ob-menu-widget__title">
				{{ t('openbuilt', 'Menu') }}
			</h3>
		</header>
		<ul v-if="menu && menu.length > 0" class="ob-menu-widget__list">
			<li
				v-for="entry in menu"
				:key="entry.id || entry.label"
				class="ob-menu-widget__row"
				role="button"
				tabindex="0"
				@click="openEntry()"
				@keyup.enter="openEntry()"
				@keyup.space="openEntry()">
				<span class="ob-menu-widget__row-label">{{ entry.label || entry.id || '—' }}</span>
				<span class="ob-menu-widget__row-route"><code>{{ entry.route || '—' }}</code></span>
				<span class="ob-menu-widget__row-order">{{ entry.order != null ? entry.order : '—' }}</span>
				<span class="ob-menu-widget__row-section">{{ entry.section || t('openbuilt', 'main') }}</span>
			</li>
		</ul>
		<p v-else class="ob-menu-widget__empty">
			{{ t('openbuilt', 'No menu entries configured.') }}
		</p>
	</div>
</template>

<script>
import { buildVersionedRoute } from '../../../router/helpers.js'

export default {
	name: 'MenuWidget',
	props: {
		appSlug: { type: String, required: true },
		versionSlug: { type: String, default: '' },
		menu: { type: Array, default: () => [] },
	},
	methods: {
		/**
		 * Open the page designer with the menu pane focused (REQ-OBADO-010).
		 *
		 * @return {void}
		 */
		openEntry() {
			const route = buildVersionedRoute(
				'PageDesigner',
				{ slug: this.appSlug },
				this.versionSlug || undefined,
			)
			route.query = { ...(route.query || {}), focus: 'menu' }
			this.$router.push(route)
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-menu-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-menu-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-menu-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-menu-widget__row {
	display: grid;
	grid-template-columns: 2fr 2fr 1fr 1fr;
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

.ob-menu-widget__row-label {
	font-weight: 600;
}

.ob-menu-widget__row-section {
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}

.ob-menu-widget__empty {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-style: italic;
}
</style>
