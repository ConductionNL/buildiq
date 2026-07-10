<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 4 — Review
  Read-only summary of all wizard settings before submission.
  Displays the app name + slug + description, the version chain in arrow form,
  and a callout identifying the production version (terminal row).
  spec: openbuild-app-creation-wizard REQ-OBWIZ-002
-->
<template>
	<div class="wizard-step4">
		<h3 class="wizard-step4__heading">
			{{ t('openbuild', 'Review and create') }}
		</h3>
		<p class="wizard-step4__description">
			{{ t('openbuild', 'Review the settings below. Clicking Create will provision your app, all version registers, and seed them with the default schema.') }}
		</p>

		<dl class="wizard-step4__summary">
			<div class="wizard-step4__row">
				<dt>{{ t('openbuild', 'Name') }}</dt>
				<dd>{{ payload.name || '—' }}</dd>
			</div>
			<div class="wizard-step4__row">
				<dt>{{ t('openbuild', 'Slug') }}</dt>
				<dd><code>{{ payload.slug || '—' }}</code></dd>
			</div>
			<div v-if="payload.description" class="wizard-step4__row">
				<dt>{{ t('openbuild', 'Description') }}</dt>
				<dd>{{ payload.description }}</dd>
			</div>
		</dl>

		<div class="wizard-step4__chain-section">
			<h4 class="wizard-step4__subheading">
				{{ t('openbuild', 'Version chain') }}
			</h4>
			<p class="wizard-step4__chain">
				{{ chainDisplay }}
			</p>
			<p class="wizard-step4__production-callout">
				{{ t('openbuild', 'Production version:') }}
				<code>{{ productionSlug }}</code>
			</p>
		</div>

		<!-- Icon previews when a glyph was chosen -->
		<div v-if="lightIconSvg || darkIconSvg" class="wizard-step4__icons">
			<h4 class="wizard-step4__subheading">
				{{ t('openbuild', 'Icons') }}
			</h4>
			<div class="wizard-step4__icon-previews">
				<figure v-if="lightIconSvg" class="wizard-step4__icon-preview">
					<!-- eslint-disable-next-line vue/no-v-html -->
					<span class="wizard-step4__icon-img" v-html="lightIconSvg" />
					<figcaption>{{ t('openbuild', 'Light') }}</figcaption>
				</figure>
				<figure v-if="darkIconSvg" class="wizard-step4__icon-preview wizard-step4__icon-preview--dark">
					<!-- eslint-disable-next-line vue/no-v-html -->
					<span class="wizard-step4__icon-img" v-html="darkIconSvg" />
					<figcaption>{{ t('openbuild', 'Dark') }}</figcaption>
				</figure>
			</div>
		</div>
	</div>
</template>

<script>
import { resolveAppIcon } from '../../utils/iconCatalogues.js'

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
		/**
		 * Observed behaviour of `versions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		versions() {
			return Array.isArray(this.payload.versions) ? this.payload.versions : []
		},

		/**
		 * Observed behaviour of `chainDisplay` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		chainDisplay() {
			if (this.versions.length === 0) return '—'
			return this.versions.map(v => v.slug || v.name || '?').join(' → ')
		},

		/**
		 * Observed behaviour of `productionSlug` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		productionSlug() {
			if (this.versions.length === 0) return '—'
			const last = this.versions[this.versions.length - 1]
			return last.slug || last.name || '—'
		},

		/**
		 * The resolved light app-icon SVG (white glyph) for the review preview.
		 *
		 * @return {string|null} SVG markup, or null when no icon was chosen.
		 */
		lightIconSvg() {
			return resolveAppIcon(this.payload.iconValue, { dark: false })
		},

		/**
		 * The resolved dark app-icon SVG (no fill), defaulting to the primary
		 * icon so it mirrors what the wizard attaches on submit.
		 *
		 * @return {string|null} SVG markup, or null when no icon was chosen.
		 */
		darkIconSvg() {
			const source = this.payload.iconDarkValue || this.payload.iconValue
			return resolveAppIcon(source, { dark: true })
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
	/* The light icon is a white glyph meant for the dark app header, so
	   preview it on a dark background (else it's white-on-white). */
	background: #1c1c1e;
	color: #fff;
}

.wizard-step4__icon-preview--dark {
	/* The dark icon is a dark glyph meant for light backgrounds, so preview
	   it on white (else it's black-on-black). */
	background: #ffffff;
	color: #1c1c1e;
}

.wizard-step4__icon-img {
	display: inline-flex;
	width: 48px;
	height: 48px;
}

.wizard-step4__icon-img :deep(svg) {
	width: 100%;
	height: 100%;
}

.wizard-step4__icon-preview figcaption {
	font-size: 0.75rem;
	color: #ccc;
}

.wizard-step4__icon-preview--dark figcaption {
	color: var(--color-text-maxcontrast, #555);
}
</style>
