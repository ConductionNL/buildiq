<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - LinkRepoDialog — owner affordance to link (or re-link) an Application to a
  - GitHub repository. Collects owner + name (+ optional org) and POSTs to the
  - GitHub sync link endpoint. Kept in its own file per ADR-004
  - gate-modal-isolation. Opened from GitHubSyncModal.
  -->
<template>
	<NcModal v-if="open" size="small" @close="onClose">
		<div class="link-repo">
			<h2>{{ t('openbuild', 'Link a GitHub repository') }}</h2>
			<p class="link-repo__summary">
				{{
					t(
						'openbuild',
						'Connect this app to a GitHub repository so you can publish and pull versions.',
					)
				}}
			</p>
			<NcTextField
				:model-value="owner"
				:label="t('openbuild', 'Repository owner (user or org)')"
				:placeholder="t('openbuild', 'conduction')"
				@update:modelValue="owner = $event" />
			<NcTextField
				:model-value="name"
				:label="t('openbuild', 'Repository name')"
				:placeholder="t('openbuild', 'my-app')"
				@update:modelValue="name = $event" />
			<NcTextField
				:model-value="org"
				:label="t('openbuild', 'Create under organisation (optional)')"
				:placeholder="t('openbuild', 'Leave empty to use your own account')"
				@update:modelValue="org = $event" />
			<p v-if="error" class="link-repo__error" role="alert">
				{{ error }}
			</p>
			<div class="link-repo__actions">
				<NcButton @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!canSubmit || submitting"
					@click="submit">
					{{
						submitting
							? t('openbuild', 'Linking…')
							: t('openbuild', 'Link repository')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'LinkRepoDialog',
	components: { NcButton, NcModal, NcTextField },
	props: {
		/** Whether the modal is shown. */
		open: { type: Boolean, default: false },
		/** The Application slug whose repo is being linked. */
		slug: { type: String, default: '' },
	},
	emits: ['close', 'linked'],
	data() {
		return {
			owner: '',
			name: '',
			org: '',
			submitting: false,
			error: '',
		}
	},
	computed: {
		/**
		 * Whether the owner + name pass the safe GitHub identity pattern.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		canSubmit() {
			const ok = /^[A-Za-z0-9][A-Za-z0-9-]*$/
			return ok.test(this.owner.trim()) && ok.test(this.name.trim())
		},
	},
	watch: {
		open(value) {
			if (value) {
				this.owner = ''
				this.name = ''
				this.org = ''
				this.error = ''
				this.submitting = false
			}
		},
	},
	methods: {
		/**
		 * Close the dialog unless a request is in flight.
		 *
		 * @return {void}
		 */
		onClose() {
			if (this.submitting) {
				return
			}
			this.$emit('close')
		},
		/**
		 * POST the linkage to the GitHub sync link endpoint and emit `linked`.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/github/link',
					{ slug: this.slug },
				)
				const body = { owner: this.owner.trim(), name: this.name.trim() }
				if (this.org.trim()) {
					body.org = this.org.trim()
				}
				const { data } = await axios.post(url, body)
				this.$emit('linked', data)
				this.$emit('close')
			} catch (e) {
				const data = e?.response?.data
				this.error =
					data?.detail
					|| data?.error
					|| e?.message
					|| t('openbuild', 'Could not link the repository.')
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.link-repo {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.link-repo__summary {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.link-repo__error {
	color: var(--color-error);
	margin: 4px 0 0 0;
}

.link-repo__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 12px;
}
</style>
