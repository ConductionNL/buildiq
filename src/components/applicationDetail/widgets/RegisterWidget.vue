<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	RegisterWidget — read-only card with an "Open in OpenRegister" deep-link.
	REQ-OBADO-006 (openbuilt-app-detail-overview / application-detail-overview).

	The Register widget shows the version's per-version register identity
	(name + slug) and its three counts (schema / object / file). The
	primary action navigates to the OpenRegister registry detail page
	via a top-level Nextcloud URL — not a Vue Router internal route —
	because OpenRegister is a sibling app, not part of OpenBuilt's
	router.
-->
<template>
	<div class="ob-register-widget">
		<header class="ob-register-widget__header">
			<h3 class="ob-register-widget__title">
				{{ t('openbuilt', 'Register') }}
			</h3>
			<p class="ob-register-widget__slug">
				<code>{{ registerSlug }}</code>
			</p>
		</header>
		<dl class="ob-register-widget__stats">
			<div class="ob-register-widget__stat">
				<dt>{{ t('openbuilt', 'Schemas') }}</dt>
				<dd>{{ schemaCount }}</dd>
			</div>
			<div class="ob-register-widget__stat">
				<dt>{{ t('openbuilt', 'Objects') }}</dt>
				<dd>{{ objectCount }}</dd>
			</div>
			<div class="ob-register-widget__stat">
				<dt>{{ t('openbuilt', 'Files') }}</dt>
				<dd>{{ filesCount }}</dd>
			</div>
		</dl>
		<footer class="ob-register-widget__footer">
			<NcButton type="primary" @click="openInOpenRegister">
				{{ t('openbuilt', 'Open in OpenRegister') }}
			</NcButton>
		</footer>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'RegisterWidget',
	components: { NcButton },
	props: {
		appSlug: { type: String, required: true },
		versionSlug: { type: String, required: true },
		schemaCount: { type: Number, default: 0 },
		objectCount: { type: Number, default: 0 },
		filesCount: { type: Number, default: 0 },
	},
	computed: {
		/**
		 * Per-version register slug — convention
		 * `openbuilt-{appSlug}-{versionSlug}` (ADR-002 / openbuilt-versioning-model).
		 *
		 * @return {string}
		 */
		registerSlug() {
			return `openbuilt-${this.appSlug}-${this.versionSlug}`
		},
	},
	methods: {
		/**
		 * Deep-link to OpenRegister's register detail page (top-level
		 * Nextcloud URL, not a Vue Router internal route).
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

.ob-register-widget__stats {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 8px;
	margin: 0;
}

.ob-register-widget__stat {
	display: flex;
	flex-direction: column;
	dt {
		font-size: 12px;
		color: var(--color-text-maxcontrast, #666);
		margin: 0;
	}
	dd {
		font-size: 18px;
		font-weight: 600;
		margin: 0;
	}
}

.ob-register-widget__footer {
	display: flex;
	justify-content: flex-end;
}
</style>
