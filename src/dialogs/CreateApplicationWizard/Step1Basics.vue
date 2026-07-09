<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 1 — Basics
  Collects the Application name, slug (auto-derived, editable via Advanced toggle),
  description, and optional light/dark icon uploads.
  spec: openbuild-app-creation-wizard REQ-OBWIZ-002, REQ-OBWIZ-005
-->
<template>
	<div class="wizard-step1">
		<h3 class="wizard-step1__heading">
			{{ t('openbuild', 'App basics') }}
		</h3>

		<!-- Name input -->
		<div class="wizard-step1__field">
			<label class="wizard-step1__label" for="wizard-app-name">
				{{ t('openbuild', 'Name') }} <span aria-hidden="true">*</span>
			</label>
			<input
				id="wizard-app-name"
				class="wizard-step1__input"
				type="text"
				:value="payload.name"
				:placeholder="t('openbuild', 'e.g. My Permit Tracker')"
				autocomplete="off"
				@input="onNameInput">
		</div>

		<!-- Slug chip + Advanced toggle -->
		<div class="wizard-step1__field wizard-step1__field--slug">
			<div class="wizard-step1__slug-row">
				<span class="wizard-step1__slug-label">
					{{ t('openbuild', 'Slug') }}:
				</span>
				<code class="wizard-step1__slug-chip" :class="{ 'wizard-step1__slug-chip--error': slugError }">
					{{ payload.slug || '—' }}
				</code>
				<button
					type="button"
					class="wizard-step1__advanced-toggle"
					@click="showAdvanced = !showAdvanced">
					{{ showAdvanced ? t('openbuild', 'Hide') : t('openbuild', 'Advanced') }}
				</button>
			</div>

			<div v-if="showAdvanced" class="wizard-step1__advanced">
				<input
					id="wizard-app-slug"
					class="wizard-step1__input"
					:class="{ 'wizard-step1__input--error': slugError }"
					type="text"
					:value="payload.slug"
					:placeholder="t('openbuild', 'kebab-case-slug')"
					autocomplete="off"
					@input="onSlugInput">
				<p v-if="slugError" class="wizard-step1__error-msg" role="alert">
					{{ slugError }}
				</p>
			</div>
		</div>

		<!-- Description textarea -->
		<div class="wizard-step1__field">
			<label class="wizard-step1__label" for="wizard-app-description">
				{{ t('openbuild', 'Description') }}
			</label>
			<textarea
				id="wizard-app-description"
				class="wizard-step1__textarea"
				:value="payload.description"
				:placeholder="t('openbuild', 'Optional: describe what this app does')"
				rows="3"
				@input="onDescriptionInput" />
		</div>

		<!-- App icon (optional) — pick a Material or OpenGemeenten glyph, or
		     upload your own SVG. A single pick yields a correct light/dark pair
		     (white glyph for the dark app header, dark glyph for light bg);
		     an optional dark override lets you use a different dark glyph. -->
		<div class="wizard-step1__field">
			<p class="wizard-step1__label">
				{{ t('openbuild', 'App icon (optional)') }}
			</p>

			<div class="wizard-step1__icon-row">
				<CnIconPicker
					:value="payload.iconValue"
					searchable
					clearable
					:sources="iconSources"
					:catalogues="iconCatalogues"
					@input="onIconInput" />

				<!-- Live preview of the resolved app icon on both backgrounds. -->
				<div class="wizard-step1__icon-preview">
					<div class="wizard-step1__preview-box wizard-step1__preview-box--light" :title="t('openbuild', 'On the app header (light icon)')">
						<!-- eslint-disable-next-line vue/no-v-html -->
						<span v-if="lightPreview" class="wizard-step1__preview-svg" v-html="lightPreview" />
						<span v-else class="wizard-step1__preview-empty">—</span>
					</div>
					<div class="wizard-step1__preview-box wizard-step1__preview-box--dark" :title="t('openbuild', 'On a light background (dark icon)')">
						<!-- eslint-disable-next-line vue/no-v-html -->
						<span v-if="darkPreview" class="wizard-step1__preview-svg" v-html="darkPreview" />
						<span v-else class="wizard-step1__preview-empty">—</span>
					</div>
				</div>
			</div>

			<!-- Upload fallback: read a user SVG as text and use it directly. -->
			<div class="wizard-step1__icon-actions">
				<label class="wizard-step1__advanced-toggle wizard-step1__upload-link">
					<input
						ref="uploadInput"
						type="file"
						accept=".svg,image/svg+xml"
						class="wizard-step1__file-input"
						@change="onUploadSvg">
					{{ t('openbuild', 'Upload your own SVG') }}
				</label>
				<button
					type="button"
					class="wizard-step1__advanced-toggle"
					@click="showDarkOverride = !showDarkOverride">
					{{ showDarkOverride ? t('openbuild', 'Hide dark override') : t('openbuild', 'Add a dark override') }}
				</button>
				<span v-if="iconError" class="wizard-step1__error-msg" role="alert">{{ iconError }}</span>
			</div>

			<!-- Optional dark-variant override. -->
			<div v-if="showDarkOverride" class="wizard-step1__dark-override">
				<p class="wizard-step1__file-label">
					{{ t('openbuild', 'Dark variant (optional — defaults to the icon above)') }}
				</p>
				<CnIconPicker
					:value="payload.iconDarkValue"
					searchable
					clearable
					:sources="iconSources"
					:catalogues="iconCatalogues"
					@input="onDarkInput" />
			</div>
		</div>
	</div>
