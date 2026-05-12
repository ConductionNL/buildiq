<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ChatPageEditor — v1.1 stub. Task 4.6 deferred.
  -->
<template>
	<StubPageEditor
		:title="t('openbuilt', 'Chat page')"
		:message="t('openbuilt', 'Structured chat editor (conversationSource OR postUrl one-of plus optional schema) coming in v1.1. For now, edit the raw JSON below or use the Raw JSON tab.')"
		:config="config"
		@update:config="$emit('update:config', $event)" />
</template>

<script>
import StubPageEditor from './StubPageEditor.vue'

export default {
	name: 'ChatPageEditor',
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
