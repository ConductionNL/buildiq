<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	UserDeltaEditModal — edits the calling user's OWN manifest delta
	(layered-versioned-app-deltas). The delta is a keyed structural diff layered
	over the admin delta (base ⊕ admin ⊕ user). Saving PUTs to the owner-scoped
	/api/app-overrides/{appId}/user endpoint (the owner is always the session
	user — no-admin-idor). Used for both first authoring (an empty delta) and
	subsequent edits.

	Modal lives in src/modals/ per ADR-004 gate-13 (no inline NcModal markup in
	page/widget components).
-->
<template>
	<NcModal
		v-if="open"
		size="large"
		:name="t('buildiq', 'Edit your override')"
		@close="onClose">
		<div class="ob-user-delta-modal">
			<h2 class="ob-user-delta-modal__title">
				{{ t('buildiq', 'Edit your override') }}
			</h2>
			<p class="ob-user-delta-modal__hint">
				{{
					t(
						'buildiq',
						'This personal delta is layered on top of the shared admin delta. Use the keyed delta format (pages by id, widgets by id, "$op":"remove" to delete).',
					)
				}}
			</p>

			<CnJsonViewer
				:value="draft"
				language="json"
				height="320px"
				@update:value="draft = $event" />

			<p v-if="error" class="ob-user-delta-modal__error">
				{{ error }}
			</p>

			<div class="ob-user-delta-modal__actions">
				<NcButton variant="tertiary" @click="onClose">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{
						saving
							? t('buildiq', 'Saving…')
							: t('buildiq', 'Save override')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { CnJsonViewer } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcModal from '@nextcloud/vue/components/NcModal'

/**
 * Parse a raw delta draft; a blank draft is an empty delta.
 *
 * @param {string} draft The editor text.
 * @return {object|Array|string|number|boolean|null} The parsed JSON value.
 */
function parseDelta(draft) {
	return draft.trim() === '' ? {} : JSON.parse(draft)
}

/**
 * Whether a parsed JSON value is an object (not null, not an array).
 *
 * @param {object|Array|string|number|boolean|null} value The parsed JSON value.
 * @return {boolean}
 */
function isJsonObject(value) {
	return value !== null && typeof value === 'object' && !Array.isArray(value)
}

/**
 * The server's detail or error message of a failed save, if any.
 *
 * @param {Error} e The rejected request.
 * @return {string|undefined}
 */
function saveFailureDetail(e) {
	return (
		e
		&& e.response
		&& e.response.data
		&& (e.response.data.detail || e.response.data.error)
	)
}

/**
 * The message shown when saving the delta fails.
 *
 * @param {Error} e The rejected request.
 * @return {string}
 */
function describeSaveFailure(e) {
	const detail = saveFailureDetail(e)
	return detail
		? `${t('buildiq', 'Could not save your override')}: ${detail}`
		: t('buildiq', 'Could not save your override')
}

export default {
	name: 'UserDeltaEditModal',
	components: { NcModal, NcButton, CnJsonViewer },
	props: {
		/** Whether the modal is open (`.sync`). */
		open: { type: Boolean, default: false },
		/** The app's kebab-case slug (= fleet appId for a hybrid app). */
		appSlug: { type: String, default: '' },
		/** The current user-delta object to seed the editor with. */
		delta: { type: Object, default: () => ({}) },
	},

	emits: ['update:open', 'saved'],
	data() {
		return {
			draft: '{}',
			saving: false,
			error: '',
		}
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.error = ''
				this.draft = JSON.stringify(this.delta || {}, null, 2)
			}
		},
	},

	methods: {
		/**
		 * Close the modal (sync the open prop back to the parent).
		 *
		 * @return {void}
		 */
		onClose() {
			this.$emit('update:open', false)
		},

		/**
		 * Validate the JSON, PUT the user delta, and emit `saved` on success.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async save() {
			if (!this.appSlug) return
			const parsed = this.parseDraft()
			if (parsed === undefined) return

			this.saving = true
			this.error = ''
			try {
				await this.putDelta(parsed)
			} catch (e) {
				this.error = describeSaveFailure(e)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Parse the draft into a delta object, or set the error and return
		 * `undefined` when it is not valid JSON or not a JSON object.
		 *
		 * @return {object|undefined}
		 */
		parseDraft() {
			let parsed
			try {
				parsed = parseDelta(this.draft)
			} catch (e) {
				this.error = t('buildiq', 'The delta is not valid JSON.')
				return undefined
			}
			if (!isJsonObject(parsed)) {
				this.error = t('buildiq', 'The delta must be a JSON object.')
				return undefined
			}
			return parsed
		},

		/**
		 * PUT the delta, then emit `saved` and close.
		 *
		 * @param {object} delta The parsed user delta.
		 * @return {Promise<void>}
		 */
		async putDelta(delta) {
			await axios.put(this.userDeltaUrl(), delta)
			this.$emit('saved')
			this.onClose()
		},

		/**
		 * The owner-scoped user-delta endpoint of this app.
		 *
		 * @return {string}
		 */
		userDeltaUrl() {
			return generateUrl('/apps/buildiq/api/app-overrides/{appId}/user', {
				appId: this.appSlug,
			})
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-user-delta-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.ob-user-delta-modal__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.ob-user-delta-modal__hint {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-user-delta-modal__error {
	margin: 0;
	font-size: 13px;
	color: var(--color-error, #d63f3f);
}

.ob-user-delta-modal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
