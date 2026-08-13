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
		:name="t('openbuild', 'Edit your override')"
		@close="onClose">
		<div class="ob-user-delta-modal">
			<h2 class="ob-user-delta-modal__title">
				{{ t('openbuild', 'Edit your override') }}
			</h2>
			<p class="ob-user-delta-modal__hint">
				{{
					t(
						'openbuild',
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
				<NcButton type="tertiary" @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					{{
						saving
							? t('openbuild', 'Saving…')
							: t('openbuild', 'Save override')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import { CnJsonViewer } from '@conduction/nextcloud-vue'

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
		 */
		async save() {
			if (!this.appSlug) return
			let parsed
			try {
				parsed = this.draft.trim() === '' ? {} : JSON.parse(this.draft)
			} catch (e) {
				this.error = t('openbuild', 'The delta is not valid JSON.')
				return
			}
			if (
				parsed === null
				|| typeof parsed !== 'object'
				|| Array.isArray(parsed)
			) {
				this.error = t('openbuild', 'The delta must be a JSON object.')
				return
			}

			this.saving = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openbuild/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				await axios.put(url, parsed)
				this.$emit('saved')
				this.onClose()
			} catch (e) {
				const detail =
					e
					&& e.response
					&& e.response.data
					&& (e.response.data.detail || e.response.data.error)
				this.error = detail
					? `${t('openbuild', 'Could not save your override')}: ${detail}`
					: t('openbuild', 'Could not save your override')
			} finally {
				this.saving = false
			}
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
