<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FilesPageEditor — v1.1 stub. Task 4.7 deferred.
  -->
<template>
	<StubPageEditor
		:title="t('openbuilt', 'Files page')"
		:message="t('openbuilt', 'Structured files editor (folder picker + allowed-types selector) coming in v1.1. For now, edit the raw JSON below or use the Raw JSON tab.')"
		:config="config"
		@update:config="$emit('update:config', $event)" />
</template>

<script>
import StubPageEditor from './StubPageEditor.vue'

export default {
	name: 'FilesPageEditor',
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
