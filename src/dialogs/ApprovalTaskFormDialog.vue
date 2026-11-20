<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - ApprovalTaskFormDialog — approves an OpenRegister approval task whose
  - position asks the approver for fields (logic-approval-task-form, design
  - D4, REQ-BQTF-003). The library's task form dialog renders the declared
  - fields from the task already read, and completes with `outcome:
  - approved` and the values as `data`; a 400 naming fields keeps it open
  - with the typed values. Buildiq validates nothing itself: OpenRegister's
  - allowlist and the subject schema do.
  -->
<template>
	<CnTaskFormDialog
		:task="task"
		:taskUuid="task ? String(task.uuid || '') : ''"
		outcome="approved"
		:dialogTitle="dialogTitle"
		data-testid="approval-task-form-dialog"
		@completed="$emit('completed', $event)"
		@close="$emit('close')" />
</template>

<script>
import { CnTaskFormDialog } from '@conduction/nextcloud-vue'

export default {
	name: 'ApprovalTaskFormDialog',
	components: { CnTaskFormDialog },

	props: {
		/**
		 * The answer of `GET /api/flow-tasks/{uuid}` for the task being approved.
		 */
		task: { type: Object, default: null },
		/**
		 * The dialog title; the library falls back to the task title.
		 */
		dialogTitle: { type: String, default: '' },
	},

	emits: ['completed', 'close'],
}
</script>
