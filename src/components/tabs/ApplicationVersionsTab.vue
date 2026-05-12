<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationVersionsTab — wraps VersionHistory as the "Version history"
  - sidebar tab on the VirtualAppDetail page. Resolves the Application from
  - the shared tab props (mixin), feeds its uuid + currentVersion to
  - VersionHistory, and handles rollback (a PUT that copies a snapshot's
  - manifest back onto the Application, leaving status=draft — REQ-OBV-003).
  -->
<template>
	<div class="ob-versions-tab">
		<p v-if="obAppError" class="ob-versions-tab__error">
			{{ obAppError }}
		</p>
		<VersionHistory
			v-if="obAppUuid"
			:application-uuid="obAppUuid"
			:current-version-uuid="(obApp && obApp.currentVersion) || ''"
			@rollback="onRollback" />
		<p v-if="rollbackError" class="ob-versions-tab__error">
			{{ rollbackError }}
		</p>
	</div>
</template>

<script>
import VersionHistory from '../../views/VersionHistory.vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ApplicationVersionsTab',
	components: { VersionHistory },
	mixins: [applicationContext],
	data() {
		return { rollbackError: '' }
	},
	methods: {
		shortHex() {
			const bytes = new Uint8Array(3)
			if (globalThis.crypto && globalThis.crypto.getRandomValues) {
				globalThis.crypto.getRandomValues(bytes)
			} else {
				for (let i = 0; i < bytes.length; i++) {
					bytes[i] = Math.floor(Math.random() * 256)
				}
			}
			return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('')
		},
		async onRollback(version) {
			if (!version || !version.manifest || !this.obApp) {
				return
			}
			this.rollbackError = ''
			try {
				await this.obPatchApp({
					manifest: version.manifest,
					version: `${version.version}-rollback-${this.shortHex()}`,
					status: 'draft',
				})
			} catch (e) {
				this.rollbackError = `${t('openbuilt', 'Rollback failed')}: ${e.message || e}`
			}
		},
	},
}
</script>

<style scoped>
.ob-versions-tab {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.ob-versions-tab__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}
</style>
