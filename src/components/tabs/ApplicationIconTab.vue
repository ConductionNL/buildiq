<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationIconTab — icon upload/preview section, mounted as the "Icons"
  - sidebar tab on the VirtualAppDetail (`type: detail`) page.
  -
  - Delegates all upload/remove logic to IconUploadSection (ADR-004 modal
  - isolation: section component owns file I/O, tab owns the context supply).
  - REQ-OBICON-004 / buildiq-nextcloud-nav.
  -->
<template>
	<div class="ob-icon-tab">
		<NcNoteCard v-if="!obApp" type="info">
			{{ t('buildiq', 'Loading application…') }}
		</NcNoteCard>
		<IconUploadSection v-else :application="obApp" @updated="onIconUpdated" />
	</div>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'
import IconUploadSection from '../../dialogs/IconUploadSection.vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ApplicationIconTab',

	components: { NcNoteCard, IconUploadSection },

	mixins: [applicationContext],

	methods: {
		/**
		 * Observed behaviour of `onIconUpdated` (retrofit annotation).
		 *
		 * @param {{field: 'icon'|'iconDark', ref: string|null}} payload - What
		 *   IconUploadSection just wrote on the Application: which icon field changed,
		 *   and the attached filename (`null` when the icon was removed). Re-emitted
		 *   unchanged so the detail page can refresh the record.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
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
