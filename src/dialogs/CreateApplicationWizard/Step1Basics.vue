<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 1 — Basics
  Collects the Application name, slug (auto-derived, editable via Advanced toggle),
  description, and optional light/dark icon uploads.
  spec: openbuilt-app-creation-wizard REQ-OBWIZ-002, REQ-OBWIZ-005
-->
<template>
	<div class="wizard-step1">
		<h3 class="wizard-step1__heading">
			{{ t('openbuilt', 'App basics') }}
		</h3>

		<!-- Name input -->
		<div class="wizard-step1__field">
			<label class="wizard-step1__label" for="wizard-app-name">
				{{ t('openbuilt', 'Name') }} <span aria-hidden="true">*</span>
			</label>
			<input
				id="wizard-app-name"
				class="wizard-step1__input"
				type="text"
				:value="payload.name"
				:placeholder="t('openbuilt', 'e.g. My Permit Tracker')"
				autocomplete="off"
				@input="onNameInput" />
		</div>

		<!-- Slug chip + Advanced toggle -->
		<div class="wizard-step1__field wizard-step1__field--slug">
			<div class="wizard-step1__slug-row">
				<span class="wizard-step1__slug-label">
					{{ t('openbuilt', 'Slug') }}:
				</span>
				<code class="wizard-step1__slug-chip" :class="{ 'wizard-step1__slug-chip--error': slugError }">
					{{ payload.slug || '—' }}
				</code>
				<button
					type="button"
					class="wizard-step1__advanced-toggle"
					@click="showAdvanced = !showAdvanced">
					{{ showAdvanced ? t('openbuilt', 'Hide') : t('openbuilt', 'Advanced') }}
				</button>
			</div>

			<div v-if="showAdvanced" class="wizard-step1__advanced">
				<input
					id="wizard-app-slug"
					class="wizard-step1__input"
					:class="{ 'wizard-step1__input--error': slugError }"
					type="text"
					:value="payload.slug"
					:placeholder="t('openbuilt', 'kebab-case-slug')"
					autocomplete="off"
					@input="onSlugInput" />
				<p v-if="slugError" class="wizard-step1__error-msg" role="alert">
					{{ slugError }}
				</p>
			</div>
		</div>

		<!-- Description textarea -->
		<div class="wizard-step1__field">
			<label class="wizard-step1__label" for="wizard-app-description">
				{{ t('openbuilt', 'Description') }}
			</label>
			<textarea
				id="wizard-app-description"
				class="wizard-step1__textarea"
				:value="payload.description"
				:placeholder="t('openbuilt', 'Optional: describe what this app does')"
				rows="3"
				@input="onDescriptionInput" />
		</div>

		<!-- Icon uploads (optional) -->
		<div class="wizard-step1__field">
			<p class="wizard-step1__label">
				{{ t('openbuilt', 'App icon (optional)') }}
			</p>
			<div class="wizard-step1__icons">
				<div class="wizard-step1__icon-slot">
					<label for="wizard-icon-light" class="wizard-step1__file-label">
						{{ t('openbuilt', 'Light icon (SVG)') }}
					</label>
					<input
						id="wizard-icon-light"
						type="file"
						accept=".svg,image/svg+xml"
						class="wizard-step1__file-input"
						@change="onIconChange('icon', $event)" />
				</div>
				<div class="wizard-step1__icon-slot">
					<label for="wizard-icon-dark" class="wizard-step1__file-label">
						{{ t('openbuilt', 'Dark icon (SVG)') }}
					</label>
					<input
						id="wizard-icon-dark"
						type="file"
						accept=".svg,image/svg+xml"
						class="wizard-step1__file-input"
						@change="onIconChange('iconDark', $event)" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { toKebabCase, validateSlug } from '../../utils/slugPattern.js'

export default {
	name: 'Step1Basics',

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

	data() {
		return {
			showAdvanced: false,
			slugManuallyEdited: false,
		}
	},

	computed: {
		slugError() {
			if (!this.payload.slug) return null
			const result = validateSlug(this.payload.slug)
			return result.valid ? null : result.message
		},

		isValid() {
			return (
				(this.payload.name || '').trim() !== ''
				&& (this.payload.slug || '').trim() !== ''
				&& this.slugError === null
			)
		},
	},

	watch: {
		isValid(newVal) {
			this.$emit('update:payload', { _step1Valid: newVal })
		},
	},

	methods: {
		onNameInput(event) {
			const name = event.target.value
			const update = { name }

			// Auto-derive slug from name unless the user has manually overridden it.
			if (!this.slugManuallyEdited) {
				update.slug = toKebabCase(name)
			}

			this.$emit('update:payload', update)
		},

		onSlugInput(event) {
			this.slugManuallyEdited = true
			this.$emit('update:payload', { slug: event.target.value })
		},

		onDescriptionInput(event) {
			this.$emit('update:payload', { description: event.target.value })
		},

		onIconChange(field, event) {
			const file = event.target.files?.[0] || null
			this.$emit('update:payload', { [field]: file })
		},
	},
}
</script>

<style scoped>
.wizard-step1 {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.wizard-step1__heading {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0 0 8px;
}

.wizard-step1__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.wizard-step1__label {
	font-weight: 500;
	font-size: 0.875rem;
	color: var(--color-text-maxcontrast, #555);
}

.wizard-step1__input,
.wizard-step1__textarea {
	padding: 8px 10px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	font-size: 0.9rem;
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
	width: 100%;
	box-sizing: border-box;
}

.wizard-step1__input--error {
	border-color: var(--color-error, #e9322d);
}

.wizard-step1__slug-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.wizard-step1__slug-chip {
	padding: 2px 8px;
	border-radius: 4px;
	background: var(--color-background-dark, #f5f5f5);
	font-size: 0.875rem;
}

.wizard-step1__slug-chip--error {
	background: var(--color-error-soft, #fdecea);
	color: var(--color-error, #e9322d);
}

.wizard-step1__advanced-toggle {
	border: none;
	background: none;
	color: var(--color-primary, #4376fc);
	cursor: pointer;
	font-size: 0.8rem;
	padding: 0;
}

.wizard-step1__advanced {
	margin-top: 6px;
}

.wizard-step1__error-msg {
	color: var(--color-error, #e9322d);
	font-size: 0.8rem;
	margin: 4px 0 0;
}

.wizard-step1__icons {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.wizard-step1__icon-slot {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.wizard-step1__file-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #555);
}

.wizard-step1__file-input {
	font-size: 0.875rem;
}
</style>
