<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationIconTab — icon upload/preview section, mounted as the "Icons"
  - sidebar tab on the VirtualAppDetail (`type: detail`) page.
  -
  - Delegates all upload/remove logic to IconUploadSection (ADR-004 modal
  - isolation: section component owns file I/O, tab owns the context supply).
  - REQ-OBICON-004 / openbuilt-nextcloud-nav.
  -->
<template>
	<div class="ob-icon-tab">
		<NcNoteCard v-if="!obApp" type="info">
			{{ t('openbuilt', 'Loading application…') }}
		</NcNoteCard>
		<IconUploadSection
			v-else
			:application="obApp"
			@updated="onIconUpdated" />
	</div>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import applicationContext from '../../mixins/applicationContext.js'
import IconUploadSection from '../../dialogs/IconUploadSection.vue'

export default {
	name: 'ApplicationIconTab',

	components: { NcNoteCard, IconUploadSection },

	mixins: [applicationContext],

	methods: {
		onIconUpdated(payload) {
			// Bubble up so the detail page can refresh the Application record.
			this.$emit('updated', payload)
		},
	},
}
</script>

<style scoped>
.ob-icon-tab {
	padding: 8px 0;
}
</style>
