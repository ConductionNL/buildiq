<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ConfirmActionDialog — a reusable confirmation step for actions that were
  - previously guarded by `window.confirm()`.
  -
  - Native `window.confirm` is blocked by gate-34 for three reasons that all
  - apply here: it blocks the JS thread, its chrome cannot be translated or
  - themed, and inside Nextcloud's modal stack it renders outside the dialog
  - the user is already in. Every caller had the same shape — a boolean guard
  - in front of a destructive continuation — so they share one dialog rather
  - than each growing an inline NcDialog (which ADR-004 / gate-13 forbids).
  -
  - Contract: the parent owns the pending action. This component NEVER runs it;
  - it only emits `confirm`. That keeps the fail-safe direction intact — a
  - closed, cancelled or dismissed dialog emits nothing, so the destructive
  - path cannot run by default.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="name"
		:no-close="busy"
		@closing="$emit('update:open', false)">
		<div class="confirm-action">
			<p class="confirm-action__message">
				{{ message }}
			</p>
			<p v-if="detail" class="confirm-action__detail">
				{{ detail }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton
				:type="destructive ? 'error' : 'primary'"
				:disabled="busy"
				@click="$emit('confirm')">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ confirmLabel || t('openbuild', 'Confirm') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'ConfirmActionDialog',
	components: { NcDialog, NcButton, NcLoadingIcon },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Dialog title. */
		name: { type: String, default: '' },
		/** The question being asked. */
		message: { type: String, default: '' },
		/** Optional second line spelling out the consequence. */
		detail: { type: String, default: '' },
		/** Label for the confirming button; falls back to "Confirm". */
		confirmLabel: { type: String, default: '' },
		/** Render the confirming button in the error style. */
		destructive: { type: Boolean, default: false },
		/** Whether the confirmed action is in flight. */
		busy: { type: Boolean, default: false },
	},
	emits: ['update:open', 'confirm'],
}
</script>

<style scoped>
.confirm-action {
	padding: 16px 24px 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.confirm-action__message {
	margin: 0;
}

.confirm-action__detail {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
