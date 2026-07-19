<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ThemeSection — the "Theme" section on the application-detail/designer
  - surface (REQ-NTS-002, REQ-NTS-005). Shows the current NL Design theme
  - (swatches + name) or "Default (Nextcloud)", with Change / Remove actions,
  - hosting the standalone ThemePickerDialog (modal-isolation rule).
  -
  - Pure controlled component: `manifest` prop in, `update:manifest` event out.
  - Saving or clearing a theme NEVER touches `dependencies[]` — the theme is a
  - progressive enhancement, not a hard dependency (design.md Decision 4).
  -->
<template>
	<section class="ob-theme-section">
		<header class="ob-theme-section__header">
			<h3 class="ob-theme-section__title">
				{{ t('openbuild', 'Theme') }}
			</h3>
			<div class="ob-theme-section__actions">
				<NcButton
					type="secondary"
					:disabled="!nldesignAvailable"
					:title="nldesignAvailable ? '' : t('openbuild', 'NL Design is not installed or enabled on this instance.')"
					@click="openPicker">
					{{ t('openbuild', 'Change') }}
				</NcButton>
				<NcButton
					v-if="theme"
					type="tertiary"
					@click="removeTheme">
					{{ t('openbuild', 'Remove') }}
				</NcButton>
			</div>
		</header>

		<p v-if="!nldesignAvailable" class="ob-theme-section__hint">
			{{ t('openbuild', 'NL Design is not available. An existing theme stays visible and removable, but you cannot change it.') }}
		</p>

		<div v-if="theme" class="ob-theme-section__current">
			<span class="ob-theme-section__swatch" :style="{ background: primaryColor }" />
			<span class="ob-theme-section__swatch" :style="{ background: backgroundColor }" />
			<strong>{{ theme.tokenSetName || theme.tokenSet }}</strong>
		</div>
		<p v-else class="ob-theme-section__default">
			{{ t('openbuild', 'Default (Nextcloud)') }}
		</p>

		<ThemePickerDialog
			v-model:open="dialogOpen"
			:theme="theme"
			:nldesign-available="nldesignAvailable"
			@save="onSave"
			@clear="removeTheme"
			@preview="$emit('preview', $event)" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ThemePickerDialog from '../dialogs/ThemePickerDialog.vue'

export default {
	name: 'ThemeSection',
	components: { NcButton, ThemePickerDialog },
	props: {
		manifest: {
			type: Object,
			default: () => ({}),
		},
		nldesignAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:manifest', 'preview'],
	data() {
		return {
			dialogOpen: false,
		}
	},
	computed: {
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		theme() {
			return (this.manifest && this.manifest.runtime && this.manifest.runtime.theme) || null
		},
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		primaryColor() {
			return (this.theme && this.theme.preview && this.theme.preview.primaryColor) || 'var(--color-primary-element)'
		},
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		backgroundColor() {
			return (this.theme && this.theme.preview && this.theme.preview.backgroundColor) || 'var(--color-main-background)'
		},
	},
	methods: {
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		openPicker() {
			if (!this.nldesignAvailable) {
				return
			}
			this.dialogOpen = true
		},
		/**
		 * Persist the chosen theme into `runtime.theme`.
		 *
		 * @param {object} theme - the runtime.theme object.
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-001
		 */
		onSave(theme) {
			this.$emit('update:manifest', this.withTheme(theme))
		},
		/**
		 * Remove the theme (delete `runtime.theme` entirely).
		 *
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		removeTheme() {
			const ok = typeof window !== 'undefined' && window.confirm
				? window.confirm(t('openbuild', 'Remove the theme? This app will render in the default Nextcloud styling.'))
				: true
			if (!ok) {
				return
			}
			this.$emit('update:manifest', this.withTheme(null))
		},
		/**
		 * Return a manifest copy with `runtime.theme` set (or removed when null
		 * so themeless manifests serialize byte-identically). Never touches
		 * `dependencies[]`.
		 *
		 * @param {?object} theme - the theme object, or null to clear.
		 * @return {object}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-001
		 */
		withTheme(theme) {
			const next = { ...this.manifest }
			const runtime = { ...(next.runtime || {}) }
			if (theme) {
				runtime.theme = theme
			} else {
				delete runtime.theme
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
			return next
		},
	},
}
</script>

<style scoped>
.ob-theme-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}
.ob-theme-section__title {
	margin: 0;
}
.ob-theme-section__actions {
	display: flex;
	gap: 8px;
}
.ob-theme-section__hint,
.ob-theme-section__default {
	color: var(--color-text-maxcontrast);
}
.ob-theme-section__current {
	display: flex;
	align-items: center;
	gap: 8px;
}
.ob-theme-section__swatch {
	width: 24px;
	height: 24px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	display: inline-block;
}
</style>
