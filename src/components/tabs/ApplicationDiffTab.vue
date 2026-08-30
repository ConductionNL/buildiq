<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDiffTab — wraps ManifestDiff as the "Diff" sidebar tab on the
  - VirtualAppDetail page. Defaults to comparing the current draft against the
  - latest published snapshot (the most common comparison); finer-grained pairs
  - are reachable from the Version history tab's compare action in a future
  - iteration.
  -->
<template>
	<div class="ob-diff-tab">
		<p v-if="obAppError" class="ob-diff-tab__error">
			{{ obAppError }}
		</p>
		<ManifestDiff
			v-else-if="obApp && obApp.slug"
			:slug="obApp.slug"
			from="draft"
			:to="(obApp && obApp.currentVersion) || ''" />
	</div>
</template>

<script>
import ManifestDiff from '../ManifestDiff.vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ApplicationDiffTab',
	components: { ManifestDiff },
	mixins: [applicationContext],
}
</script>

<style scoped>
.ob-diff-tab {
	padding: 8px 0;
}

.ob-diff-tab__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}
</style>
