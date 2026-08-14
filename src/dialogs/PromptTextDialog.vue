<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PromptTextDialog — a reusable single-line text prompt, replacing
  - `window.prompt()`.
  -
  - Same reasoning as ConfirmActionDialog: the native prompt blocks the JS
  - thread, cannot be translated or themed, and renders outside Nextcloud's
  - modal stack. It is also worse than confirm() for accessibility, because
  - its input has no programmatic label at all — here the field is labelled
  - properly via NcTextField.
  -
  - Contract: the parent owns the pending action; this component only emits
  - `submit` with the entered value. Cancelling or closing emits nothing, and
  - the submit button is disabled while the value is blank, so the caller can
  - never receive an empty string.
  -->
<template>
	<NcDialog v-if="open" :name="name" @closing="$emit('update:open', false)">
		<div class="prompt-text">
			<NcTextField
				v-model:value="value"
				:label="label"
				:placeholder="placeholder"
				@keydown.enter="onSubmit" />
		</div>
		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!value.trim()" @click="onSubmit">
				{{ confirmLabel || t('openbuild', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'PromptTextDialog',
	components: { NcDialog, NcButton, NcTextField },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Dialog title. */
		name: { type: String, default: '' },
		/** Accessible label for the input. */
		label: { type: String, default: '' },
		/** Placeholder shown in the input. */
		placeholder: { type: String, default: '' },
		/** Value the input starts with each time the dialog opens. */
		initialValue: { type: String, default: '' },
		/** Label for the submitting button; falls back to "Confirm". */
		confirmLabel: { type: String, default: '' },
	},

	emits: ['update:open', 'submit'],
	data() {
		return {
			value: this.initialValue,
		}
	},

	watch: {
		/**
		 * Re-seed the input each time the dialog opens.
		 *
		 * A previous edit must never leak into the next prompt —
		 * `window.prompt`'s default argument behaved this way, and the
		 * field-mapping flow depends on the suggestion being the leaf key.
		 *
		 * @param {boolean} shown - whether the dialog just became visible.
		 * @return {void}
		 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		open(shown) {
			if (shown) {
				this.value = this.initialValue
			}
		},
	},

	methods: {
		/**
		 * Emit the trimmed value, ignoring a blank entry.
		 *
		 * Blank is refused here as well as by the disabled submit button, so
		 * the caller can never receive an empty display-field name.
		 *
		 * @return {void}
		 * @spec openspec/specs/openconnector-api-sources/spec.md#req-ocas-003
		 */
		onSubmit() {
			const trimmed = this.value.trim()
			if (!trimmed) {
				return
			}
			this.$emit('submit', trimmed)
		},
	},
}
</script>

<style scoped>
.prompt-text {
	padding: 16px 24px 8px;
}
</style>
