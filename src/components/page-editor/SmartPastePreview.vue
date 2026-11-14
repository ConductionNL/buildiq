<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  SmartPastePreview — the "Paste to fill" control of a form page, as the
  designer's preview shows it (ai-smart-paste-into-forms D5, REQ-BQSP-003).

  It runs inside the preview's own application and reads the page from the
  preview router, so it shows on the form page the maker is looking at. No
  model is called: the proposals are a fixed sample answer for the allowed
  fields, marked as suggestions, and nothing is submitted. The toggle is a
  disclosure (`aria-expanded`), which the preview's activation guard lets
  through, like any menu that only reveals UI.
-->
<template>
	<div v-if="page" class="smart-paste-preview" data-testid="smart-paste-preview">
		<div class="smart-paste-preview__bar">
			<button
				type="button"
				class="smart-paste-preview__toggle"
				data-testid="smart-paste-preview-toggle"
				:aria-expanded="open ? 'true' : 'false'"
				aria-controls="smart-paste-preview-panel"
				@click="open = !open">
				{{ t('buildiq', 'Paste to fill') }}
			</button>
			<span class="smart-paste-preview__note">
				{{ t('buildiq', 'Preview: a sample answer, no AI is called.') }}
			</span>
		</div>
		<div
			v-if="open"
			id="smart-paste-preview-panel"
			class="smart-paste-preview__panel">
			<p class="smart-paste-preview__note">
				{{ hint }}
			</p>
			<dl class="smart-paste-preview__proposals">
				<template v-for="proposal in proposals" :key="proposal.key">
					<dt>{{ proposal.label }}</dt>
					<dd :data-testid="'smart-paste-proposal-' + proposal.key">
						{{ proposal.value }}
						<span class="smart-paste-preview__badge">{{ t('buildiq', 'Suggestion') }}</span>
					</dd>
				</template>
			</dl>
			<p class="smart-paste-preview__note">
				{{ t('buildiq', 'The other fields stay empty, and nothing is submitted until the user clicks Submit.') }}
			</p>
		</div>
	</div>
</template>

<script>
import { formMode, isSmartPasteFillable } from '../../services/manifestValidation/formLogic.js'

/**
 * The first option of a select field, as a display value.
 *
 * @param {object} field - the select field.
 * @return {string} the option's label or value, or ''.
 */
function firstOption(field) {
	const option = Array.isArray(field.options) ? field.options[0] : undefined
	if (option && typeof option === 'object') {
		return String(option.label ?? option.value ?? '')
	}
	return option === undefined ? '' : String(option)
}

/**
 * The fixed sample value the preview proposes for a field.
 *
 * @param {object} field - a form field.
 * @param {(app: string, text: string) => string} t - the translate function.
 * @return {string} the value as shown.
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
export function sampleValue(field, t) {
	switch (field.type) {
	case 'number':
		return '42'
	case 'boolean':
		return t('buildiq', 'Yes')
	case 'date':
		return '2026-01-15'
	case 'select':
		return firstOption(field) || t('buildiq', 'Sample value')
	case 'textarea':
		return t('buildiq', 'Sample text from the pasted message')
	default:
		return t('buildiq', 'Sample value')
	}
}

/**
 * The form page with smart paste switched on, of a signed-in form, or null.
 *
 * @param {object} manifest - the manifest under edit.
 * @param {unknown} pageId - the preview route's name, the page id.
 * @return {?object} the page.
 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
 */
export function smartPastePage(manifest, pageId) {
	const pages = Array.isArray(manifest?.pages) ? manifest.pages : []
	const page = pages.find((p) => p && p.id === pageId && p.type === 'form')
	const config = page && page.config
	if (!config || !config.smartPaste || config.smartPaste.enabled !== true) {
		return null
	}
	return formMode(config) === 'public' ? null : page
}

export default {
	name: 'SmartPastePreview',

	props: {
		/**
		 * The manifest under edit.
		 */
		manifest: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return { open: false }
	},

	computed: {
		/**
		 * The previewed form page, when it offers smart paste.
		 *
		 * @return {?object}
		 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		 */
		page() {
			return smartPastePage(this.manifest, this.$route?.name)
		},

		/**
		 * The hint the maker wrote, or the default one.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		 */
		hint() {
			const hint = this.page?.config?.smartPaste?.hint
			return typeof hint === 'string' && hint !== ''
				? hint
				: this.t('buildiq', 'Paste an email or letter')
		},

		/**
		 * One sample proposal per allowed, fillable field, in the form's order.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/changes/ai-smart-paste-into-forms/specs/form-smart-paste/spec.md#requirement-the-authored-behaviour-is-fixed-for-the-renderer-req-bqsp-003
		 */
		proposals() {
			const config = this.page?.config || {}
			const allowed = new Set(Array.isArray(config.smartPaste?.fields) ? config.smartPaste.fields : [])
			const fields = Array.isArray(config.fields) ? config.fields : []
			return fields
				.filter((field) => isSmartPasteFillable(field) && allowed.has(field.key))
				.map((field) => ({
					key: field.key,
					label: field.label || field.key,
					value: sampleValue(field, this.t),
				}))
		},
	},

	watch: {
		/**
		 * Another page closes the panel.
		 *
		 * @return {void}
		 */
		page() {
			this.open = false
		},
	},
}
</script>

<style scoped>
.smart-paste-preview {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.smart-paste-preview__bar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.smart-paste-preview__toggle {
	padding: 4px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.smart-paste-preview__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.smart-paste-preview__proposals {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.smart-paste-preview__proposals dd {
	margin: 0;
}

.smart-paste-preview__badge {
	margin-inline-start: 6px;
	padding: 0 6px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
}
</style>
