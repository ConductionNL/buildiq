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
			<h2>{{ t('buildiq', 'Publish to GitHub') }}</h2>
			<p v-if="linked" class="publish-confirm__summary">
				{{
					t(
						'buildiq',
						'Publishing adds a new commit to {repo} on branch {branch}. It never overwrites history.',
						{
							repo: repoLabel,
							branch:
								repo && repo.branch
									? repo.branch
									: t('buildiq', 'the default branch'),
						},
					)
				}}
			</p>
			<template v-else>
				<p class="publish-confirm__summary">
					{{
						t(
							'buildiq',
							'This app has no repository yet. Name one. Publishing creates it as a public repository, tagged so the store can find it.',
						)
					}}
				</p>
				<NcTextField
					:modelValue="repoName"
					:label="t('buildiq', 'Repository name')"
					:placeholder="defaultRepoName"
					@update:modelValue="repoName = $event" />
				<NcTextField
					:modelValue="org"
					:label="t('buildiq', 'Create under organisation (optional)')"
					:placeholder="
						t('buildiq', 'Leave empty to use your own account')
					"
					@update:modelValue="org = $event" />
			</template>
			<p class="publish-confirm__cred">
				{{
					t('buildiq', 'Using credential: {name}', {
						name: credentialName || t('buildiq', 'none selected'),
					})
				}}
			</p>
			<NcSelect
				v-model="selectedVersion"
				:inputLabel="t('buildiq', 'Version to publish')"
				:options="versionOptions"
				:placeholder="t('buildiq', 'Production version')"
				:clearable="true" />
			<p v-if="error" class="publish-confirm__error" role="alert">
				{{ error }}
			</p>
			<div class="publish-confirm__actions">
				<NcButton @click="onClose">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!credentialId || submitting"
					data-testid="publish-confirm-submit"
					@click="submit">
					{{
						submitting
							? t('buildiq', 'Publishing…')
							: t('buildiq', 'Publish')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'PublishConfirmDialog',
	components: { NcButton, NcModal, NcSelect, NcTextField },
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
			repoName: '',
			org: '',
			submitting: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the app already points at a repository.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		linked() {
			return !!(this.repo && this.repo.owner && this.repo.name)
		},

		/**
		 * The repository name proposed for an app that has none, matching the
		 * name the server would pick for a published template.
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		defaultRepoName() {
			return this.slug ? `openbuild-${this.slug}` : ''
		},

		/**
		 * The repository this publish creates, or null when one is linked.
		 *
		 * @return {?object} `{ name, org }`, or null.
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		newRepo() {
			if (this.linked) {
				return null
			}
			const name = (this.repoName || this.defaultRepoName).trim()
			if (!/^[A-Za-z0-9][A-Za-z0-9-]*$/.test(name)) {
				return null
			}
			return { name, org: this.org.trim() }
		},

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
			return t('buildiq', 'the linked repository')
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
		/**
		 * Seed the form each time the dialog opens, so a second publish never
		 * shows the first one's answers.
		 *
		 * @param {boolean} value Whether the dialog is now open.
		 * @return {void}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		open(value) {
			if (value) {
				this.selectedVersion = null
				this.repoName = this.defaultRepoName
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
		 * Map a non-ok push outcome to a clear, actionable message.
		 *
		 * @param {string} outcome The push outcome code.
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		outcomeMessage(outcome) {
			const messages = {
				push_conflict: t(
					'buildiq',
					'The remote branch moved ahead. Pull the latest changes first, then publish again.',
				),

				broker_denied: t(
					'buildiq',
					"The credential broker denied this publish. Check the credential's allowed apps and scopes.",
				),

				broker_unavailable: t(
					'buildiq',
					'The credential broker is unavailable. Publishing is disabled until it is configured.',
				),

				not_linked: t('buildiq', 'Link a repository before publishing.'),
				github_rate_limited: t(
					'buildiq',
					'GitHub is rate-limiting this credential right now. Try again shortly.',
				),

				github_unreachable: t(
					'buildiq',
					'GitHub could not be reached. Try again shortly.',
				),
			}
			return messages[outcome] || t('buildiq', 'Publishing failed.')
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
					'/apps/buildiq/api/applications/{slug}/github/push',
					{ slug: this.slug },
				)
				const body = { credentialId: this.credentialId }
				// An app with no repository names one here, and the push endpoint
				// creates it, tags it with the discovery topic and links it. Without
				// this the publish came back `not_linked`, and the only way to a
				// first repository was to create it on github.com by hand.
				if (!this.linked) {
					if (this.newRepo === null) {
						this.error = t(
							'buildiq',
							'Give the repository a name of letters, numbers and dashes.',
						)
						this.submitting = false
						return
					}
					body.repo = this.newRepo
				}
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
					|| t('buildiq', 'Publishing failed.')
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
