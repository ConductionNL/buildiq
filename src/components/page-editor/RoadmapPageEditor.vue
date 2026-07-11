<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RoadmapPageEditor — structured editor for `type: "roadmap"` pages
  - (REQ-PEC-004).
  -
  - Manifest contract verified against `CnFeaturesAndRoadmapPage.vue`: every
  - key resolves manifest `config.<key>` > `loadState('<appId>',
  - 'features_roadmap_<key>')` initialState > a built-in fallback, so every
  - field here is optional. `repo` (`owner/repo`), `forge`
  - (`{ type: 'codeberg'|'forgejo'|'gitea'|'github', baseUrl? }`, written as
  - one object — unsetting `forge.type` deletes the whole `forge` key),
  - `disabled` (admin opt-out mirroring the
  - `openregister::features_roadmap_enabled` flag), and the four CTA/doc URL
  - overrides (`documentationUrl`, `suggestUrl`, `openbuiltUrl`,
  - `llmSkillsUrl`). `features[]` is normally server-provided via
  - initialState (see OpenBuild's own FeaturesRoadmap.vue) and is
  - deliberately Raw-JSON-only here (design.md Decision 4) — this editor
  - never touches it, so it survives every form edit untouched.
  -
  - Lossless round-trip: `update(key, value)` clones `config` and only
  - touches the one key, so externally-authored keys (incl. `features[]`)
  - survive every edit.
  -->
<template>
	<div class="roadmap-page-editor">
		<h3 class="roadmap-page-editor__title">
			{{ t('openbuild', 'Roadmap page') }}
		</h3>

		<p class="roadmap-page-editor__hint" role="note">
			{{ t('openbuild', 'Every field below resolves manifest config first, then the matching features_roadmap_KEY initialState value, then a built-in fallback. The features list itself is normally server-provided; edit it via the Raw JSON tab.') }}
		</p>

		<fieldset class="roadmap-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Repository') }}</legend>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'Repo') }}
				<input
					type="text"
					:value="config.repo || ''"
					:placeholder="t('openbuild', 'owner/repo')"
					:aria-invalid="isInvalid('repo')"
					@input="update('repo', $event.target.value)">
				<InlineFieldMark :error="markFor('repo')" />
			</label>
		</fieldset>

		<fieldset class="roadmap-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Forge') }}</legend>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'Forge type') }}
				<select
					:value="(config.forge && config.forge.type) || ''"
					:aria-invalid="isInvalid('forge')"
					@change="updateForgeType($event.target.value)">
					<option value="">
						{{ t('openbuild', '— not set —') }}
					</option>
					<option value="codeberg">
						codeberg
					</option>
					<option value="forgejo">
						forgejo
					</option>
					<option value="gitea">
						gitea
					</option>
					<option value="github">
						github
					</option>
				</select>
			</label>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'Forge base URL (optional)') }}
				<input
					type="text"
					:value="(config.forge && config.forge.baseUrl) || ''"
					:disabled="!(config.forge && config.forge.type)"
					:placeholder="t('openbuild', 'https://codeberg.org')"
					@input="updateForgeField('baseUrl', $event.target.value)">
			</label>
			<InlineFieldMark :error="markFor('forge')" />
		</fieldset>

		<fieldset class="roadmap-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Admin opt-out') }}</legend>
			<label class="roadmap-page-editor__inline">
				<input
					type="checkbox"
					:checked="config.disabled === true"
					@change="update('disabled', $event.target.checked)">
				{{ t('openbuild', 'Disabled') }}
			</label>
			<InlineFieldMark :error="markFor('disabled')" />
		</fieldset>

		<fieldset class="roadmap-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Overrides (optional)') }}</legend>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'Documentation URL') }}
				<input
					type="text"
					:value="config.documentationUrl || ''"
					:aria-invalid="isInvalid('documentationUrl')"
					@input="update('documentationUrl', $event.target.value)">
				<InlineFieldMark :error="markFor('documentationUrl')" />
			</label>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'Suggest URL') }}
				<input
					type="text"
					:value="config.suggestUrl || ''"
					:aria-invalid="isInvalid('suggestUrl')"
					@input="update('suggestUrl', $event.target.value)">
				<InlineFieldMark :error="markFor('suggestUrl')" />
			</label>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'OpenBuilt URL') }}
				<input
					type="text"
					:value="config.openbuiltUrl || ''"
					:aria-invalid="isInvalid('openbuiltUrl')"
					@input="update('openbuiltUrl', $event.target.value)">
				<InlineFieldMark :error="markFor('openbuiltUrl')" />
			</label>
			<label class="roadmap-page-editor__group-row">
				{{ t('openbuild', 'LLM skills URL') }}
				<input
					type="text"
					:value="config.llmSkillsUrl || ''"
					:aria-invalid="isInvalid('llmSkillsUrl')"
					@input="update('llmSkillsUrl', $event.target.value)">
				<InlineFieldMark :error="markFor('llmSkillsUrl')" />
			</label>
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'RoadmapPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'roadmap',
		},
		appSlug: {
			type: String,
			default: '',
		},
		// Kept for contract uniformity with the other sub-editors; the
		// roadmap page needs no register/schema picker.
		dataRegisters: {
			type: Array,
			default: () => [],
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	computed: {
		validatedConfigKeys() {
			return ['repo', 'forge', 'disabled', 'documentationUrl', 'suggestUrl', 'openbuiltUrl', 'llmSkillsUrl']
		},
	},
	methods: {
		/**
		 * Lossless top-level `config` key update: clone, delete-on-empty,
		 * touch only the edited key.
		 *
		 * @param {string} key - top-level config key.
		 * @param {*} value - new value.
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || (Array.isArray(value) && value.length === 0)) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Update `forge.type`; unsetting it deletes the whole `forge` key
		 * (an empty `{ baseUrl }` with no type is not a valid forge shape).
		 *
		 * @param {string} value - forge type or ''.
		 */
		updateForgeType(value) {
			if (value === '') {
				this.update('forge', null)
				return
			}
			const next = { ...(this.config.forge || {}), type: value }
			this.update('forge', next)
		},
		/**
		 * Update a non-`type` `forge` field (currently only `baseUrl`),
		 * preserving `forge.type`.
		 *
		 * @param {string} key - forge field key.
		 * @param {string} value - new value.
		 */
		updateForgeField(key, value) {
			const next = { ...(this.config.forge || {}) }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.update('forge', Object.keys(next).length === 0 ? null : next)
		},
	},
}
</script>

<style scoped>
.roadmap-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.roadmap-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.roadmap-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.roadmap-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.roadmap-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.roadmap-page-editor__group-row input,
.roadmap-page-editor__group-row select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.roadmap-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	font-size: 13px;
}

.roadmap-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
