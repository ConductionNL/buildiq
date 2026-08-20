<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FilesPageEditor — structured editor for `type: "files"` pages (task 4.7).
  -
  - Manifest contract: `{ folder, allowedTypes? }`:
  -   - `folder` — the (virtual-app-relative) folder path the file browser
  -     is rooted at;
  -   - `allowedTypes` — an optional array of MIME types / extensions the
  -     browser restricts uploads + listing to. Edited as a tag input
  -     (type a value, press Enter / comma to add it; ✕ removes one) with
  -     a small suggestion list of common types.
  -
  - `update(key, value)` clones `config` and only touches the one key so
  - externally-authored extra keys round-trip losslessly.
  -->
<template>
	<div class="files-page-editor">
		<h3 class="files-page-editor__title">
			{{ t('openbuild', 'Files page') }}
		</h3>

		<fieldset class="files-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Root folder') }}</legend>
			<label class="files-page-editor__group-row">
				{{ t('openbuild', 'Folder path') }}
				<input
					type="text"
					:value="config.folder || ''"
					:placeholder="t('openbuild', 'e.g. /Documents or Attachments')"
					:aria-invalid="isInvalid('folder')"
					@input="update('folder', $event.target.value)" />
				<InlineFieldMark :error="markFor('folder')" />
			</label>
		</fieldset>

		<fieldset class="files-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Allowed types (optional)') }}</legend>
			<div class="files-page-editor__tags">
				<span
					v-for="(typ, index) in allowedTypes"
					:key="index"
					class="files-page-editor__tag">
					{{ typ }}
					<button
						type="button"
						class="files-page-editor__tag-remove"
						:title="t('openbuild', 'Remove type')"
						@click="removeType(index)">
						✕
					</button>
				</span>
				<input
					ref="typeInput"
					v-model="typeDraft"
					type="text"
					class="files-page-editor__tag-input"
					list="files-page-editor-type-suggestions"
					:placeholder="t('openbuild', 'Add type, press Enter')"
					:aria-label="t('openbuild', 'Add type, press Enter')"
					:aria-invalid="isInvalid('allowedTypes')"
					@keydown.enter.prevent="commitDraft"
					@keydown.,.prevent="commitDraft"
					@blur="commitDraft" />
				<datalist id="files-page-editor-type-suggestions">
					<option
						v-for="opt in TYPE_SUGGESTIONS"
						:key="opt"
						:value="opt" />
				</datalist>
			</div>
			<InlineFieldMark :error="markFor('allowedTypes')" />
			<p class="files-page-editor__hint">
				{{
					t(
						'openbuild',
						'MIME types (image/png) or extensions (.pdf). Leave empty to allow everything.',
					)
				}}
			</p>
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

const TYPE_SUGGESTIONS = [
	'image/png',
	'image/jpeg',
	'image/*',
	'application/pdf',
	'text/plain',
	'text/csv',
	'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
	'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
	'.pdf',
	'.docx',
	'.xlsx',
	'.csv',
	'.png',
	'.jpg',
]

export default {
	name: 'FilesPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},

		pageType: {
			type: String,
			default: 'files',
		},

		appSlug: {
			type: String,
			default: '',
		},

		parentRoute: {
			type: String,
			default: '',
		},
	},

	emits: ['update:config'],
	data() {
		return {
			typeDraft: '',
			TYPE_SUGGESTIONS,
		}
	},

	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['folder', 'allowedTypes']
		},

		/**
		 * Observed behaviour of `allowedTypes` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		allowedTypes() {
			return Array.isArray(this.config.allowedTypes)
				? this.config.allowedTypes
				: []
		},
	},

	methods: {
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, so config keys this editor does not surface round-trip
		 * losslessly.
		 *
		 * @param {string} key - the config key being written: `folder` or `allowedTypes`.
		 * @param {string|string[]} value - the new value: the folder path from the text input, or the full replacement tag list. An empty string or empty array deletes the key — that is how "allow everything" is expressed.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (
				value === ''
				|| value === null
				|| (Array.isArray(value) && value.length === 0)
			) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Observed behaviour of `commitDraft` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		commitDraft() {
			const value = (this.typeDraft || '').trim()
			this.typeDraft = ''
			if (!value || this.allowedTypes.includes(value)) {
				return
			}
			this.update('allowedTypes', [...this.allowedTypes, value])
		},

		/**
		 * Remove one allowed-type tag. Removing the last one deletes
		 * `allowedTypes` altogether (via `update`), which the runtime reads
		 * as "allow everything".
		 *
		 * @param {number} index - position of the tag in `allowedTypes`, from the `v-for` over the tag chips.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		removeType(index) {
			const next = this.allowedTypes.slice()
			next.splice(index, 1)
			this.update('allowedTypes', next)
		},
	},
}
</script>

<style scoped>
.files-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.files-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.files-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.files-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.files-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.files-page-editor__group-row input {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.files-page-editor__tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.files-page-editor__tag {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	font-size: 12px;
}

.files-page-editor__tag-remove {
	background: transparent;
	border: none;
	cursor: pointer;
	color: var(--color-error, var(--color-main-text));
	font-size: 11px;
	line-height: 1;
}

.files-page-editor__tag-input {
	flex: 1 1 160px;
	min-width: 120px;
	border: none;
	background: transparent;
	color: var(--color-main-text);
	font-size: 13px;
	outline: none;
}

.files-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
