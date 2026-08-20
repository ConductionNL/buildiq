<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationVersionsTab — wraps VersionHistory as the "Version history"
  - sidebar tab on the VirtualAppDetail page. Resolves the Application from
  - the shared tab props (mixin), feeds its uuid + currentVersion to
  - VersionHistory, and handles rollback (a PUT that restores a snapshot's
  - manifest onto the ACTIVE VERSION, leaving status=draft — REQ-OBV-003).
  -->
<template>
	<div class="ob-versions-tab">
		<p v-if="obAppError" class="ob-versions-tab__error">
			{{ obAppError }}
		</p>
		<VersionHistory
			v-if="obAppUuid"
			:appSlug="(obApp && obApp.slug) || ''"
			:applicationUuid="obAppUuid"
			:currentVersionUuid="
				(obApp && (obApp.productionVersion || obApp.currentVersion)) || ''
			"
			:canEdit="canEdit"
			:canRelease="canRelease"
			@rollback="onRollback"
			@released="onReleased" />
		<p v-if="rollbackError" class="ob-versions-tab__error">
			{{ rollbackError }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import VersionHistory from '../../views/VersionHistory.vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ApplicationVersionsTab',
	components: { VersionHistory },
	mixins: [applicationContext],
	data() {
		return { rollbackError: '' }
	},

	computed: {
		/**
		 * Whether the caller may edit versions (owner / editor role).
		 *
		 * @return {boolean}
		 */
		canEdit() {
			return this.obAppRole === 'owner' || this.obAppRole === 'editor'
		},

		/**
		 * Whether the caller may release a draft to production (owner only).
		 *
		 * @return {boolean}
		 */
		canRelease() {
			return this.obAppRole === 'owner'
		},
	},

	methods: {
		/**
		 * Refresh the application after a release so the production marker moves.
		 *
		 * @return {void}
		 */
		onReleased() {
			this.obLoadApp()
		},

		/**
		 * Restore a snapshot's manifest onto the application's ACTIVE VERSION.
		 *
		 * @param {{version: string, manifest: object, applicationUuid?: string}} version - The
		 *   ApplicationVersion snapshot the user confirmed rolling back to,
		 *   forwarded by VersionHistory. Its `manifest` is PUT to
		 *   `/api/applications/{slug}/manifest` and the Application is left at
		 *   `status: 'draft'`, so the rollback never silently republishes. A
		 *   snapshot without a stored manifest is ignored.
		 *
		 *   Formerly documented as writing the manifest onto the Application
		 *   itself under a `<version>-rollback-<hex>` label. Neither field exists
		 *   on the `application` schema, so both were silently dropped and the
		 *   rollback restored nothing — see the writeup in the method body.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		async onRollback(version) {
			if (!version || !version.manifest || !this.obApp) {
				return
			}
			this.rollbackError = ''
			try {
				// PUT the ACTIVE VERSION's manifest — the same route
				// ApplicationManifestTab uses, and for the same reason.
				//
				// This previously went through `obPatchApp({ manifest, version,
				// status })`, which PUTs the whole Application object at
				// `/api/objects/openbuild/application/{uuid}`. The `application`
				// schema declares 15 properties and NEITHER `manifest` NOR
				// `version` is one of them, so OpenRegister dropped both and only
				// `status` survived. Rolling back therefore restored NOTHING — it
				// just flipped the app to draft. Measured on a live instance:
				//
				//   PUT {description: 'CONTROL_MARKER_XYZ789',  // known prop
				//        version: 'PROBE-rollback-deadbeef',    // unknown
				//        manifest: {probeMarker: 'ROLLBACK_PROBE'}}
				//   -> description = 'CONTROL_MARKER_XYZ789'  (PUT landed)
				//   -> version     = None                     (dropped)
				//   -> manifest    = None                     (dropped)
				//
				// Note the endpoint's asymmetry: GET returns the manifest bare,
				// PUT expects it wrapped in `{ manifest }`.
				await axios.put(
					generateUrl(
						`/apps/openbuild/api/applications/${encodeURIComponent(this.obApp.slug)}/manifest`,
					),
					{ manifest: version.manifest },
				)
				// `status` IS a real property, so this one always worked.
				//
				// The old `<version>-rollback-<hex>` label is deliberately NOT
				// reinstated: there is no `version` property on the Application to
				// hold it, and inventing a home for it would be a schema change,
				// not a bug fix. The version chain already records what exists;
				// what was broken is that the manifest never came back.
				await this.obPatchApp({ status: 'draft' })
			} catch (e) {
				this.rollbackError = `${t('openbuild', 'Rollback failed')}: ${e.message || e}`
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
