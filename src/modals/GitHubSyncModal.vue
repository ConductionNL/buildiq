<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - GitHubSyncModal — the GitHub sync section of the application detail cockpit
  - (github-app-sync). Loads the app's GitHub status on open and surfaces the
  - owner round-trip: a credential picker, a link-repo affordance, a Publish
  - action (feature-detected via publishAvailable / brokerCredentialAvailable),
  - and a Pull action, plus a status readout (linked repo, last pushed/pulled
  - sha). Owners see the write controls; non-owners see the status readout only.
  - Publish is non-destructive (adds a commit); pull creates a new DRAFT version
  - (never overwrites production). Sub-dialogs live in their own files per
  - ADR-004 gate-modal-isolation.
  -->
<template>
	<NcModal
		v-if="open"
		:name="t('openbuild', 'GitHub')"
		size="normal"
		@close="$emit('update:open', false)">
		<div class="github-sync">
			<h2 class="github-sync__title">
				{{ t('openbuild', 'GitHub') }}
			</h2>

			<div v-if="loading" class="github-sync__loading">
				<NcLoadingIcon :size="28" />
				<span>{{ t('openbuild', 'Loading GitHub status…') }}</span>
			</div>

			<template v-else>
				<!-- Status readout (visible to everyone). -->
				<div class="github-sync__status">
					<template v-if="linked">
						<p class="github-sync__row">
							<span class="github-sync__label">{{
								t('openbuild', 'Linked repository')
							}}</span>
							<span class="github-sync__value">{{ repoLabel }}</span>
						</p>
						<p class="github-sync__row">
							<span class="github-sync__label">{{
								t('openbuild', 'Default branch')
							}}</span>
							<span class="github-sync__value">{{
								status.githubDefaultBranch || '—'
							}}</span>
						</p>
						<p class="github-sync__row">
							<span class="github-sync__label">{{
								t('openbuild', 'Last published commit')
							}}</span>
							<span
								class="github-sync__value github-sync__value--mono"
								>{{ shortSha(status.lastPushedSha) }}</span
							>
						</p>
						<p class="github-sync__row">
							<span class="github-sync__label">{{
								t('openbuild', 'Last pulled commit')
							}}</span>
							<span
								class="github-sync__value github-sync__value--mono"
								>{{ shortSha(status.lastPulledSha) }}</span
							>
						</p>
					</template>
					<NcNoteCard v-else type="info">
						{{
							t(
								'openbuild',
								'This app is not linked to a GitHub repository yet.',
							)
						}}
					</NcNoteCard>
				</div>

				<!-- Owner write controls. -->
				<template v-if="isOwner">
					<div class="github-sync__credential">
						<NcSelect
							v-model="selectedCredential"
							:inputLabel="t('openbuild', 'GitHub credential')"
							:options="credentialOptions"
							:placeholder="t('openbuild', 'Select a credential')"
							:clearable="true" />
						<NcNoteCard
							v-if="credentialOptions.length === 0"
							type="warning">
							{{
								t(
									'openbuild',
									'No GitHub credential found. Add one in your OpenRegister credentials settings to publish and to pull private repositories.',
								)
							}}
						</NcNoteCard>
					</div>

					<NcNoteCard v-if="!publishAvailable" type="warning">
						{{ publishHint }}
					</NcNoteCard>

					<div class="github-sync__actions">
						<NcButton @click="linkOpen = true">
							{{
								linked
									? t('openbuild', 'Re-link repository')
									: t('openbuild', 'Link repository')
							}}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="!canPublish"
							@click="openPublish">
							{{ t('openbuild', 'Publish') }}
						</NcButton>
						<NcButton :disabled="!linked || pulling" @click="doPull">
							{{
								pulling
									? t('openbuild', 'Pulling…')
									: t('openbuild', 'Pull')
							}}
						</NcButton>
					</div>

					<NcNoteCard v-if="pullResult" type="success">
						{{
							t(
								'openbuild',
								'Pulled a new draft version "{name}" from {ref}. Promote it via the version history when you are ready — your production version is unchanged.',
								{
									name:
										pullResult.versionSlug
										|| pullResult.versionUuid,
									ref: pullResult.sourceRef,
								},
							)
						}}
					</NcNoteCard>
					<NcNoteCard v-if="error" type="error">
						{{ error }}
					</NcNoteCard>
				</template>
			</template>
		</div>

		<LinkRepoDialog
			:open="linkOpen"
			:slug="slug"
			@close="linkOpen = false"
			@linked="onLinked" />
		<PublishConfirmDialog
			:open="publishOpen"
			:slug="slug"
			:credentialId="selectedCredentialId"
			:credentialName="selectedCredentialName"
			:versions="versions"
			:repo="repoContext"
			@close="publishOpen = false"
			@published="onPublished" />
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import LinkRepoDialog from './LinkRepoDialog.vue'
import PublishConfirmDialog from './PublishConfirmDialog.vue'

