<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - StubPageEditor — fallback for page types absent from `SUB_EDITOR_MAP`
  - (unknown/future types only; every canonical v2 type has a dedicated
  - sub-editor as of REQ-PEC-001). Round-trips the config block losslessly
  - via a raw-JSON textarea so the editor never blanks externally authored
  - manifests for a type it doesn't recognise. `title` / `message` are
  - required props — `PageDesigner.vue`'s dispatch binding supplies both
  - whenever this component mounts.
  -->
<template>
	<div class="stub-page-editor">
		<h3>{{ title }}</h3>
		<p class="stub-page-editor__placeholder">
			{{ message }}
		</p>
		<textarea
			class="stub-page-editor__textarea"
			spellcheck="false"
			:value="jsonDraft"
			@input="onInput($event.target.value)" />
		<p v-if="parseError" class="stub-page-editor__error" role="alert">
			{{ parseError }}
		</p>
	</div>
</template>

<script>
export default {
	name: 'StubPageEditor',
	props: {
		title: {
			type: String,
			required: true,
		},
		message: {
			type: String,
			required: true,
		},
		config: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['update:config'],
	data() {
		return {
			jsonDraft: JSON.stringify(this.config || {}, null, 2),
			parseError: '',
		}
	},
	watch: {
		config: {
			deep: true,
			/**
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
			 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
			 */
			handler(val) {
				try {
					const fresh = JSON.stringify(val || {}, null, 2)
					if (fresh !== this.jsonDraft) {
						this.jsonDraft = fresh
					}
				} catch {
					// ignore — surfaced via the validator
				}
			},
		},
	},
	methods: {
		/**
		 * Observed behaviour of `onInput` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		onInput(value) {
			this.jsonDraft = value
			try {
				const parsed = JSON.parse(value)
				this.parseError = ''
				this.$emit('update:config', parsed)
			} catch (e) {
				this.parseError = e.message
			}
		},
	},
}
</script>

<style scoped>
.stub-page-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.stub-page-editor h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.stub-page-editor__placeholder {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.stub-page-editor__textarea {
	min-height: 240px;
	font-family: monospace;
	font-size: 13px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.stub-page-editor__error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
