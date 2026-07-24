<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AppCustomThemeSection — the "App theme" section on the page designer
  - (openspec/changes/app-theming). Sibling of `ThemeSection.vue`
  - (nldesign-theme-selection) — same controlled-component contract
  - (`manifest` prop in, `update:manifest` event out), rendered in
  - `PageDesignerHost.vue` alongside it. Deviation note: design.md guessed
  - this editor might live in `AppSettingsModal.vue`; the codebase's own
  - established reuse pattern for `runtime.*` theme editing is this
  - manifest-driven sibling-section placement (see `ThemeSection.vue`), so
  - this change follows that PRECEDENT instead — see the PR description
  - for the full rationale.
  -
  - Logo (opt-in dedicated upload): reuses the exact OR-attached-file
  - upload mechanism `IconUploadSection.vue` already implements for
  - `icon`/`iconDark` (POST .../files, PATCH-equivalent — here a manifest
  - field, not an Application field), targeting a distinct
  - `theme-logo.svg` filename and setting `appTheme.logoRef` instead of
  - touching `icon`/`iconDark` at all (design.md Decision D4). No new
  - backend route (proposal.md Impact).
  -
  - WCAG contrast (design.md Decision D2): `checkThemeContrast` runs
  - reactively on every edit and renders inline per-pair failures here;
  - the actual Save-blocking hard gate lives in `PageDesignerHost.save()`
  - (no bypass — a developer can freely EDIT a failing draft, but cannot
  - PERSIST it).
  -
  - @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
  - @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
  - @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
  -->
<template>
	<section class="ob-app-theme-section">
		<header class="ob-app-theme-section__header">
			<h3 class="ob-app-theme-section__title">
				{{ t('openbuild', 'App theme') }}
			</h3>
			<div class="ob-app-theme-section__actions">
				<NcButton v-if="!theme" type="secondary" @click="addTheme">
					{{ t('openbuild', 'Add a custom theme') }}
				</NcButton>
				<NcButton v-else type="tertiary" @click="removeTheme">
					{{ t('openbuild', 'Remove') }}
				</NcButton>
			</div>
		</header>

		<p v-if="!theme" class="ob-app-theme-section__default">
			{{ t('openbuild', 'Default (Nextcloud)') }}
		</p>

		<div v-else class="ob-app-theme-section__editor">
			<!-- Colors -->
			<div class="ob-app-theme-section__colors">
				<div v-for="field in colorFields" :key="field.key" class="ob-app-theme-section__color-field">
					<label :for="`ob-theme-color-${field.key}`" class="ob-app-theme-section__color-label">
						{{ field.label }}
					</label>
					<div class="ob-app-theme-section__color-inputs">
						<input
							:id="`ob-theme-color-${field.key}`"
							type="color"
							:value="theme[field.key]"
							class="ob-app-theme-section__color-swatch"
							@input="setColor(field.key, $event.target.value)">
						<NcTextField
							:value="theme[field.key]"
							:label="t('openbuild', '{field} hex value', { field: field.label })"
							class="ob-app-theme-section__color-hex"
							@update:value="setColor(field.key, $event)" />
					</div>
				</div>
			</div>

			<!-- Header style -->
			<NcSelect
				v-model="headerStyleOption"
				:input-label="t('openbuild', 'Header style')"
				:options="headerStyleOptions"
				:clearable="false"
				label="label" />

			<!-- Logo -->
			<div class="ob-app-theme-section__logo">
				<span class="ob-app-theme-section__logo-label">{{ t('openbuild', 'Theme logo') }}</span>
				<div class="ob-app-theme-section__logo-row">
					<img :src="defaultIconUrl" :alt="t('openbuild', 'App icon preview')" class="ob-app-theme-section__logo-preview">
					<span v-if="!usesDedicatedLogo" class="ob-app-theme-section__logo-hint">
						{{ t('openbuild', 'Using the app icon.') }}
					</span>
					<span v-else class="ob-app-theme-section__logo-hint">
						{{ t('openbuild', 'Using a dedicated theme logo: {name}', { name: theme.logoRef.ref }) }}
					</span>
					<label class="ob-app-theme-section__logo-upload">
						<input
							ref="logoInput"
							type="file"
							accept=".svg"
							class="ob-app-theme-section__logo-file-input"
							:disabled="!applicationUuid || uploadingLogo"
							@change="onLogoFileChange">
						<span>{{ t('openbuild', 'Upload a dedicated logo') }}</span>
					</label>
					<NcButton v-if="usesDedicatedLogo" type="tertiary" @click="clearDedicatedLogo">
						{{ t('openbuild', 'Use app icon instead') }}
					</NcButton>
				</div>
				<p v-if="logoUploadError" class="ob-app-theme-section__error" role="alert">
					{{ logoUploadError }}
				</p>
			</div>

			<!-- Live preview swatch strip -->
			<div class="ob-app-theme-section__preview">
				<span class="ob-app-theme-section__swatch" :style="{ background: theme.primaryColor }" :title="t('openbuild', 'Primary')" />
				<span class="ob-app-theme-section__swatch" :style="{ background: theme.secondaryColor }" :title="t('openbuild', 'Secondary')" />
				<span class="ob-app-theme-section__swatch" :style="{ background: theme.accentColor }" :title="t('openbuild', 'Accent')" />
			</div>

			<!-- WCAG contrast — inline per-pair failures, no bypass -->
			<div v-if="!contrastResult.passed" class="ob-app-theme-section__contrast-failures" role="alert">
				<p class="ob-app-theme-section__contrast-heading">
					{{ t('openbuild', 'This theme does not meet WCAG contrast requirements — fix the colors below before saving:') }}
				</p>
				<ul>
					<li v-for="(failure, i) in contrastResult.failures" :key="i">
						<strong>{{ failure.pair }}</strong>: {{ failure.ratio }}:1
						({{ t('openbuild', 'requires') }} {{ failure.required }}:1 {{ failure.kind === 'text' ? t('openbuild', 'for text') : t('openbuild', 'for UI elements') }})
					</li>
				</ul>
			</div>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { checkThemeContrast } from '../services/checkThemeContrast.js'

