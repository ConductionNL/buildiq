<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcDialog
		:name="t('openbuild', 'Export application')"
		:noClose="submitting"
		size="normal"
		@closing="onClose">
		<form class="export-dialog" @submit.prevent="submit">
			<NcSelect
				v-model="form.version"
				:inputLabel="t('openbuild', 'Version')"
				:options="versionOptions"
				:disabled="submitting" />
			<NcSelect
				v-model="form.target"
				:inputLabel="t('openbuild', 'Target')"
				:options="targetOptions"
				:disabled="submitting" />
			<NcSelect
				v-model="form.license"
				:inputLabel="t('openbuild', 'License')"
				:options="licenseOptions"
				:disabled="submitting" />
			<NcCheckboxRadioSwitch
				v-model="form.includeSeedData"
				:disabled="submitting">
				{{ t('openbuild', 'Include seed data') }}
			</NcCheckboxRadioSwitch>

			<template v-if="dataRegisterChoices.length">
				<h4 class="export-dialog__section-title">
					{{ t('openbuild', 'Data registers') }}
				</h4>
				<p class="export-dialog__scope-hint">
					{{
						t(
							'openbuild',
							'This app is bound to shared data registers it does not own. Their schema definitions are always included as reference material. Row data is only bundled for a register when you switch it on below.',
						)
					}}
				</p>
				<NcCheckboxRadioSwitch
					v-for="choice in dataRegisterChoices"
					:key="choice.register"
					v-model="choice.includeData"
					:disabled="submitting">
					{{
						t('openbuild', 'Include row data for {label}', {
							label: choice.label || choice.register,
						})
					}}
				</NcCheckboxRadioSwitch>
			</template>

			<template v-if="flowChoices.length">
				<h4 class="export-dialog__section-title">
					{{ t('openbuild', 'Flows') }}
				</h4>
				<p class="export-dialog__scope-hint">
					{{
						t(
							'openbuild',
							'The flows this app is made of. Included by default — an exported app without them installs and does nothing. They arrive switched off on the importing instance, so nothing runs until somebody enables it there.',
						)
					}}
				</p>
				<!-- The data-test hook lives on a plain wrapper, not on the
				     NcCheckboxRadioSwitch: that component sets
				     `inheritAttrs: false`, so an attribute passed to it does
				     not reach the DOM and a selector for it finds nothing. -->
				<div
					v-for="choice in flowChoices"
					:key="choice.flow"
					:data-test="`export-flow-${choice.flow}`">
					<NcCheckboxRadioSwitch
						v-model="choice.include"
						:disabled="submitting">
						{{ choice.label || choice.flow }}
					</NcCheckboxRadioSwitch>
				</div>
			</template>

			<template v-if="form.target && form.target.value === 'github'">
				<NcTextField
					v-model="form.githubOrg"
					:label="t('openbuild', 'GitHub organisation')"
					:disabled="submitting" />
				<NcTextField
					v-model="form.githubRepo"
					:label="t('openbuild', 'Repository name')"
					:disabled="submitting" />
				<NcSelect
					v-model="form.githubVisibility"
					:inputLabel="t('openbuild', 'Visibility')"
					:options="visibilityOptions"
					:disabled="submitting" />
				<NcSelect
					v-model="form.githubCredential"
					:inputLabel="t('openbuild', 'GitHub credential')"
					:options="githubCredentials"
					:loading="loadingCredentials"
					:disabled="submitting"
					:placeholder="t('openbuild', 'Select a credential')" />
				<p
					v-if="!loadingCredentials && !githubCredentials.length"
					class="export-dialog__scope-hint">
					{{
						t(
							'openbuild',
							'You have no GitHub credential yet. Add one under Personal settings → Additional settings, then reopen this dialog. OpenBuild never sees the token itself — it asks the credential broker to make each GitHub call on your behalf.',
						)
					}}
				</p>
				<p v-else class="export-dialog__scope-hint">
					{{
						t(
							'openbuild',
							'The token stays in your credential vault. OpenBuild sends only the request it wants made, and the broker injects the token and refuses anything outside the allowed GitHub calls.',
						)
					}}
				</p>
			</template>

			<p v-if="errorMessage" class="export-dialog__error">
				{{ errorMessage }}
			</p>
		</form>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="submitting" @click="submit">
				{{ t('openbuild', 'Start export') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ExportDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		applicationSlug: {
			type: String,
			required: true,
		},

		availableVersions: {
			type: Array,
			default: () => [{ label: '0.1.0', value: '0.1.0' }],
		},

		// The source Application's declared `dataRegisters` bindings
		// (data-registers-runtime design.md Decision 5). One toggle is
		// rendered per binding, unchecked by default (schema-defs-only).
		dataRegisters: {
			type: Array,
			default: () => [],
		},

		// The source Application's declared `flows` bindings. Each entry is
		// `{ label, flow }` where `flow` is the OpenRegister flow's UUID —
		// the `Flow` entity has no slug, so there is nothing more readable to
		// bind by, which is why `label` carries the weight in this picker.
		//
		// There is deliberately no `agents` prop: agents carry
		// `applicationSlug` and are collected from the application itself, so
		// there is nothing for an operator to choose.
		flows: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'queued'],
	data() {
		return {
			submitting: false,
			errorMessage: '',
			// The user's `github` broker credentials, as NcSelect options.
			// OpenBuild only ever learns their UUIDs — never the tokens behind them.
			githubCredentials: [],
			loadingCredentials: false,
			form: {
				version: this.availableVersions[0] || {
					label: '0.1.0',
					value: '0.1.0',
				},

				target: { label: this.t('openbuild', 'ZIP download'), value: 'zip' },
				license: { label: 'EUPL-1.2', value: 'EUPL-1.2' },
				includeSeedData: false,
				githubOrg: '',
				githubRepo: '',
				githubVisibility: {
					label: this.t('openbuild', 'Private'),
					value: 'private',
				},

				githubCredential: null,
			},

			// Per-binding includeData choice, unchecked by default. Built
			// once from the dataRegisters prop — mirrors `form`'s own
			// once-at-creation pattern above.
			dataRegisterChoices: this.dataRegisters.map((binding) => ({
				register: binding.register,
				label: binding.label,
				includeData: false,
			})),

			// Per-flow include choice, checked by DEFAULT — unlike row data.
			// A flow is what makes an exported app do anything, so the useful
			// default is "ship what this app is made of"; row data is somebody
			// else's content and defaults off for that reason.
			flowChoices: this.flows.map((binding) => ({
				flow: binding.flow,
				label: binding.label,
				include: true,
			})),
		}
	},

	computed: {
		/**
		 * Observed behaviour of `versionOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		versionOptions() {
			return this.availableVersions
		},

		/**
		 * Observed behaviour of `targetOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		targetOptions() {
			return [
				{ label: this.t('openbuild', 'ZIP download'), value: 'zip' },
				{ label: this.t('openbuild', 'Push to GitHub'), value: 'github' },
			]
		},

		/**
		 * Observed behaviour of `licenseOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		licenseOptions() {
			return [
				{ label: 'EUPL-1.2', value: 'EUPL-1.2' },
				{ label: 'AGPL-3.0', value: 'AGPL-3.0' },
				{ label: 'MIT', value: 'MIT' },
			]
		},

		/**
		 * Observed behaviour of `visibilityOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		visibilityOptions() {
			return [
				{ label: this.t('openbuild', 'Private'), value: 'private' },
				{ label: this.t('openbuild', 'Public'), value: 'public' },
			]
		},
	},

	mounted() {
		this.fetchGithubCredentials()
	},

	methods: {
		/**
		 * Load the user's `github` credentials from OpenRegister's broker.
		 *
		 * We deliberately ask for the whole personal wallet and filter client-side:
		 * the endpoint already scopes to the caller's own credentials, and the
		 * response carries no secrets — only names, providers and UUIDs.
		 *
		 * A failure here is not fatal. It leaves the list empty, which the template
		 * renders as "you have no GitHub credential yet", and submit() still refuses
		 * to queue a GitHub export without one.
		 *
		 * @spec openspec/changes/export-github-broker/tasks.md#task-4-credential-picker-in-the-export-dialog
		 */
		async fetchGithubCredentials() {
			this.loadingCredentials = true
			try {
				const url = generateUrl('/apps/openregister/api/credentials')
				const response = await axios.get(url)
				const results = response.data.results || []
				this.githubCredentials = results
					.filter((cred) => cred.provider === 'github')
					.map((cred) => ({ label: cred.name || cred.id, value: cred.id }))
				if (this.githubCredentials.length === 1) {
					this.form.githubCredential = this.githubCredentials[0]
				}
			} catch (error) {
				this.githubCredentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * Observed behaviour of `onClose` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		onClose() {
			if (this.submitting) {
				return
			}
			this.$emit('close')
		},

		/**
		 * Observed behaviour of `submit` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-1
		 */
		async submit() {
			this.submitting = true
			this.errorMessage = ''
			try {
				const payload = {
					applicationVersion: this.form.version.value,
					target: this.form.target.value,
					license: this.form.license.value,
					includeSeedData: this.form.includeSeedData,
					// Mirrors the source Application's dataRegisters bindings
					// 1:1, each carrying the resolved includeData flag
					// (data-registers-runtime design.md Decision 5).
					dataRegisters: this.dataRegisterChoices.map((choice) => ({
						register: choice.register,
						includeData: choice.includeData,
					})),

					// Only the chosen flows, and only their UUIDs — `label` is
					// a picker convenience, and the exporter writes the flow's
					// own name into the bundle rather than trusting this one.
					flows: this.flowChoices
						.filter((choice) => choice.include)
						.map((choice) => ({ flow: choice.flow })),
				}
				if (this.form.target.value === 'github') {
					if (!this.form.githubCredential) {
						this.errorMessage = this.t(
							'openbuild',
							'Pick a GitHub credential to push with.',
						)
						this.submitting = false
						return
					}
					payload.githubOrg = this.form.githubOrg
					payload.githubRepo = this.form.githubRepo
					payload.githubVisibility = this.form.githubVisibility.value
					// A broker credential UUID, not a token. The secret never leaves the vault.
					payload.githubCredentialId = this.form.githubCredential.value
				}
				const url = generateUrl(
					`/apps/openbuild/api/applications/${encodeURIComponent(this.applicationSlug)}/exports`,
				)
				const response = await axios.post(url, payload)
				this.$emit('queued', response.data.uuid)
				this.$emit('close')
			} catch (err) {
				this.errorMessage =
					err?.response?.data?.error
					|| this.t(
						'openbuild',
						'GitHub authentication failed. Please check the token scope and try again.',
					)
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.export-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) 0;
}

.export-dialog__scope-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0;
}

.export-dialog__section-title {
	margin: 8px 0 0;
	font-size: 0.95rem;
	font-weight: 600;
}

.export-dialog__error {
	color: var(--color-error);
	margin: 0;
}
</style>
