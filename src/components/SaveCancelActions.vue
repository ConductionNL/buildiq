<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SaveCancelActions — the Cancel + Save footer of an editor dialog: Cancel
  - emits `cancel`, Save emits `save` and is disabled with a "Saving…" label
  - while `saving`. Rendered into the dialog's `#actions` slot.
  -->
<template>
	<NcButton @click="$emit('cancel')">
		{{ t('buildiq', 'Cancel') }}
	</NcButton>
	<NcButton variant="primary" :disabled="saving" @click="$emit('save')">
		{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'SaveCancelActions',
	components: { NcButton },
	props: {
		// Whether the dialog is saving.
		saving: { type: Boolean, default: false },
	},

	emits: ['cancel', 'save'],
}
</script>
