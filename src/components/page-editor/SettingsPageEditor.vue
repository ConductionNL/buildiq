<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SettingsPageEditor — v1.1 stub. Task 4.5 deferred.
  -->
<template>
	<StubPageEditor
		:title="t('openbuilt', 'Settings page')"
		:message="t('openbuilt', 'Structured settings editor (section list with fields / component / widgets exactly-one-of) coming in v1.1. For now, edit the raw JSON below or use the Raw JSON tab.')"
		:config="config"
		@update:config="$emit('update:config', $event)" />
</template>

<script>
import StubPageEditor from './StubPageEditor.vue'

export default {
	name: 'SettingsPageEditor',
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