export default {
	name: 'GitHubSyncModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcSelect,
		LinkRepoDialog,
		PublishConfirmDialog,
	},

	props: {
		/** Whether the modal is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** The Application slug. */
		slug: { type: String, default: '' },
		/** Whether the caller is an owner (gates the write controls). */
		isOwner: { type: Boolean, default: false },
	},

	emits: ['update:open'],
	data() {
		return {
			status: null,
			loading: false,
			credentials: [],
			selectedCredential: null,
			versions: [],
			linkOpen: false,
			publishOpen: false,
			pulling: false,
			pullResult: null,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the app is linked to a GitHub repository.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		linked() {
			return !!(this.status && this.status.githubRepo)
		},

		/**
		 * Human label of the linked repository.
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		repoLabel() {
			const r = this.status && this.status.githubRepo
			return r ? `${r.owner}/${r.name}` : '—'
		},

		/**
		 * The linked repo context passed to the publish dialog.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		repoContext() {
			const r = this.status && this.status.githubRepo
			if (!r) {
				return null
			}
			return {
				owner: r.owner,
				name: r.name,
				branch: this.status.githubDefaultBranch,
			}
		},

		/**
		 * Whether publishing is available per the server feature-detection flags.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		publishAvailable() {
			return !!(this.status && this.status.publishAvailable)
		},

		/**
		 * The credential picker options (`{ id, label }`).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		credentialOptions() {
			return (Array.isArray(this.credentials) ? this.credentials : []).map(
				(c) => ({
					id: c.id,
					label: c.name || c.id,
				}),
			)
		},

		/**
		 * The selected credential id (unwraps the NcSelect option object).
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		selectedCredentialId() {
			return this.selectedCredential?.id ?? this.selectedCredential ?? ''
		},

		/**
		 * The selected credential display name.
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		selectedCredentialName() {
			return this.selectedCredential?.label ?? ''
		},

		/**
		 * Whether the Publish control may be enabled — available AND a credential
		 * is chosen. (Advisory; the server broker is the authoritative gate.)
		 *
		 * @return {boolean}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		canPublish() {
			return this.publishAvailable && !!this.selectedCredentialId
		},

		/**
		 * The disabled-publish hint explaining what is missing.
		 *
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		publishHint() {
			if (this.status && !this.status.brokerCredentialAvailable) {
				return t(
					'openbuild',
					'Publishing is unavailable: the credential broker or its GitHub write rules are not enabled on this instance. Pulling public repositories still works.',
				)
			}
			return t(
				'openbuild',
				'Publishing is unavailable until a GitHub credential is configured. Pulling public repositories still works.',
			)
		},
	},

	watch: {
		open(value) {
			if (value) {
				this.error = ''
				this.pullResult = null
				this.loadStatus()
				this.loadCredentials()
				this.loadVersions()
			}
		},
	},

	methods: {
		/**
		 * Shorten a commit sha for display.
		 *
		 * @param {string} sha The full commit sha.
		 * @return {string}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		shortSha(sha) {
			return sha ? String(sha).slice(0, 8) : '—'
		},

		/**
		 * Load the app's GitHub status (linked repo, shas, feature flags).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async loadStatus() {
			this.loading = true
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/github/status',
					{ slug: this.slug },
				)
				const { data } = await axios.get(url)
				this.status = data
			} catch (e) {
				this.status = null
				this.error = t('openbuild', 'Could not load the GitHub status.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the user's github credentials via OpenRegister's credentials API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async loadCredentials() {
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				const list = Array.isArray(data)
					? data
					: Array.isArray(data?.results)
						? data.results
						: []
				this.credentials = list.filter((c) => c && c.provider === 'github')
			} catch (e) {
				this.credentials = []
			}
		},

		/**
		 * Load the app's versions for the publish version picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async loadVersions() {
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/versions',
					{ slug: this.slug },
				)
				const { data } = await axios.get(url)
				this.versions = Array.isArray(data)
					? data
					: Array.isArray(data?.results)
						? data.results
						: []
			} catch (e) {
				this.versions = []
			}
		},

		/**
		 * Refresh status after a successful link.
		 *
		 * @return {void}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		onLinked() {
			this.linkOpen = false
			showSuccess(t('openbuild', 'Repository linked.'))
			this.loadStatus()
		},

		/**
		 * Open the publish confirm dialog (requires a chosen credential).
		 *
		 * @return {void}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		openPublish() {
			if (!this.canPublish) {
				return
			}
			this.publishOpen = true
		},

		/**
		 * Reflect a successful publish: update the status readout with the new
		 * commit sha.
		 *
		 * @param {object} result The push result `{ commitSha, repoUrl, branch }`.
		 * @return {void}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		onPublished(result) {
			this.publishOpen = false
			if (result && result.commitSha && this.status) {
				this.status = { ...this.status, lastPushedSha: result.commitSha }
			}
			showSuccess(
				t('openbuild', 'Published commit {sha}.', {
					sha: this.shortSha(result && result.commitSha),
				}),
			)
			this.loadStatus()
		},

		/**
		 * Pull the linked repo's default branch into a NEW draft version. Never
		 * overwrites production. Surfaces a strict-parse failure naming the file.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/github-app-sync/specs/application-detail-ui/spec.md
		 */
		async doPull() {
			if (!this.linked || this.pulling) {
				return
			}
			this.pulling = true
			this.error = ''
			this.pullResult = null
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/github/pull',
					{ slug: this.slug },
				)
				const body = {
					ref: (this.status && this.status.githubDefaultBranch) || 'main',
				}
				if (this.selectedCredentialId) {
					body.credentialId = this.selectedCredentialId
				}
				const { data } = await axios.post(url, body)
				if (
					data
					&& data.outcome
					&& data.outcome !== 'ok'
					&& !data.versionUuid
				) {
					this.error =
						data.detail || data.error || t('openbuild', 'Pull failed.')
					return
				}
				this.pullResult = data
				this.loadStatus()
			} catch (e) {
				const data = e?.response?.data
				const file = data?.file || data?.path
				const base =
					data?.detail
					|| data?.error
					|| e?.message
					|| t('openbuild', 'Pull failed.')
				this.error = file
					? t('openbuild', '{message} (in {file})', {
							message: base,
							file,
						})
					: base
			} finally {
				this.pulling = false
			}
		},
	},
}
</script>

<style scoped>
.github-sync {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.github-sync__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.github-sync__loading {
	display: flex;
	gap: 12px;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

.github-sync__status {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.github-sync__row {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	margin: 0;
}

.github-sync__label {
	color: var(--color-text-maxcontrast);
}

.github-sync__value--mono {
	font-family: var(--font-face-monospace, monospace);
}

.github-sync__credential {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.github-sync__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
