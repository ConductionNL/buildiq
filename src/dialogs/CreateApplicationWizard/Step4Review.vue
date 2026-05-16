<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 4 — Review
  Read-only summary of all wizard settings before submission.
  Displays the app name + slug + description, the version chain in arrow form,
  and a callout identifying the production version (terminal row).
  spec: openbuilt-app-creation-wizard REQ-OBWIZ-002
-->
<template>
	<div class="wizard-step4">
		<h3 class="wizard-step4__heading">
			{{ t('openbuilt', 'Review and create') }}
		</h3>
		<p class="wizard-step4__description">
			{{ t('openbuilt', 'Review the settings below. Clicking Create will provision your app, all version registers, and seed them with the default schema.') }}
		</p>

		<dl class="wizard-step4__summary">
			<div class="wizard-step4__row">
				<dt>{{ t('openbuilt', 'Name') }}</dt>
				<dd>{{ payload.name || '—' }}</dd>
			</div>
			<div class="wizard-step4__row">
				<dt>{{ t('openbuilt', 'Slug') }}</dt>
				<dd><code>{{ payload.slug || '—' }}</code></dd>
			</div>
			<div v-if="payload.description" class="wizard-step4__row">
				<dt>{{ t('openbuilt', 'Description') }}</dt>
				<dd>{{ payload.description }}</dd>
			</div>
		</dl>

		<div class="wizard-step4__chain-section">
			<h4 class="wizard-step4__subheading">
				{{ t('openbuilt', 'Version chain') }}
			</h4>
			<p class="wizard-step4__chain">
				{{ chainDisplay }}
			</p>
			<p class="wizard-step4__production-callout">
				{{ t('openbuilt', 'Production version:') }}
				<code>{{ productionSlug }}</code>
			</p>
		</div>

		<!-- Icon previews when uploaded -->
		<div v-if="payload.icon || payload.iconDark" class="wizard-step4__icons">
			<h4 class="wizard-step4__subheading">
				{{ t('openbuilt', 'Icons') }}
			</h4>
			<div class="wizard-step4__icon-previews">
				<figure v-if="iconLightUrl" class="wizard-step4__icon-preview">
					<img :src="iconLightUrl" :alt="t('openbuilt', 'Light icon preview')" class="wizard-step4__icon-img" />
					<figcaption>{{ t('openbuilt', 'Light') }}</figcaption>
				</figure>
				<figure v-if="iconDarkUrl" class="wizard-step4__icon-preview wizard-step4__icon-preview--dark">
					<img :src="iconDarkUrl" :alt="t('openbuilt', 'Dark icon preview')" class="wizard-step4__icon-img" />
					<figcaption>{{ t('openbuilt', 'Dark') }}</figcaption>
				</figure>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'Step4Review',

	props: {
		/**
		 * The current wizard payload (full, passed down from the wizard shell).
		 */
		payload: {
			type: Object,
			required: true,
		},
	},

	computed: {
		versions() {
			return Array.isArray(this.payload.versions) ? this.payload.versions : []
		},

		chainDisplay() {
			if (this.versions.length === 0) return '—'
			return this.versions.map(v => v.slug || v.name || '?').join(' → ')
		},

		productionSlug() {
			if (this.versions.length === 0) return '—'
			const last = this.versions[this.versions.length - 1]
			return last.slug || last.name || '—'
		},

		iconLightUrl() {
			return this.payload.icon ? URL.createObjectURL(this.payload.icon) : null
		},

		iconDarkUrl() {
			return this.payload.iconDark ? URL.createObjectURL(this.payload.iconDark) : null
		},
	},
}
</script>

<style scoped>
.wizard-step4 {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.wizard-step4__heading {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0;
}

.wizard-step4__description {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.9rem;
	margin: 0;
}

.wizard-step4__summary {
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wizard-step4__row {
	display: flex;
	gap: 12px;
	align-items: baseline;
}

.wizard-step4__row dt {
	min-width: 100px;
	font-weight: 500;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.875rem;
}

.wizard-step4__row dd {
	margin: 0;
	color: var(--color-main-text, #222);
}

.wizard-step4__chain-section {
	padding: 12px 16px;
	background: var(--color-background-dark, #f5f5f5);
	border-radius: var(--border-radius, 4px);
}

.wizard-step4__subheading {
	font-size: 0.875rem;
	font-weight: 600;
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast, #555);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.wizard-step4__chain {
	font-family: monospace;
	font-size: 1rem;
	color: var(--color-primary, #4376fc);
	margin: 0 0 8px;
}

.wizard-step4__production-callout {
	font-size: 0.875rem;
	color: var(--color-main-text, #222);
	margin: 0;
}

.wizard-step4__icons {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wizard-step4__icon-previews {
	display: flex;
	gap: 16px;
}

.wizard-step4__icon-preview {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 4px;
	padding: 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: 8px;
	margin: 0;
}

.wizard-step4__icon-preview--dark {
	background: #1a1a2e;
}

.wizard-step4__icon-img {
	width: 48px;
	height: 48px;
	object-fit: contain;
}

.wizard-step4__icon-preview figcaption {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast, #555);
}

.wizard-step4__icon-preview--dark figcaption {
	color: #aaa;
}
</style>
