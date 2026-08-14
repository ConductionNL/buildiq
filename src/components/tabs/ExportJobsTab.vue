<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ExportJobsTab — wraps ExportJobsList as the "Exports" sidebar tab on the
  - VirtualAppDetail page. Resolves the Application slug from the shared tab
  - props (applicationContext mixin) and passes it to ExportJobsList.
  -
  - @spec openspec/changes/openbuild-exporter/tasks.md#task-9.2
  -->
<template>
	<div class="ob-exports-tab">
		<ExportJobsList
			v-if="obApp && obApp.slug"
			:applicationSlug="obApp.slug"
			:applicationUuid="obAppUuid" />
		<p v-else-if="obAppError" class="ob-exports-tab__error">
			{{ obAppError }}
		</p>
	</div>
</template>

<script>
import ExportJobsList from '../../views/ExportJobsList.vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ExportJobsTab',
	components: { ExportJobsList },
	mixins: [applicationContext],
}
</script>
