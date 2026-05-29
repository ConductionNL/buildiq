<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnSettingsSection
		:name="t('openbuild', 'Configuration')"
		:description="t('openbuild', 'Configure the app settings')">
		<form @submit.prevent="save">
			<div class="form-group">
				<label for="register">{{ t('openbuild', 'Register') }}</label>
				<input
					id="register"
					v-model="form.register"
					type="text"
					:placeholder="t('openbuild', 'OpenRegister register ID')">
			</div>

			<div v-if="successMessage" class="success-message">
				{{ successMessage }}
			</div>

			<NcButton
				type="primary"
				native-type="submit"
				:disabled="saving">
				{{ saving ? t('openbuild', 'Saving...') : t('openbuild', 'Save') }}
			</NcButton>
		</form>
	</CnSettingsSection>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		CnSettingsSection,
	},
	data() {
		return {
			form: {
				register: '',
			},
			saving: false,
			successMessage: '',
		}
	},
	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
	 */
	created() {
		const settingsStore = useSettingsStore()
		this.form.register = settingsStore.settings?.register || ''
	},
	methods: {
		/**
		 * Observed behaviour of `save` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
		 */
		async save() {
			this.saving = true
			this.successMessage = ''
			const settingsStore = useSettingsStore()
			const result = await settingsStore.saveSettings(this.form)
			if (result) {
				this.successMessage = t('openbuild', 'Settings saved successfully')
			}
			this.saving = false
		},
	},
}
</script>

<style scoped>
.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
}

.success-message {
	color: var(--color-success);
	margin-bottom: 8px;
}
</style>
