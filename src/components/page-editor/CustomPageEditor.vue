<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CustomPageEditor — v1.1 stub. Task 4.9 deferred.
  -->
<template>
	<StubPageEditor
		:title="t('openbuilt', 'Custom page')"
		:message="t('openbuilt', 'Structured custom-page editor (customComponents registry picker + free-form config) coming in v1.1. For now, edit the raw JSON below or use the Raw JSON tab.')"
		:config="config"
		@update:config="$emit('update:config', $event)" />
</template>

<script>
import StubPageEditor from './StubPageEditor.vue'

export default {
	name: 'CustomPageEditor',
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
