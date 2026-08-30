<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 2 — Preset picker
  Four radio-card options: single, dev-prod, dev-staging-prod, custom.
  Selecting a canned preset pre-populates payload.versions with the hardcoded chain.
  Selecting custom marks the selection so the wizard shell shows step 3.
  spec: buildiq-app-creation-wizard REQ-OBWIZ-002, REQ-OBWIZ-003
-->
<template>
	<div class="wizard-step2">
		<h3 class="wizard-step2__heading">
			{{ t('buildiq', 'Choose a version preset') }}
		</h3>
		<p class="wizard-step2__description">
			{{
				t(
					'buildiq',
					'Select how many deployment versions your app will have. You can always add more versions later.',
				)
			}}
		</p>

		<div
			class="wizard-step2__presets"
			role="radiogroup"
			:aria-label="t('buildiq', 'Version presets')">
			<button
				v-for="option in presetOptions"
				:key="option.id"
				type="button"
				class="wizard-step2__preset-card"
				:class="{
					'wizard-step2__preset-card--selected':
						payload.preset === option.id,
				}"
				:aria-pressed="payload.preset === option.id"
				@click="selectPreset(option.id)">
				<strong class="wizard-step2__preset-name">{{ option.label }}</strong>
				<span class="wizard-step2__preset-chain">{{ option.chain }}</span>
				<span class="wizard-step2__preset-desc">{{
					option.description
				}}</span>
			</button>
		</div>
	</div>
</template>

<script>
/** Canonical version chains per preset. */
const PRESET_VERSIONS = {
	single: [{ name: 'Production', slug: 'production' }],
	'dev-prod': [
		{ name: 'Development', slug: 'development' },
		{ name: 'Production', slug: 'production' },
	],
	'dev-staging-prod': [
		{ name: 'Development', slug: 'development' },
		{ name: 'Staging', slug: 'staging' },
		{ name: 'Production', slug: 'production' },
	],
}

export default {
	name: 'Step2Preset',

	props: {
		/**
		 * The current wizard payload (partial, passed down from the wizard shell).
		 */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	computed: {
		/**
		 * Observed behaviour of `presetOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		presetOptions() {
			return [
				{
					id: 'single',
					label: t('buildiq', 'Single'),
					chain: 'production',
					description: t(
						'buildiq',
						'One version only. Best for simple apps without a staging environment.',
					),
				},
				{
					id: 'dev-prod',
					label: t('buildiq', 'Development + Production'),
					chain: 'development → production',
					description: t(
						'buildiq',
						'A safe playground for changes before they go live.',
					),
				},
				{
					id: 'dev-staging-prod',
					label: t('buildiq', 'Development + Staging + Production'),
					chain: 'development → staging → production',
					description: t(
						'buildiq',
						'Classic three-tier pipeline for larger teams.',
					),
				},
				{
					id: 'custom',
					label: t('buildiq', 'Custom'),
					chain: t('buildiq', 'Define your own chain'),
					description: t(
						'buildiq',
						'Name and order your versions however your team works.',
					),
				},
			]
		},

		isValid() {
			return Boolean(this.payload.preset)
		},
	},

	watch: {
		isValid(newVal) {
			this.$emit('update:payload', { _step2Valid: newVal })
		},
	},

	methods: {
		/**
		 * Observed behaviour of `selectPreset` (retrofit annotation).
		 *
		 * @param {'single'|'dev-prod'|'dev-staging-prod'|'custom'} presetId - `id` of
		 *   the chosen preset card. A canned preset also pre-populates `versions` from
		 *   `PRESET_VERSIONS`; `custom` keeps any versions already chosen and
		 *   otherwise seeds a single Production row for step 3 to edit.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		selectPreset(presetId) {
			const update = { preset: presetId }

			// For canned presets, pre-populate the versions array.
			if (PRESET_VERSIONS[presetId]) {
				update.versions = PRESET_VERSIONS[presetId].map((v) => ({ ...v }))
			} else {
				// Custom — keep existing versions or seed a default Production row.
				if (!this.payload.versions || this.payload.versions.length === 0) {
					update.versions = [{ name: 'Production', slug: 'production' }]
				}
			}

			this.$emit('update:payload', update)
		},
	},
}
</script>

<style scoped>
.wizard-step2 {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.wizard-step2__heading {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0;
}

.wizard-step2__description {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.9rem;
	margin: 0;
}

.wizard-step2__presets {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px;
}

.wizard-step2__preset-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 14px 16px;
	border: 2px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
	cursor: pointer;
	text-align: left;
	transition:
		border-color 0.15s,
		box-shadow 0.15s;
}

.wizard-step2__preset-card:hover {
	border-color: var(--color-primary, #4376fc);
}

.wizard-step2__preset-card--selected {
	border-color: var(--color-primary, #4376fc);
	background: var(--color-primary-light, #e8effe);
	box-shadow: 0 0 0 2px var(--color-primary, #4376fc);
}

.wizard-step2__preset-name {
	font-size: 0.9rem;
	font-weight: 600;
	color: var(--color-main-text, #222);
}

.wizard-step2__preset-chain {
	font-family: monospace;
	font-size: 0.8rem;
	color: var(--color-primary, #4376fc);
}

.wizard-step2__preset-desc {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast, #555);
}

/*
 * WCAG 2.2 AA 2.3.3 Animation from Interactions. The preset card animates its
 * border and shadow on hover/selection; a reader who has asked their OS for
 * reduced motion must not get it. Scoped to this component's own selector
 * rather than a blanket `* { transition: none }` so it cannot reach into
 * NcButton / NcSelect internals.
 */
@media (prefers-reduced-motion: reduce) {
	.wizard-step2__preset-card {
		transition: none;
	}
}
</style>
