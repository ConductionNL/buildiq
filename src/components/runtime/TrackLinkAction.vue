<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - TrackLinkAction — registrable Detail-page `actionsComponent`
  - (`config.actionsComponent: "TrackLinkAction"`, ADR-036 kind "actions")
  - that lets a staff member mint a "track your case" link for the object
  - they are currently viewing (REQ-EFP-006).
  -
  - Owner-context ONLY: mounted inside a running (built) app's Detail page,
  - viewed by an authenticated staff member with access to the object —
  - never rendered for an anonymous visitor (Portaliq renders the anonymous
  - submission surface; OpenBuild never does). Gated on the CURRENT app's
  - `runtime.externalForms[]` entry for this object's (register, schema)
  - carrying `trackLinkAction.enabled: true` — read via the `cnManifest`
  - injection CnAppRoot provides to every descendant (the live, reactive
  - manifest; see `provide()` in CnAppRoot.vue), so this renders nothing for
  - schemas that never opted in and nothing while editing removes the flag.
  -->
<template>
	<div v-if="eligible" class="ob-track-link-action">
		<NcButton :disabled="minting" @click="mint">
			{{ minting ? t('openbuild', 'Minting…') : t('openbuild', 'Mint track-link') }}
		</NcButton>
		<div v-if="link" class="ob-track-link-action__result">
			<code>{{ link }}</code>
			<NcButton type="tertiary" @click="copy">
				{{ t('openbuild', 'Copy') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useTrackLinkAction } from '../../composables/useTrackLinkAction.js'
import { objectSchemaKeys, objectRegisterKeys, matchesKey } from '../../utils/objectSchemaKeys.js'

export default {
	name: 'TrackLinkAction',
	components: { NcButton },
	// Bound from CnDetailPage's `actions` scoped slot (see
	// `resolvedSlotEntries` in CnPageRenderer.vue): `object`, `objectId`,
	// `schema`, `objectType`, `store`. Only `object`/`objectId` are used here
	// — register/schema are read off the object's `@self` envelope, the same
	// convention `useProcestCase.js::writeBack()` already relies on.
	inject: {
		cnManifest: { default: null },
		// The page's own object context. Its `register`/`schema` are the
		// manifest's slugs; `@self` carries numeric ids. See the schemaKeys
		// computed below.
		cnObjectContext: { default: null },
	},
	props: {
		object: {
			type: Object,
			default: null,
		},
		objectId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			minting: false,
			link: '',
		}
	},
	computed: {
		/**
		 * The object's OR register slug, read off the `@self` envelope.
		 *
		 * @return {string}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		register() {
			return this.registerKeys[0] || ''
		},
		/**
		 * Every name this object's register can legitimately be known by.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		registerKeys() {
			return objectRegisterKeys(this.object, this.cnObjectContext)
		},
		/**
		 * The object's OR schema slug, read off the `@self` envelope.
		 *
		 * @return {string}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		schema() {
			return this.schemaKeys[0] || ''
		},
		/**
		 * Every name this object's schema can legitimately be known by.
		 *
		 * `@self.register`/`@self.schema` are NUMERIC ids (measured:
		 * `{"register":"15","schema":"21"}`) while `runtime.externalForms[]`
		 * carries slugs, so the old single-field reads could never match an
		 * entry and this action never rendered. See src/utils/objectSchemaKeys.js.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		schemaKeys() {
			return objectSchemaKeys(this.object, this.cnObjectContext)
		},
		/**
		 * The resolved object id — prefer the explicit prop (CnDetailPage
		 * always resolves one for a mounted detail view), fall back to the
		 * object's own envelope.
		 *
		 * @return {string}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		resolvedObjectId() {
			return this.objectId || (this.object && this.object['@self'] && this.object['@self'].id) || ''
		},
		/**
		 * The manifest entry (if any) `runtime.externalForms[]` carries for
		 * this object's `(register, schema)`.
		 *
		 * @return {?object}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		externalFormEntry() {
			const list = this.cnManifest && this.cnManifest.runtime && this.cnManifest.runtime.externalForms
			const registerKeys = this.registerKeys
			const schemaKeys = this.schemaKeys
			if (!Array.isArray(list) || registerKeys.length === 0 || schemaKeys.length === 0) {
				return null
			}
			return list.find((e) => e
				&& matchesKey(e.register, registerKeys)
				&& matchesKey(e.schema, schemaKeys)) || null
		},
		/**
		 * REQ-EFP-006: only offered when the schema's external-form entry has
		 * `trackLinkAction.enabled: true` — never rendered otherwise.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		eligible() {
			const entry = this.externalFormEntry
			return !!(entry && entry.trackLinkAction && entry.trackLinkAction.enabled && this.resolvedObjectId)
		},
	},
	methods: {
		/**
		 * Mint the track-link for the currently viewed object.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		async mint() {
			if (this.minting) {
				return
			}
			this.minting = true
			try {
				const { mintTrackLink } = useTrackLinkAction()
				const result = await mintTrackLink(this.register, this.schema, this.resolvedObjectId)
				this.link = result.url
				showSuccess(t('openbuild', 'Track-link minted.'))
			} catch (e) {
				showError(t('openbuild', 'Could not mint a track-link: {error}', { error: (e && e.message) || String(e) }))
			} finally {
				this.minting = false
			}
		},
		/**
		 * Copy the minted link to the clipboard.
		 *
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-006
		 */
		copy() {
			if (!this.link) {
				return
			}
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(this.link)
				showSuccess(t('openbuild', 'Link copied.'))
			}
		},
	},
}
</script>

<style scoped>
.ob-track-link-action {
	display: flex;
	flex-direction: column;
	gap: 6px;
	align-items: flex-start;
}

.ob-track-link-action__result {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ob-track-link-action__result code {
	word-break: break-all;
	font-size: 0.85em;
}
</style>
