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
				<span class="ob-register-widget__schemas-label">{{
					t('openbuild', 'Schemas')
				}}</span>
				<span v-if="!loading" class="ob-register-widget__schemas-count">{{
					schemas.length
				}}</span>
			</div>

			<NcLoadingIcon
				v-if="loading"
				:size="24"
				class="ob-register-widget__loading" />

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
					{{
						n(
							'openbuild',
							'+%n more schema',
							'+%n more schemas',
							schemas.length - visibleLimit,
						)
					}}
				</li>
			</ul>
		</div>

		<footer class="ob-register-widget__footer">
			<NcButton
				v-if="canImport"
				type="secondary"
				@click="$emit('import-data', { registerSlug, schemas })">
				{{ t('openbuild', 'Import data') }}
			</NcButton>
			<NcButton type="primary" @click="openInOpenRegister">
				{{ t('openbuild', 'Open in OpenRegister') }}
			</NcButton>
		</footer>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'RegisterWidget',
	components: { NcButton, NcLoadingIcon },
	props: {
		/** The app's kebab-case slug. */
		appSlug: { type: String, required: true },
		/** The active version's slug (the per-version register is `openbuild-{appSlug}-{versionSlug}`). */
		versionSlug: { type: String, required: true },
		/**
		 * Whether this is a hybrid app. A hybrid app's data + schemas live in the
		 * INSTALLED app's register (named after the app), not the empty per-version
		 * `openbuild-{appSlug}-{versionSlug}` register — so the widget points at the
		 * fleet register for hybrids.
		 */
		isHybrid: { type: Boolean, default: false },
		/**
		 * The active version's REAL register slug. Versions may share production's
		 * register (manifest-only versioning), so the `openbuild-{appSlug}-{versionSlug}`
		 * convention can name a non-existent register; when this override is set it
		 * takes precedence.
		 */
		registerSlugOverride: { type: String, default: '' },
		/**
		 * Whether the caller holds a build/manage role on the Application. When
		 * true the widget surfaces the "Import data" affordance (the import
		 * itself is independently re-gated server-side by OpenRegister's own
		 * register manage-permission). Default false — non-builders never see
		 * the affordance.
		 */
		canImport: { type: Boolean, default: false },
	},
	emits: ['import-data'],
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
		 * The register to show. For a HYBRID app this is the installed fleet
		 * app's register (named after the app — that is where its schemas/data
		 * actually live); for a VIRTUAL app it is the per-version register
		 * `openbuild-{appSlug}-{versionSlug}` (ADR-002 / openbuild-versioning-model).
		 *
		 * @return {string}
		 */
		registerSlug() {
			if (this.registerSlugOverride) return this.registerSlugOverride
			return this.isHybrid
				? this.appSlug
				: `openbuild-${this.appSlug}-${this.versionSlug}`
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
		 * list (to map those ids to titles).
		 *
		 * Retries a few times while the result is empty: on a cold page load (and
		 * especially right after an app/container restart) OpenRegister may not
		 * have the register's `schemas` populated yet when the widget first
		 * mounts, which would otherwise leave a misleading "no schemas" state that
		 * never recovers. Each attempt re-resolves, so it self-heals once OR is
		 * ready. Bounded so a genuinely empty register settles instead of looping.
		 *
		 * @param {number} attempt The current attempt index (0-based).
		 * @return {Promise<void>}
		 */
		async loadSchemas(attempt = 0) {
			if (!this.appSlug || !this.versionSlug) return
			if (attempt === 0) this.loading = true

			let result = []
			try {
				const registersUrl = generateUrl('/apps/openregister/api/registers')
				const { data: regData } = await axios.get(registersUrl, {
					params: { _limit: 1000 },
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				const registers =
					regData && Array.isArray(regData.results) ? regData.results : []
				const register = registers.find((r) => r.slug === this.registerSlug)
				const schemaIds =
					register && Array.isArray(register.schemas)
						? register.schemas.map(String)
						: []

				if (schemaIds.length > 0) {
					const schemasUrl = generateUrl('/apps/openregister/api/schemas')
					const { data: schData } = await axios.get(schemasUrl, {
						params: { _limit: 10000 },
						headers: { 'OCS-APIREQUEST': 'true' },
					})
					const allSchemas =
						schData && Array.isArray(schData.results)
							? schData.results
							: []
					const byId = new Map()
					allSchemas.forEach((s) => {
						const id = String(
							(s['@self'] && s['@self'].id) || s.id || '',
						)
						if (id) byId.set(id, s.title || s.slug || id)
					})

					result = schemaIds
						.map((id) =>
							byId.has(id) ? { id, name: byId.get(id) } : null,
						)
						.filter(Boolean)
						.sort((a, b) => String(a.name).localeCompare(String(b.name)))
				}
			} catch (e) {
				result = []
			}

			// Self-heal a cold-load race: re-attempt while empty (bounded).
			if (result.length === 0 && attempt < 3) {
				setTimeout(() => this.loadSchemas(attempt + 1), 1200)
				return
			}

			this.schemas = result
			this.loading = false
		},
		/**
		 * Deep-link to OpenRegister's register detail page (top-level Nextcloud
		 * URL, not a Vue Router internal route).
		 *
		 * @return {void}
		 */
		openInOpenRegister() {
			const url = generateUrl(
				`/apps/openregister/registers/${encodeURIComponent(this.registerSlug)}`,
			)
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
