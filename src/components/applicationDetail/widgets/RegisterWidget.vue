<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	RegisterWidget — read-only card listing the app's per-version OpenRegister
	register and the schemas it contains, with an "Open in OpenRegister"
	deep-link. (application-detail-overview / layered-versioned-app-deltas.)

	Object and file counts are intentionally NOT shown here — they already live in
	the dashboard KPI tiles (Object count + Storage), so the Register widget
	instead lists the register's schemas (what's actually IN the register). The
	primary action navigates to the OpenRegister registry detail page via a
	top-level Nextcloud URL — OpenRegister is a sibling app, not part of
	OpenBuild's router.
-->
<template>
	<div class="ob-register-widget">
		<header class="ob-register-widget__header">
			<h3 class="ob-register-widget__title">
				{{ t('openbuild', 'Register') }}
			</h3>
			<p class="ob-register-widget__slug">
				<code>{{ registerSlug }}</code>
			</p>
		</header>

		<!-- Schema list (replaces the old schema/object/file count grid). -->
		<div class="ob-register-widget__schemas">
			<div class="ob-register-widget__schemas-head">
				<span class="ob-register-widget__schemas-label">{{ t('openbuild', 'Schemas') }}</span>
				<span v-if="!loading" class="ob-register-widget__schemas-count">{{ schemas.length }}</span>
			</div>

			<NcLoadingIcon v-if="loading" :size="24" class="ob-register-widget__loading" />

			<p v-else-if="schemas.length === 0" class="ob-register-widget__empty">
				{{ t('openbuild', 'This register defines no schemas.') }}
			</p>

			<ul v-else class="ob-register-widget__schema-list">
				<li
					v-for="schema in visibleSchemas"
					:key="schema.id"
					class="ob-register-widget__schema">
					{{ schema.name }}
				</li>
				<li
					v-if="schemas.length > visibleLimit"
					class="ob-register-widget__schema ob-register-widget__schema--more">
					{{ n('openbuild', '+%n more schema', '+%n more schemas', schemas.length - visibleLimit) }}
				</li>
			</ul>
		</div>

		<footer class="ob-register-widget__footer">
			<NcButton type="primary" @click="openInOpenRegister">
				{{ t('openbuild', 'Open in OpenRegister') }}
			</NcButton>
		</footer>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'RegisterWidget',
	components: { NcButton, NcLoadingIcon },
	props: {
		/** The app's kebab-case slug. */
		appSlug: { type: String, required: true },
		/** The active version's slug (the per-version register is `openbuild-{appSlug}-{versionSlug}`). */
		versionSlug: { type: String, required: true },
	},
	data() {
		return {
			/** @type {Array<{id: (string|number), name: string}>} */
			schemas: [],
			loading: false,
			/** Max schema names shown before collapsing into "+N more". */
			visibleLimit: 12,
		}
	},
	computed: {
		/**
		 * Per-version register slug — convention `openbuild-{appSlug}-{versionSlug}`
		 * (ADR-002 / openbuild-versioning-model).
		 *
		 * @return {string}
		 */
		registerSlug() {
			return `openbuild-${this.appSlug}-${this.versionSlug}`
		},
		/**
		 * The schemas shown inline (the rest collapse into a "+N more" row).
		 *
		 * @return {Array<{id: (string|number), name: string}>}
		 */
		visibleSchemas() {
			return this.schemas.slice(0, this.visibleLimit)
		},
	},
	watch: {
		registerSlug: 'loadSchemas',
	},
	mounted() {
		this.loadSchemas()
	},
	methods: {
		/**
		 * Resolve the register by slug and list its schemas by name. Two reads:
		 * the registers list (to find this register's schema ids) and the schemas
		 * list (to map those ids to titles). Best-effort — any failure leaves an
		 * empty list and the widget still renders the deep-link.
		 *
		 * @return {Promise<void>}
		 */
		async loadSchemas() {
			if (!this.appSlug || !this.versionSlug) return
			this.loading = true
			try {
				const registersUrl = generateUrl('/apps/openregister/api/registers')
				const { data: regData } = await axios.get(registersUrl, {
					params: { _limit: 1000 },
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				const registers = (regData && Array.isArray(regData.results)) ? regData.results : []
				const register = registers.find((r) => r.slug === this.registerSlug)
				const schemaIds = (register && Array.isArray(register.schemas)) ? register.schemas.map(String) : []

				if (schemaIds.length === 0) {
					this.schemas = []
					return
				}

				const schemasUrl = generateUrl('/apps/openregister/api/schemas')
				const { data: schData } = await axios.get(schemasUrl, {
					params: { _limit: 10000 },
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				const allSchemas = (schData && Array.isArray(schData.results)) ? schData.results : []
				const byId = new Map()
				allSchemas.forEach((s) => {
					const id = String((s['@self'] && s['@self'].id) || s.id || '')
					if (id) byId.set(id, s.title || s.slug || id)
				})

				this.schemas = schemaIds
					.map((id) => (byId.has(id) ? { id, name: byId.get(id) } : null))
					.filter(Boolean)
					.sort((a, b) => String(a.name).localeCompare(String(b.name)))
			} catch (e) {
				this.schemas = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Deep-link to OpenRegister's register detail page (top-level Nextcloud
		 * URL, not a Vue Router internal route).
		 *
		 * @return {void}
		 */
		openInOpenRegister() {
			const url = generateUrl(`/apps/openregister/registers/${encodeURIComponent(this.registerSlug)}`)
			window.location.href = url
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-register-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-register-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-register-widget__slug {
	margin: 4px 0 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-register-widget__schemas-head {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.ob-register-widget__schemas-label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast, #666);
	text-transform: uppercase;
	letter-spacing: 0.03em;
}

.ob-register-widget__schemas-count {
	font-size: 12px;
	padding: 1px 8px;
	border-radius: 10px;
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-text-maxcontrast, #666);
}

.ob-register-widget__loading {
	padding: 8px 0;
}

.ob-register-widget__empty {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-register-widget__schema-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.ob-register-widget__schema {
	font-size: 13px;
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-background-hover, #f5f5f5);
	border: 1px solid var(--color-border, #ddd);
}

.ob-register-widget__schema--more {
	background: transparent;
	border-style: dashed;
	color: var(--color-text-maxcontrast, #666);
}

.ob-register-widget__footer {
	display: flex;
	justify-content: flex-end;
}
</style>
