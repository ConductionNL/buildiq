<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PublishConfirmDialog — owner confirmation before publishing (pushing) an
  - Application version to its linked GitHub repository. Lets the owner pick
  - which version to publish, then POSTs to the GitHub sync push endpoint with
  - the credential chosen in GitHubSyncModal. Non-destructive: push ADDS a
  - commit. Handles push_conflict / broker_* outcomes with clear messages.
  - Kept in its own file per ADR-004 gate-modal-isolation.
  -->
<template>
	<NcModal v-if="open" size="small" @close="onClose">
		<div class="publish-confirm">
			<h2>{{ t('openbuild', 'Publish to GitHub') }}</h2>
			<p class="publish-confirm__summary">
				{{
					t(
						'openbuild',
						'Publishing adds a new commit to {repo} on branch {branch}. It never overwrites history.',
						{
							repo: repoLabel,
							branch:
								repo && repo.branch
									? repo.branch
									: t('openbuild', 'the default branch'),
						},
					)
				}}
			</p>
			<p class="publish-confirm__cred">
				{{
					t('openbuild', 'Using credential: {name}', {
						name: credentialName || t('openbuild', 'none selected'),
					})
				}}
			</p>
			<NcSelect
				v-model="selectedVersion"
				:input-label="t('openbuild', 'Version to publish')"
				:options="versionOptions"
				:placeholder="t('openbuild', 'Production version')"
				:clearable="true" />
			<p v-if="error" class="publish-confirm__error" role="alert">
				{{ error }}
			</p>
			<div class="publish-confirm__actions">
				<NcButton @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!credentialId || submitting"
					@click="submit">
					{{
						submitting
							? t('openbuild', 'Publishing…')
							: t('openbuild', 'Publish')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcSelect } from '@nextcloud/vue'

export default {
	name: 'PublishConfirmDialog',
	components: { NcButton, NcModal, NcSelect },
	props: {
		/** Whether the modal is shown. */
		open: { type: Boolean, default: false },
		/** The Application slug being published. */
		slug: { type: String, default: '' },
		/** The chosen broker credential id (required to publish). */
		credentialId: { type: String, default: '' },
		/** Display name of the chosen credential. */
		credentialName: { type: String, default: '' },
		/** The Application's versions (for the version picker). */
		versions: { type: Array, default: () => [] },
		/** The linked repo `{ owner, name, branch }` for the summary line. */
		repo: { type: Object, default: null },
	},
	emits: ['close', 'published'],
	data() {
		return {
			selectedVersion: null,
			submitting: false,
			error: '',
		}
	},
	computed: {
		/**
		 * Human label of the linked repository for the summary line.
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		repoLabel() {
			if (this.repo && this.repo.owner && this.repo.name) {
				return `${this.repo.owner}/${this.repo.name}`
			}
			return t('openbuild', 'the linked repository')
		},
		/**
		 * The version picker options (`{ id: slug, label }`).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		versionOptions() {
			return (Array.isArray(this.versions) ? this.versions : []).map((v) => ({
				id: v.slug,
				label: `${v.name || v.slug}${v.semver ? ` (${v.semver})` : ''}`,
			}))
		},
	},
	watch: {
		open(value) {
			if (value) {
				this.selectedVersion = null
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
		 * Map a non-ok push outcome to a clear, actionable message.
		 *
		 * @param {string} outcome The push outcome code.
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		outcomeMessage(outcome) {
			const messages = {
				push_conflict: t(
					'openbuild',
					'The remote branch moved ahead. Pull the latest changes first, then publish again.',
				),
				broker_denied: t(
					'openbuild',
					"The credential broker denied this publish. Check the credential's allowed apps and scopes.",
				),
				broker_unavailable: t(
					'openbuild',
					'The credential broker is unavailable. Publishing is disabled until it is configured.',
				),
				not_linked: t('openbuild', 'Link a repository before publishing.'),
				github_unreachable: t(
					'openbuild',
					'GitHub could not be reached. Try again shortly.',
				),
			}
			return messages[outcome] || t('openbuild', 'Publishing failed.')
		},
		/**
		 * POST the push to the GitHub sync endpoint. Surfaces push_conflict /
		 * broker_* outcomes as errors; emits `published` with the commit sha on
		 * success.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async submit() {
			if (!this.credentialId || this.submitting) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/github/push',
					{ slug: this.slug },
				)
				const body = { credentialId: this.credentialId }
				const versionSlug =
					this.selectedVersion?.id ?? this.selectedVersion ?? null
				if (versionSlug) {
					body.versionSlug = versionSlug
				}
				const { data } = await axios.post(url, body)
				const outcome = data?.outcome
				if (outcome && outcome !== 'ok' && !data?.commitSha) {
					this.error = this.outcomeMessage(outcome)
					this.submitting = false
					return
				}
				this.$emit('published', data)
				this.$emit('close')
			} catch (e) {
				const data = e?.response?.data
				this.error =
					(data?.outcome && this.outcomeMessage(data.outcome))
					|| data?.detail
					|| data?.error
					|| e?.message
					|| t('openbuild', 'Publishing failed.')
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.publish-confirm {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.publish-confirm__summary {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.publish-confirm__cred {
	margin: 0;
	font-size: 0.9rem;
}

.publish-confirm__error {
	color: var(--color-error);
	margin: 4px 0 0 0;
}

.publish-confirm__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 12px;
}
</style>
