<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ShareTokenDialog — standalone dialog (modal-isolation rule) for
  - public-forms-runtime token management: list / create / revoke / copy-link.
  - Reachable from the PageDesigner toolbar (`page-id` set — scoped to the
  - open page, page picker hidden) and from AppSettingsModal (`page-id`
  - absent — a page picker offers every public-enabled page on the app).
  - Self-contained: owns its own axios calls against
  - `/api/applications/{slug}/share-tokens` (mirrors ScheduleEditDialog's
  - self-contained-fetch pattern), so parents only need to toggle `open`.
  -->
<template>
	<NcModal v-if="open" :name="t('openbuild', 'Share links')" @close="onClose">
		<div class="share-token-dialog">
			<h2 class="share-token-dialog__title">
				{{ t('openbuild', 'Share links') }}
			</h2>
			<p class="share-token-dialog__hint">
				{{ t('openbuild', 'Anyone with a share link can access this page without logging in. Revoke a link at any time to stop it working immediately.') }}
			</p>

			<section class="share-token-dialog__create">
				<h3 class="share-token-dialog__subtitle">
					{{ t('openbuild', 'Create a new link') }}
				</h3>

				<NcSelect
					v-if="!pageId"
					v-model="selectedPage"
					:input-label="t('openbuild', 'Page')"
					:options="publicEnabledPages"
					:clearable="false"
					:placeholder="t('openbuild', 'Select a public-enabled page')"
					label="label" />
				<p v-if="!pageId && publicEnabledPages.length === 0" class="share-token-dialog__warning">
					{{ t('openbuild', 'No page is marked public yet. Enable "Public access" in a form page\'s config first.') }}
				</p>

				<NcSelect
					v-model="modeOption"
					:input-label="t('openbuild', 'Mode')"
					:options="modeOptions"
					:clearable="false"
					label="label" />

				<NcTextField
					v-if="modeOption && modeOption.id === 'edit'"
					:value="boundObjectId"
					:label="t('openbuild', 'Record UUID to edit')"
					:placeholder="t('openbuild', 'The object this link edits')"
					@update:value="boundObjectId = $event" />

				<NcTextField
					:value="expiresAt"
					type="datetime-local"
					:label="t('openbuild', 'Expires at (optional, required for edit links)')"
					@update:value="expiresAt = $event" />

				<NcTextField
					:value="password"
					type="password"
					:label="t('openbuild', 'Password (optional)')"
					@update:value="password = $event" />

				<NcTextField
					:value="allowedPrefillFieldsInput"
					:label="t('openbuild', 'Allowed prefill fields (comma-separated, optional)')"
					@update:value="allowedPrefillFieldsInput = $event" />

				<NcCheckboxRadioSwitch
					:checked="requireEmailVerification"
					type="switch"
					@update:checked="requireEmailVerification = $event">
					{{ t('openbuild', 'Flag submissions as unverified until confirmed') }}
				</NcCheckboxRadioSwitch>

				<p v-if="createError" class="share-token-dialog__error" role="alert">
					{{ createError }}
				</p>

				<NcButton type="primary" :disabled="!canCreate || creating" @click="onCreate">
					{{ creating ? t('openbuild', 'Creating…') : t('openbuild', 'Create link') }}
				</NcButton>

				<div v-if="lastCreatedUrl" class="share-token-dialog__created">
					<code class="share-token-dialog__url">{{ lastCreatedUrl }}</code>
					<NcButton @click="copyToClipboard(lastCreatedUrl)">
						{{ t('openbuild', 'Copy link') }}
					</NcButton>
				</div>
			</section>

			<section class="share-token-dialog__list">
				<h3 class="share-token-dialog__subtitle">
					{{ t('openbuild', 'Existing links') }}
				</h3>
				<p v-if="loading">
					{{ t('openbuild', 'Loading…') }}
				</p>
				<p v-else-if="tokens.length === 0" class="share-token-dialog__hint">
					{{ t('openbuild', 'No share links yet.') }}
				</p>
				<ul v-else class="share-token-dialog__rows">
					<li v-for="row in tokens" :key="row.id || row.uuid" class="share-token-dialog__row">
						<div class="share-token-dialog__row-main">
							<strong>{{ row.pageId }}</strong>
							<span class="share-token-dialog__badge">{{ row.mode }}</span>
							<span v-if="row.revoked" class="share-token-dialog__badge share-token-dialog__badge--revoked">
								{{ t('openbuild', 'revoked') }}
							</span>
							<span v-if="row.expiresAt" class="share-token-dialog__meta">
								{{ t('openbuild', 'Expires: {date}', { date: row.expiresAt }) }}
							</span>
						</div>
						<div class="share-token-dialog__row-actions">
							<NcButton v-if="row.token" @click="copyToClipboard(publicUrlFor(row.token))">
								{{ t('openbuild', 'Copy link') }}
							</NcButton>
							<NcButton
								v-if="!row.revoked"
								type="tertiary"
								:disabled="revokingId === (row.id || row.uuid)"
								@click="onRevoke(row)">
								{{ t('openbuild', 'Revoke') }}
							</NcButton>
						</div>
					</li>
				</ul>
			</section>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcSelect, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'ShareTokenDialog',
	components: { NcModal, NcButton, NcSelect, NcTextField, NcCheckboxRadioSwitch },
	props: {
		/** Whether the dialog is shown. */
		open: { type: Boolean, default: false },
		/** The owning Application's slug. */
		appSlug: { type: String, required: true },
		/**
		 * When set (page-designer toolbar), scopes list+create to this one
		 * page and hides the page picker. When absent (AppSettingsModal),
		 * every public-enabled page is selectable.
		 */
		pageId: { type: String, default: '' },
		/**
		 * Optional pre-fetched manifest `pages[]` array (the page designer
		 * already has it in memory). When empty, the dialog self-fetches the
		 * production manifest on open — kept self-contained (like
		 * ScheduleEditDialog's own axios calls) so AppSettingsModal-adjacent
		 * callers don't need to plumb the manifest through first.
		 */
		pages: { type: Array, default: () => [] },
	},
	emits: ['update:open'],
	data() {
		return {
			tokens: [],
			loading: false,
			creating: false,
			revokingId: null,
			createError: '',
			selectedPage: null,
			modeOption: null,
			boundObjectId: '',
			expiresAt: '',
			password: '',
			allowedPrefillFieldsInput: '',
			requireEmailVerification: false,
			lastCreatedUrl: '',
			fetchedPages: [],
		}
	},
	computed: {
		/**
		 * Pages whose `config.public.enabled` is `true` — the only pages a
		 * new link may target (mirrors the server-side gate in
		 * `ShareTokenService::issue()`). Prefers the `pages` prop when given,
		 * else the dialog's own self-fetched manifest pages.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-public-page-can-only-be-issued-a-token-when-its-config-declares-publicenabled
		 */
		publicEnabledPages() {
			const source = (this.pages && this.pages.length > 0) ? this.pages : this.fetchedPages
			return (source || [])
				.filter((p) => p && p.config && p.config.public && p.config.public.enabled === true)
				.map((p) => ({ id: p.id, label: p.title || p.id }))
		},
		modeOptions() {
			return [
				{ id: 'submit', label: t('openbuild', 'submit — anonymous create form') },
				{ id: 'edit', label: t('openbuild', 'edit — per-record edit link') },
				{ id: 'read', label: t('openbuild', 'read — public read-only page') },
			]
		},
		/**
		 * The page id a new link targets — the `page-id` prop when scoped,
		 * otherwise the picker's current selection.
		 *
		 * @return {string}
		 */
		effectivePageId() {
			if (this.pageId) {
				return this.pageId
			}
			return this.selectedPage ? this.selectedPage.id : ''
		},
		canCreate() {
			if (this.effectivePageId === '') {
				return false
			}
			if (this.modeOption && this.modeOption.id === 'edit' && this.boundObjectId.trim() === '') {
				return false
			}
			return true
		},
	},
	watch: {
		open(isOpen) {
			if (isOpen) {
				this.resetCreateForm()
				this.fetchTokens()
				if (!this.pageId && (!this.pages || this.pages.length === 0)) {
					this.fetchManifestPages()
				}
			}
		},
	},
	methods: {
		/**
		 * Self-fetch the production manifest's `pages[]` when the caller did
		 * not pass one in (AppSettingsModal-adjacent usage) — populates the
		 * page picker for the whole-app scope.
		 *
		 * @return {Promise<void>}
		 */
		async fetchManifestPages() {
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/${this.appSlug}/manifest`)
				const { data } = await axios.get(url)
				this.fetchedPages = Array.isArray(data && data.pages) ? data.pages : []
			} catch (e) {
				this.fetchedPages = []
			}
		},
		/**
		 * Build the public URL for a token value. Kept client-side (rather
		 * than trusting a server-built URL) so it works identically for a
		 * just-created token and an older one re-fetched from the list.
		 *
		 * @param {string} token The opaque token value.
		 * @return {string}
		 */
		publicUrlFor(token) {
			return window.location.origin + generateUrl(`/apps/openbuild/public/forms/${encodeURIComponent(token)}`)
		},
		resetCreateForm() {
			this.selectedPage = null
			this.modeOption = this.modeOptions[0]
			this.boundObjectId = ''
			this.expiresAt = ''
			this.password = ''
			this.allowedPrefillFieldsInput = ''
			this.requireEmailVerification = false
			this.createError = ''
			this.lastCreatedUrl = ''
		},
		/**
		 * Fetch the token list for this Application (optionally page-scoped).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-token-management-ui-in-the-page-designer-and-app-settings
		 */
		async fetchTokens() {
			this.loading = true
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/${this.appSlug}/share-tokens`)
				const params = this.pageId ? { pageId: this.pageId } : {}
				const { data } = await axios.get(url, { params })
				this.tokens = Array.isArray(data && data.tokens) ? data.tokens : []
			} catch (e) {
				this.tokens = []
				// eslint-disable-next-line no-console
				console.error('[openbuild] ShareTokenDialog: failed to load tokens', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Create a new share token from the form state.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#requirement-sharetoken-schema-scopes-one-token-to-one-application-and-page
		 */
		async onCreate() {
			this.creating = true
			this.createError = ''
			this.lastCreatedUrl = ''
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/${this.appSlug}/share-tokens`)
				const allowedPrefillFields = this.allowedPrefillFieldsInput
					.split(',')
					.map((f) => f.trim())
					.filter((f) => f !== '')
				const { data } = await axios.post(url, {
					pageId: this.effectivePageId,
					mode: this.modeOption ? this.modeOption.id : 'submit',
					boundObjectId: this.boundObjectId.trim() || null,
					expiresAt: this.expiresAt ? new Date(this.expiresAt).toISOString() : null,
					password: this.password || null,
					allowedPrefillFields,
					requireEmailVerification: this.requireEmailVerification,
				})
				if (data && data.token) {
					this.lastCreatedUrl = this.publicUrlFor(data.token)
				}
				showSuccess(t('openbuild', 'Share link created'))
				await this.fetchTokens()
			} catch (e) {
				this.createError = (e.response && e.response.data && e.response.data.message)
					|| t('openbuild', 'Failed to create share link')
			} finally {
				this.creating = false
			}
		},
		/**
		 * Revoke a token — takes effect immediately for subsequent resolves.
		 *
		 * @param {object} row The token row from the list.
		 * @return {Promise<void>}
		 * @spec openspec/changes/public-forms-runtime/specs/public-form-access/spec.md#scenario-revoked-token-stops-resolving
		 */
		async onRevoke(row) {
			const id = row.id || row.uuid
			this.revokingId = id
			try {
				const url = generateUrl(`/apps/openbuild/api/applications/${this.appSlug}/share-tokens/${id}`)
				await axios.delete(url)
				showSuccess(t('openbuild', 'Share link revoked'))
				await this.fetchTokens()
			} catch (e) {
				showError(t('openbuild', 'Failed to revoke share link'))
			} finally {
				this.revokingId = null
			}
		},
		/**
		 * Copy a link to the clipboard, with a toast confirmation.
		 *
		 * @param {string} url The URL to copy.
		 * @return {Promise<void>}
		 */
		async copyToClipboard(url) {
			try {
				await navigator.clipboard.writeText(url)
				showSuccess(t('openbuild', 'Link copied to clipboard'))
			} catch (e) {
				showError(t('openbuild', 'Could not copy link — copy it manually'))
			}
		},
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.share-token-dialog {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
	min-width: 420px;
	max-width: 560px;
}

.share-token-dialog__title {
	margin: 0;
}

.share-token-dialog__subtitle {
	margin: 0 0 8px;
	font-size: 15px;
	font-weight: 600;
}

.share-token-dialog__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9em;
}

.share-token-dialog__warning {
	color: var(--color-warning-text, var(--color-warning));
	margin: 0;
	font-size: 0.85em;
}

.share-token-dialog__create,
.share-token-dialog__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.share-token-dialog__error {
	color: var(--color-error);
	margin: 0;
}

.share-token-dialog__created {
	display: flex;
	align-items: center;
	gap: 8px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	padding: 8px;
}

.share-token-dialog__url {
	overflow-wrap: anywhere;
	flex: 1;
}

.share-token-dialog__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.share-token-dialog__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.share-token-dialog__row-main {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
}

.share-token-dialog__row-actions {
	display: flex;
	gap: 6px;
}

.share-token-dialog__badge {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: 10px;
	background: var(--color-background-dark);
}

.share-token-dialog__badge--revoked {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.share-token-dialog__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
