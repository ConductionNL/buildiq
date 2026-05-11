<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcDialog
		:name="t('openbuilt', 'Export application')"
		:can-close="!submitting"
		size="normal"
		@closing="onClose">
		<form class="export-dialog" @submit.prevent="submit">
			<NcSelect
				v-model="form.version"
				:input-label="t('openbuilt', 'Version')"
				:options="versionOptions"
				:disabled="submitting" />
			<NcSelect
				v-model="form.target"
				:input-label="t('openbuilt', 'Target')"
				:options="targetOptions"
				:disabled="submitting" />
			<NcSelect
				v-model="form.license"
				:input-label="t('openbuilt', 'License')"
				:options="licenseOptions"
				:disabled="submitting" />
			<NcCheckboxRadioSwitch
				v-model="form.includeSeedData"
				:disabled="submitting">
				{{ t('openbuilt', 'Include seed data') }}
			</NcCheckboxRadioSwitch>

			<template v-if="form.target && form.target.value === 'github'">
				<NcTextField
					v-model="form.githubOrg"
					:label="t('openbuilt', 'GitHub organisation')"
					:disabled="submitting" />
				<NcTextField
					v-model="form.githubRepo"
					:label="t('openbuilt', 'Repository name')"
					:disabled="submitting" />
				<NcSelect
					v-model="form.githubVisibility"
					:input-label="t('openbuilt', 'Visibility')"
					:options="visibilityOptions"
					:disabled="submitting" />
				<NcTextField
					v-model="form.githubPat"
					type="password"
					autocomplete="off"
					:label="t('openbuilt', 'GitHub personal access token')"
					:disabled="submitting" />
				<p class="export-dialog__scope-hint">
					{{ t('openbuilt', 'The token needs the `repo` scope. It is sent once over your Nextcloud session, stored encrypted via the credentials manager, and deleted automatically when the export finishes.') }}
				</p>
			</template>

			<p v-if="errorMessage" class="export-dialog__error">{{ errorMessage }}</p>
		</form>

		<template #actions>
			<NcButton :disabled="submitting" @click="onClose">
				{{ t('openbuilt', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting" @click="submit">
				{{ t('openbuilt', 'Start export') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

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
	},
	emits: ['close', 'queued'],
	data() {
		return {
			submitting: false,
			errorMessage: '',
			form: {
				version: this.availableVersions[0] || { label: '0.1.0', value: '0.1.0' },
				target: { label: this.t('openbuilt', 'ZIP download'), value: 'zip' },
				license: { label: 'EUPL-1.2', value: 'EUPL-1.2' },
				includeSeedData: false,
				githubOrg: '',
				githubRepo: '',
				githubVisibility: { label: this.t('openbuilt', 'Private'), value: 'private' },
				githubPat: '',
			},
		}
	},
	computed: {
		versionOptions() {
			return this.availableVersions
		},
		targetOptions() {
			return [
				{ label: this.t('openbuilt', 'ZIP download'), value: 'zip' },
				{ label: this.t('openbuilt', 'Push to GitHub'), value: 'github' },
			]
		},
		licenseOptions() {
			return [
				{ label: 'EUPL-1.2', value: 'EUPL-1.2' },
				{ label: 'AGPL-3.0', value: 'AGPL-3.0' },
				{ label: 'MIT', value: 'MIT' },
			]
		},
		visibilityOptions() {
			return [
				{ label: this.t('openbuilt', 'Private'), value: 'private' },
				{ label: this.t('openbuilt', 'Public'), value: 'public' },
			]
		},
	},
	methods: {
		onClose() {
			if (this.submitting) {
				return
			}
			this.$emit('close')
		},
		async submit() {
			this.submitting = true
			this.errorMessage = ''
			try {
				const payload = {
					applicationVersion: this.form.version.value,
					target: this.form.target.value,
					license: this.form.license.value,
					includeSeedData: this.form.includeSeedData,
				}
				if (this.form.target.value === 'github') {
					payload.githubOrg = this.form.githubOrg
					payload.githubRepo = this.form.githubRepo
					payload.githubVisibility = this.form.githubVisibility.value
					payload.githubPat = this.form.githubPat
				}
				const url = generateUrl(`/apps/openbuilt/api/applications/${encodeURIComponent(this.applicationSlug)}/exports`)
				const response = await axios.post(url, payload)
				this.$emit('queued', response.data.uuid)
				this.$emit('close')
			} catch (err) {
				this.errorMessage = err?.response?.data?.error
					|| this.t('openbuilt', 'GitHub authentication failed. Please check the token scope and try again.')
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
.export-dialog__error {
	color: var(--color-error);
	margin: 0;
}
</style>