/**
 * Starting colors for "Add a custom theme" — verified (design/implementation
 * time, not guessed) to pass `checkThemeContrast` against the pinned
 * `#FFFFFF` background so a developer's first look at the section is not an
 * immediate failure state.
 */
const DEFAULT_THEME = Object.freeze({
	logoRef: null,
	primaryColor: '#1D4ED8',
	secondaryColor: '#0F172A',
	accentColor: '#B45309',
	headerStyle: 'default',
})

export default {
	name: 'AppCustomThemeSection',
	components: { NcButton, NcSelect, NcTextField },
	props: {
		manifest: {
			type: Object,
			default: () => ({}),
		},
		/** The App's own slug, used for the default app-icon preview URL. */
		appSlug: {
			type: String,
			default: '',
		},
		/** The owning Application's OR uuid — required to upload/resolve a dedicated logo. */
		applicationUuid: {
			type: String,
			default: '',
		},
	},
	emits: ['update:manifest'],
	data() {
		return {
			uploadingLogo: false,
			logoUploadError: '',
		}
	},
	computed: {
		/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style */
		theme() {
			return (this.manifest && this.manifest.runtime && this.manifest.runtime.appTheme) || null
		},
		/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style */
		colorFields() {
			return [
				{ key: 'primaryColor', label: t('openbuild', 'Primary color') },
				{ key: 'secondaryColor', label: t('openbuild', 'Secondary color') },
				{ key: 'accentColor', label: t('openbuild', 'Accent color') },
			]
		},
		/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style */
		headerStyleOptions() {
			return [
				{ id: 'default', label: t('openbuild', 'Default') },
				{ id: 'compact', label: t('openbuild', 'Compact') },
				{ id: 'branded', label: t('openbuild', 'Branded (shows the logo in the app header)') },
			]
		},
		headerStyleOption: {
			/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style */
			get() {
				const id = (this.theme && this.theme.headerStyle) || 'default'
				return this.headerStyleOptions.find((o) => o.id === id) || this.headerStyleOptions[0]
			},
			/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style */
			set(option) {
				this.updateTheme({ headerStyle: (option && option.id) || 'default' })
			},
		},
		/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields */
		defaultIconUrl() {
			return this.appSlug ? generateUrl(`/apps/openbuild/icons/${this.appSlug}.svg`) : ''
		},
		/** @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields */
		usesDedicatedLogo() {
			return !!(this.theme && this.theme.logoRef && this.theme.logoRef.ref)
		},
		/**
		 * Live WCAG contrast result for the current draft — recomputed on
		 * every edit (design.md Decision D2).
		 *
		 * @return {{passed: boolean, failures: Array}}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-wcag-contrast-guardrail-blocks-saving-a-non-compliant-theme
		 */
		contrastResult() {
			return checkThemeContrast(this.theme)
		},
	},
	methods: {
		/**
		 * Start a new appTheme block from verified-passing defaults.
		 *
		 * @return {void}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
		 */
		addTheme() {
			this.$emit('update:manifest', this.withTheme({ ...DEFAULT_THEME }))
		},
		/**
		 * Remove the appTheme block entirely (manifest stays byte-identical
		 * to a themeless app).
		 *
		 * @return {void}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
		 */
		removeTheme() {
			this.$emit('update:manifest', this.withTheme(null))
		},
		/**
		 * Merge a partial update into the current theme and emit.
		 *
		 * @param {object} patch - fields to merge onto the current theme.
		 * @return {void}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
		 */
		updateTheme(patch) {
			if (!this.theme) {
				return
			}
			this.$emit('update:manifest', this.withTheme({ ...this.theme, ...patch }))
		},
		/**
		 * Set a single color field from either the native color input or the
		 * paired hex text field.
		 *
		 * @param {string} key - `primaryColor`/`secondaryColor`/`accentColor`.
		 * @param {string} value - the new hex value.
		 * @return {void}
		 */
		setColor(key, value) {
			this.updateTheme({ [key]: value })
		},
		/**
		 * Return a manifest copy with `runtime.appTheme` set (or removed when
		 * null so themeless manifests serialize byte-identically). Never
		 * touches `dependencies[]` (matches `ThemeSection.vue`'s pattern).
		 *
		 * @param {?object} theme - the appTheme object, or null to clear.
		 * @return {object}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-apptheme-manifest-block-declares-logo-colors-and-header-style
		 */
		withTheme(theme) {
			const next = { ...this.manifest }
			const runtime = { ...(next.runtime || {}) }
			if (theme) {
				runtime.appTheme = theme
			} else {
				delete runtime.appTheme
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
			return next
		},
		/**
		 * Upload a dedicated theme logo — same OR-attached-file mechanism
		 * `IconUploadSection.vue` uses for `icon`/`iconDark`, targeting a
		 * distinct `theme-logo.svg` filename and setting `appTheme.logoRef`
		 * (never touching the Application's own icon fields).
		 *
		 * @param {Event} event - the file input change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		async onLogoFileChange(event) {
			this.logoUploadError = ''
			const file = event.target.files && event.target.files[0]
			if (!file || !file.name.toLowerCase().endsWith('.svg')) {
				this.logoUploadError = t('openbuild', 'Only .svg files are accepted')
				if (this.$refs.logoInput) {
					this.$refs.logoInput.value = ''
				}
				return
			}
			if (!this.applicationUuid) {
				return
			}
			this.uploadingLogo = true
			try {
				const filename = 'theme-logo.svg'
				const content = await file.text()
				const uploadUrl = generateUrl(`/apps/openregister/api/objects/openbuild/application/${this.applicationUuid}/files`)
				await axios.post(uploadUrl, { name: filename, content })
				this.updateTheme({ logoRef: { ref: filename } })
			} catch (error) {
				this.logoUploadError = (error && error.response && error.response.data && error.response.data.message)
					|| t('openbuild', 'Upload failed — please try again')
			} finally {
				this.uploadingLogo = false
				if (this.$refs.logoInput) {
					this.$refs.logoInput.value = ''
				}
			}
		},
		/**
		 * Revert to the default app-icon logo (`logoRef: null`) without
		 * deleting the previously uploaded file.
		 *
		 * @return {void}
		 * @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-application-s-existing-icon-fields
		 */
		clearDedicatedLogo() {
			this.updateTheme({ logoRef: null })
		},
	},
}
</script>

<style scoped>
.ob-app-theme-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.ob-app-theme-section__title {
	margin: 0;
}

.ob-app-theme-section__default {
	color: var(--color-text-maxcontrast);
}

.ob-app-theme-section__editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.ob-app-theme-section__colors {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.ob-app-theme-section__color-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ob-app-theme-section__color-inputs {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ob-app-theme-section__color-swatch {
	width: 36px;
	height: 36px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.ob-app-theme-section__color-hex {
	width: 120px;
}

.ob-app-theme-section__logo {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-app-theme-section__logo-row {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
}

.ob-app-theme-section__logo-preview {
	width: 32px;
	height: 32px;
	object-fit: contain;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: #fff;
}

.ob-app-theme-section__logo-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.ob-app-theme-section__logo-file-input {
	display: none;
}

.ob-app-theme-section__logo-upload {
	cursor: pointer;
	padding: 4px 10px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-size: 0.85em;
}

.ob-app-theme-section__preview {
	display: flex;
	gap: 8px;
}

.ob-app-theme-section__swatch {
	width: 28px;
	height: 28px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	display: inline-block;
}

.ob-app-theme-section__contrast-failures {
	background: var(--color-error, #d63f3f);
	color: var(--color-primary-text, #fff);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.ob-app-theme-section__contrast-heading {
	margin: 0 0 4px;
	font-weight: 600;
}

.ob-app-theme-section__error {
	color: var(--color-error-text, var(--color-error));
	font-size: 0.85em;
}
</style>
