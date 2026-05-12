<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - LogsPageEditor — v1.1 stub. Task 4.4 deferred.
  -->
<template>
	<StubPageEditor
		:title="t('openbuilt', 'Logs page')"
		:message="t('openbuilt', 'Structured logs editor (register / schema / source / columns) coming in v1.1. For now, edit the raw JSON below or use the Raw JSON tab.')"
		:config="config"
		@update:config="$emit('update:config', $event)" />
</template>

<script>
import StubPageEditor from './StubPageEditor.vue'

export default {
	name: 'LogsPageEditor',
	components: { StubPageEditor },
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['update:config'],
}
</script>
