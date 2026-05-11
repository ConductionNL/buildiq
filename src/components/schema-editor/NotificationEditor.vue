<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - NotificationEditor — v1 STUB (REQ-OBSD-005 — notifications slice
  - deferred to v1.1 per design Decision 7). The channel picker +
  - template picker + recipient relation-path picker land in v1.1
  - (tasks 8.3) once the notification template catalogue is declared
  - in OR. v1 surfaces a read-only view of any existing
  - `x-openregister-notifications` block + a "coming in v1.1" message.
  -->
<template>
	<section class="openbuilt-notification-editor">
		<header class="openbuilt-notification-editor__header">
			<h3>{{ t('openbuilt', 'Notifications') }}</h3>
		</header>
		<NcNoteCard type="info">
			{{ t('openbuilt', 'The notification editor ships in v1.1 (see design Decision 7). Existing notifications declared on this schema are shown read-only below.') }}
		</NcNoteCard>
		<pre v-if="notifications" class="openbuilt-notification-editor__readonly">{{ formatted }}</pre>
		<p v-else class="openbuilt-notification-editor__empty">
			{{ t('openbuilt', 'No notifications declared on this schema.') }}
		</p>
	</section>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'NotificationEditor',
	components: { NcNoteCard },
	props: {
		notifications: { type: [Object, Array], default: null },
	},
	computed: {
		formatted() {
			try {
				return JSON.stringify(this.notifications, null, 2)
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-notification-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-notification-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-notification-editor__readonly {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 13px;
	overflow: auto;
}

.openbuilt-notification-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