</template>

<script>
import { CnIconPicker } from '@conduction/nextcloud-vue'
import { toKebabCase, validateSlug } from '../../utils/slugPattern.js'
import { ICON_SOURCES, buildIconCatalogues, resolveAppIcon } from '../../utils/iconCatalogues.js'

export default {
	name: 'Step1Basics',

	components: {
		CnIconPicker,
	},

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
			showDarkOverride: false,
			iconError: '',
			iconSources: ICON_SOURCES,
			// Frozen so Vue doesn't deep-observe ~7.7k catalogue entries.
			iconCatalogues: Object.freeze(buildIconCatalogues()),
		}
	},

	computed: {
		/**
		 * The resolved light app icon (white glyph) for the preview box, or ''.
		 *
		 * @return {string} SVG markup or an empty string.
		 */
		lightPreview() {
			return resolveAppIcon(this.payload.iconValue, { dark: false }) || ''
		},

		/**
		 * The resolved dark app icon (no fill) for the preview box. Falls back to
		 * the primary icon when no dark override is set, mirroring how the wizard
		 * derives the dark variant on submit.
		 *
		 * @return {string} SVG markup or an empty string.
		 */
		darkPreview() {
			const source = this.payload.iconDarkValue || this.payload.iconValue
			return resolveAppIcon(source, { dark: true }) || ''
		},

		/**
		 * Observed behaviour of `slugError` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		slugError() {
			if (!this.payload.slug) return null
			const result = validateSlug(this.payload.slug)
			return result.valid ? null : result.message
		},

		/**
		 * Observed behaviour of `isValid` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
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
		/**
		 * Observed behaviour of `onNameInput` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		onNameInput(event) {
			const name = event.target.value
			const update = { name }

			// Auto-derive slug from name unless the user has manually overridden it.
			if (!this.slugManuallyEdited) {
				update.slug = toKebabCase(name)
			}

			this.$emit('update:payload', update)
		},

		/**
		 * Observed behaviour of `onSlugInput` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		onSlugInput(event) {
			this.slugManuallyEdited = true
			this.$emit('update:payload', { slug: event.target.value })
		},

		/**
		 * Observed behaviour of `onDescriptionInput` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-2
		 */
		onDescriptionInput(event) {
			this.$emit('update:payload', { description: event.target.value })
		},

		/**
		 * Store the primary icon pick (an mdi/OpenGemeenten catalogue value or
		 * raw SVG). The app-icon SVG is synthesized from this on submit.
		 *
		 * @param {string|null} value The picker's emitted value.
		 * @return {void}
		 */
		onIconInput(value) {
			this.iconError = ''
			this.$emit('update:payload', { iconValue: value || null })
		},

		/**
		 * Store the optional dark-variant override.
		 *
		 * @param {string|null} value The picker's emitted value.
		 * @return {void}
		 */
		onDarkInput(value) {
			this.$emit('update:payload', { iconDarkValue: value || null })
		},

		/**
		 * Read an uploaded SVG file as text and use its markup as the icon value
		 * (the fallback for when no catalogue glyph fits).
		 *
		 * @param {Event} event The file-input change event.
		 * @return {void}
		 */
		onUploadSvg(event) {
			this.iconError = ''
			const file = event.target.files?.[0]
			if (!file) {
				return
			}
			const isSvg = file.type === 'image/svg+xml' || /\.svg$/i.test(file.name)
			if (!isSvg) {
				this.iconError = t('openbuild', 'Only .svg files are accepted')
				this.$refs.uploadInput.value = ''
				return
			}
			const reader = new FileReader()
			reader.onload = (e) => {
				const text = typeof e.target.result === 'string' ? e.target.result.trim() : ''
				if (!text.includes('<svg')) {
					this.iconError = t('openbuild', 'That file does not contain an SVG')
				} else {
					this.$emit('update:payload', { iconValue: text })
				}
				this.$refs.uploadInput.value = ''
			}
			reader.onerror = () => {
				this.iconError = t('openbuild', 'Could not read that file')
				this.$refs.uploadInput.value = ''
			}
			reader.readAsText(file)
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

.wizard-step1__icon-row {
	display: flex;
	gap: 16px;
	align-items: flex-start;
	flex-wrap: wrap;
}

.wizard-step1__icon-row > .cn-icon-picker {
	flex: 1 1 280px;
	min-width: 240px;
}

.wizard-step1__icon-preview {
	display: flex;
	gap: 8px;
}

.wizard-step1__preview-box {
	width: 44px;
	height: 44px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius, 4px);
	border: 1px solid var(--color-border, #ddd);
	flex-shrink: 0;
}

.wizard-step1__preview-box--light {
	background: #1c1c1e;
}

.wizard-step1__preview-box--dark {
	background: #ffffff;
}

.wizard-step1__preview-svg {
	display: inline-flex;
	width: 28px;
	height: 28px;
}

.wizard-step1__preview-svg :deep(svg) {
	width: 100%;
	height: 100%;
}

.wizard-step1__preview-empty {
	font-size: 18px;
	color: var(--color-text-maxcontrast, #888);
}

.wizard-step1__icon-actions {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
	margin-top: 8px;
}

.wizard-step1__upload-link {
	cursor: pointer;
}

.wizard-step1__dark-override {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wizard-step1__file-label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast, #555);
}

.wizard-step1__file-input {
	display: none;
}
</style>
