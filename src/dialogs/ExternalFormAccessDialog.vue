<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ExternalFormAccessDialog — standalone dialog (modal-isolation rule) that
  - provisions/revokes "external access" for a Form page's OR target
  - (REQ-EFP-002). Lets the builder enable/disable public create, optionally
  - enable public read, optionally scope to an organisation, optionally
  - expose the "mint track-link" action later, and see the resulting public
  - URLs before confirming.
  -
  - All provisioning happens HERE, via `externalFormProvisioningService.js`,
  - riding the builder's own NC session — OpenBuild hosts no PHP proxy for
  - any of it (design.md Decision 3). On save this dialog emits the fully
  - resolved `runtime.externalForms[]` entry; the host (FormPageEditor.vue)
  - only persists it into the manifest.
  -->
<template>
	<NcDialog
		:open="open"
		:name="t('openbuild', 'External access')"
		size="normal"
		@update:open="onDialogOpenChange"
		@closing="onClose">
		<div class="ob-external-form-access">
			<p class="ob-external-form-access__target">
				{{ t('openbuild', 'Target: {register} / {schema}', { register, schema }) }}
			</p>

			<label class="ob-external-form-access__toggle">
				<input
					:checked="enabled"
					type="checkbox"
					@change="enabled = $event.target.checked">
				{{ t('openbuild', 'Allow anonymous submissions to this endpoint') }}
			</label>

			<template v-if="enabled">
				<label class="ob-external-form-access__toggle">
					<input
						:checked="publicRead"
						type="checkbox"
						@change="publicRead = $event.target.checked">
					{{ t('openbuild', 'Also allow anonymous reads (public listing)') }}
				</label>

				<NcTextField
					:value="organisationScope || ''"
					:label="t('openbuild', 'Organisation scope (optional)')"
					:placeholder="t('openbuild', 'Organisation id — leave empty for none')"
					@update:value="organisationScope = $event || null" />

				<label class="ob-external-form-access__toggle">
					<input
						:checked="trackLinkEnabled"
						type="checkbox"
						@change="trackLinkEnabled = $event.target.checked">
					{{ t('openbuild', 'Offer a "mint track-link" action on submitted objects') }}
				</label>
			</template>

			<NcNoteCard v-if="portalHint" type="warning">
				{{ t('openbuild', 'Portaliq rendering not available on this instance yet — the raw public-create URL below still works.') }}
			</NcNoteCard>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div v-if="showUrls" class="ob-external-form-access__urls">
				<p>
					<strong>{{ t('openbuild', 'Raw public submit URL') }}</strong><br>
					<code>{{ rawSubmitUrl }}</code>
				</p>
				<p v-if="portalUrl">
					<strong>{{ t('openbuild', 'Portaliq page') }}</strong><br>
					<code>{{ portalUrl }}</code>
				</p>
			</div>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Close') }}
			</NcButton>
			<NcButton
				v-if="hadEnabledEntry"
				type="tertiary"
				:disabled="saving"
				@click="onDisable">
				{{ t('openbuild', 'Disable') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="saving"
				@click="onSave">
				{{ saving ? t('openbuild', 'Saving…') : t('openbuild', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import {
	enablePublicCreate,
	revokePublicCreate,
	provisionPortalPage,
	draftPortalPage,
} from '../services/externalFormProvisioningService.js'

/**
 * Generate an `ef-<uuid>` bookkeeping id (design.md Decision 1). Falls back
 * to a timestamp+random id when `crypto.randomUUID` is unavailable (older
 * browsers, some test/JSDOM environments).
 *
 * @return {string}
 */
function generateEntryId() {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return `ef-${crypto.randomUUID()}`
	}
	return `ef-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`
}

export default {
	name: 'ExternalFormAccessDialog',
	components: { NcDialog, NcButton, NcTextField, NcNoteCard },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		// The resolved OR target the page's submitEndpoint points at.
		register: {
			type: String,
			default: '',
		},
		schema: {
			type: String,
			default: '',
		},
		pageId: {
			type: String,
			default: '',
		},
		// The current `runtime.externalForms` entry for this page, or null.
		entry: {
			type: Object,
			default: null,
		},
	},
	emits: ['update:open', 'save'],
	data() {
		return {
			enabled: false,
			publicRead: false,
			organisationScope: null,
			trackLinkEnabled: false,
			saving: false,
			errorMessage: '',
			portalHint: false,
			saved: false,
			savedPortalUrl: '',
		}
	},
	computed: {
		/**
		 * Whether a previously-enabled entry exists to offer the Disable action.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-005
		 */
		hadEnabledEntry() {
			return !!(this.entry && this.entry.status === 'enabled')
		},
		/**
		 * The raw anonymous-create endpoint, shown so the builder can copy it
		 * into an external form/website (REQ-EFP-002).
		 *
		 * @return {string}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		rawSubmitUrl() {
			if (!this.register || !this.schema) {
				return ''
			}
			return window.location.origin + generateUrl(`/apps/openregister/api/objects/${this.register}/${this.schema}`)
		},
		/**
		 * The Portaliq portal URL, when provisioned.
		 *
		 * @return {string}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
		 */
		portalUrl() {
			if (this.savedPortalUrl) {
				return this.savedPortalUrl
			}
			if (this.entry && this.entry.portalPage && this.entry.portalPage.portalPath) {
				return window.location.origin + generateUrl(this.entry.portalPage.portalPath)
			}
			return ''
		},
		/**
		 * Show the URL panel once the toggle is enabled and either a save has
		 * completed or an entry already exists (reopen case).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		showUrls() {
			return this.enabled && (this.saved || this.hadEnabledEntry)
		},
	},
	watch: {
		/**
		 * Re-hydrate the form from `entry` each time the dialog (re)opens.
		 *
		 * @param {boolean} isOpen - the new `open` prop value.
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
			}
		},
	},
	methods: {
		/**
		 * Seed the form from the current entry when (re)opening.
		 *
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		hydrate() {
			this.errorMessage = ''
			this.portalHint = false
			this.saved = false
			this.savedPortalUrl = ''
			const e = this.entry
			this.enabled = !!(e && e.status === 'enabled')
			this.publicRead = !!(e && e.publicRead)
			this.organisationScope = (e && e.organisationScope) || null
			this.trackLinkEnabled = !!(e && e.trackLinkAction && e.trackLinkAction.enabled)
		},
		/**
		 * Persist the enable/update flow: read-merge-write the schema
		 * authorization, then provision (create/update) the Portaliq
		 * `portalPage`. Degrades gracefully — a Portaliq failure never blocks
		 * the OR leg, which has already completed by the time it runs
		 * (design.md Decision 5 / OQ-2).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-003
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-004
		 */
		async onSave() {
			if (!this.register || !this.schema || this.saving) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			this.portalHint = false
			try {
				if (!this.enabled) {
					await this.onDisable()
					return
				}
				await enablePublicCreate({ schema: this.schema, publicRead: this.publicRead })
				const existingObjectId = this.entry && this.entry.portalPage && this.entry.portalPage.objectId
				const portalResult = await provisionPortalPage({
					register: this.register,
					schema: this.schema,
					objectId: existingObjectId || null,
				})
				this.portalHint = !!portalResult.unavailable
				this.savedPortalUrl = portalResult.portalPath ? (window.location.origin + generateUrl(portalResult.portalPath)) : ''
				const next = {
					id: (this.entry && this.entry.id) || generateEntryId(),
					pageId: this.pageId,
					register: this.register,
					schema: this.schema,
					status: 'enabled',
					publicRead: this.publicRead,
					organisationScope: this.organisationScope || null,
					portalPage: portalResult.unavailable
						? null
						: { objectId: portalResult.objectId, portalPath: portalResult.portalPath },
					trackLinkAction: { enabled: this.trackLinkEnabled },
				}
				this.saved = true
				this.$emit('save', next)
			} catch (e) {
				this.errorMessage = t('openbuild', 'Could not provision external access: {error}', { error: (e && e.message) || String(e) })
			} finally {
				this.saving = false
			}
		},
		/**
		 * Revoke: reverse the schema-authorization merge and draft the linked
		 * `portalPage` (never delete it). No-ops the Portaliq leg when no
		 * `portalPage` was ever linked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-005
		 */
		async onDisable() {
			if (!this.register || !this.schema) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			try {
				const hadPublicRead = !!(this.entry && this.entry.publicRead)
				await revokePublicCreate({ schema: this.schema, removeRead: hadPublicRead })
				const objectId = this.entry && this.entry.portalPage && this.entry.portalPage.objectId
				if (objectId) {
					await draftPortalPage(objectId)
				}
				const next = {
					id: (this.entry && this.entry.id) || generateEntryId(),
					pageId: this.pageId,
					register: this.register,
					schema: this.schema,
					status: 'disabled',
					publicRead: false,
					organisationScope: (this.entry && this.entry.organisationScope) || null,
					portalPage: (this.entry && this.entry.portalPage) || null,
					trackLinkAction: { enabled: false },
				}
				this.enabled = false
				this.saved = false
				this.$emit('save', next)
			} catch (e) {
				this.errorMessage = t('openbuild', 'Could not disable external access: {error}', { error: (e && e.message) || String(e) })
			} finally {
				this.saving = false
			}
		},
		/**
		 * NcDialog's `update:open` fires on backdrop/esc close too — route it
		 * through the same close handler as the explicit Close button.
		 *
		 * @param {boolean} value - the new open state.
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		onDialogOpenChange(value) {
			if (!value) {
				this.onClose()
			}
		},
		/**
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-002
		 */
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-external-form-access {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.ob-external-form-access__target {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.ob-external-form-access__toggle {
	display: flex;
	gap: 8px;
	align-items: center;
}
.ob-external-form-access__urls {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}
.ob-external-form-access__urls code {
	word-break: break-all;
}
</style>
