<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - InlineFieldMark — tiny presentational badge a page-type sub-editor
  - renders next to a config field when `useManifestValidator` reports an
  - error whose JSON-Pointer path falls under that field's registered
  - prefix. Implements the in-place affordance half of REQ-OBPD-011 (the
  - right-pane error list stays the overview).
  -
  - Pure component: no store, no injection. The owning sub-editor passes
  - `{ hasError, message }` (the shape `pageEditorValidator.errorFor(key)`
  - returns) and is responsible for also setting `aria-invalid` on its
  - own input. This component just paints the visible mark + the
  - assistive-tech text.
  -->
<template>
	<span
		v-if="error && error.hasError"
		class="inline-field-mark"
		role="alert">
		<span class="inline-field-mark__dot" aria-hidden="true">⚠</span>
		<span class="inline-field-mark__text">{{ error.message || t('openbuilt', 'This field has a validation error.') }}</span>
	</span>
</template>

<script>
export default {
	name: 'InlineFieldMark',
	props: {
		// { hasError: boolean, message: string } — usually the return of
		// the injected `pageEditorValidator.errorFor(configKey)`.
		error: {
			type: Object,
			default: null,
		},
	},
}
</script>

<style scoped>
.inline-field-mark {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	margin-top: 2px;
	font-size: 11px;
	color: var(--color-error);
	line-height: 1.3;
}
.inline-field-mark__dot {
	font-size: 12px;
}
</style>
