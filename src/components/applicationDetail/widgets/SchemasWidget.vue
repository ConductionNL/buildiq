<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	SchemasWidget — list schemas in the active version's register with
	deep-link rows + inline "+ Add schema". REQ-OBADO-007.

	The inline "+ Add schema" button delegates to the existing
	create-schema dialog when a global registration is present
	(currently checked via `window.openbuilt?.openAddSchemaDialog`).
	When no dialog is registered the button emits a debug log entry
	and no-ops, deferring the actual create flow to the future schema-
	designer spec.
-->
<template>
	<div class="ob-schemas-widget">
		<header class="ob-schemas-widget__header">
			<h3 class="ob-schemas-widget__title">
				{{ t('openbuilt', 'Schemas') }}
			</h3>
			<NcButton type="tertiary" @click="addSchema">
				{{ t('openbuilt', '+ Add schema') }}
			</NcButton>
		</header>
		<ul v-if="schemas && schemas.length > 0" class="ob-schemas-widget__list">
			<li
				v-for="schema in schemas"
				:key="schema.id || schema.uuid || schema.slug"
				class="ob-schemas-widget__row"
				role="button"
				tabindex="0"
				@click="openSchema(schema)"
				@keyup.enter="openSchema(schema)"
				@keyup.space="openSchema(schema)">
				<span class="ob-schemas-widget__row-name">{{ schema.name || schema.title || schema.slug }}</span>
				<span class="ob-schemas-widget__row-meta">
					<span class="ob-schemas-widget__row-count">{{ formatCount(schema.objectCount) }}</span>
					<span class="ob-schemas-widget__row-status">{{ schema.status || t('openbuilt', 'active') }}</span>
				</span>
			</li>
		</ul>
		<p v-else class="ob-schemas-widget__empty">
			{{ t('openbuilt', 'No schemas yet in this version.') }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { buildVersionedRoute } from '../../../router/helpers.js'

export default {
	name: 'SchemasWidget',
	components: { NcButton },
	props: {
		appSlug: { type: String, required: true },
		versionSlug: { type: String, default: '' },
		schemas: { type: Array, default: () => [] },
	},
	methods: {
		/**
		 * Format an object-count value for inline display.
		 *
		 * @param {number|undefined} count Object count value.
		 * @return {string}
		 */
		formatCount(count) {
			const n = Number(count || 0)
			return t('openbuilt', '{count} objects', { count: n })
		},

		/**
		 * Navigate to the schema designer for the clicked row, preserving the
		 * active `?_version=` query parameter (REQ-OBADO-007).
		 *
		 * @param {object} schema The schema row.
		 * @return {void}
		 */
		openSchema(schema) {
			const id = schema.id || schema.uuid || schema.slug
			if (!id) {
				return
			}
			this.$router.push(buildVersionedRoute(
				'SchemaDesigner',
				{ slug: this.appSlug, schemaId: String(id) },
				this.versionSlug || undefined,
			))
		},

		/**
		 * Open the existing create-schema dialog if one is registered globally,
		 * otherwise log a debug notice and no-op (REQ-OBADO-007 deferred-dialog
		 * scenario).
		 *
		 * @return {void}
		 */
		addSchema() {
			const opener = (typeof window !== 'undefined' && window.openbuilt && typeof window.openbuilt.openAddSchemaDialog === 'function')
				? window.openbuilt.openAddSchemaDialog
				: null
			if (opener) {
				opener({ appSlug: this.appSlug, versionSlug: this.versionSlug })
				return
			}
			if (typeof console !== 'undefined' && typeof console.debug === 'function') {
				console.debug('openbuilt: schema-create dialog not yet registered — deferred to schema-designer spec')
			}
			this.$emit('add-schema', { appSlug: this.appSlug, versionSlug: this.versionSlug })
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-schemas-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-schemas-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.ob-schemas-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-schemas-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-schemas-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
	&:hover,
	&:focus {
		background: var(--color-background-hover, #f5f5f5);
		outline: none;
	}
}

.ob-schemas-widget__row-meta {
	display: flex;
	gap: 12px;
	color: var(--color-text-maxcontrast, #666);
	font-size: 13px;
}

.ob-schemas-widget__empty {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-style: italic;
}
</style>
