<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnSettingsSection
		:name="t('buildiq', 'Configuration')"
		:description="t('buildiq', 'Configure the app settings')">
		<form @submit.prevent="save">
			<div class="form-group">
				<label for="register">{{ t('buildiq', 'Register') }}</label>
				<input
					id="register"
					v-model="form.register"
					type="text"
					:placeholder="t('buildiq', 'OpenRegister register ID')" />
			</div>

			<h3 class="settings-subheading">
				{{ t('buildiq', 'Template registry') }}
			</h3>
			<p class="settings-help">
				{{
					t(
						'buildiq',
						'Connect a remote registry to browse and install shared templates from a store.',
					)
				}}
			</p>

			<div class="form-group">
				<label for="registry_url">{{ t('buildiq', 'Registry URL') }}</label>
				<input
					id="registry_url"
					v-model="form.registry_url"
					type="text"
					:placeholder="t('buildiq', 'https://store.example.com')" />
			</div>

			<div class="form-group">
				<label for="registry_register">{{
					t('buildiq', 'Registry register')
				}}</label>
				<input
					id="registry_register"
					v-model="form.registry_register"
					type="text"
					:placeholder="t('buildiq', 'buildiq')" />
			</div>

			<div class="form-group">
				<label for="registry_token">{{
					t('buildiq', 'Registry token')
				}}</label>
				<input
					id="registry_token"
					v-model="form.registry_token"
					type="password"
					autocomplete="new-password"
					:placeholder="tokenPlaceholder" />
				<span v-if="tokenIsSet" class="settings-help">
					{{
						t(
							'buildiq',
							'A token is set. Leave blank to keep the current token.',
						)
					}}
				</span>
			</div>

			<div v-if="successMessage" class="success-message">
				{{ successMessage }}
			</div>

			<NcButton variant="primary" type="submit" :disabled="saving">
				{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
			</NcButton>
		</form>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
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
				registry_url: '',
				registry_register: 'openbuild',
				registry_token: '',
			},

			tokenIsSet: false,
			saving: false,
			successMessage: '',
		}
	},

	computed: {
		/**
		 * Placeholder for the registry token field — hides whether a token is
		 * already configured (the token value is never returned by the backend).
		 *
		 * @return {string} The translated placeholder.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		tokenPlaceholder() {
			return this.tokenIsSet
				? t('buildiq', 'Leave blank to keep the current token')
				: t('buildiq', 'Bearer token (optional)')
		},
	},

	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
	 */
	created() {
		const settingsStore = useSettingsStore()
		const settings = settingsStore.settings || {}
		this.form.register = settings.register || ''
		this.form.registry_url = settings.registry_url || ''
		this.form.registry_register = settings.registry_register || 'buildiq'
		this.tokenIsSet = !!settings.registry_token_set
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
			// Empty token means "leave unchanged" — omit it from the payload.
			const payload = {
				register: this.form.register,
				registry_url: this.form.registry_url,
				registry_register: this.form.registry_register,
			}
			if (this.form.registry_token.trim().length > 0) {
				payload.registry_token = this.form.registry_token
			}
			const result = await settingsStore.saveSettings(payload)
			if (result) {
				this.successMessage = t('buildiq', 'Settings saved successfully')
				this.tokenIsSet =
					!!result.registry_token_set
					|| (this.tokenIsSet
						&& this.form.registry_token.trim().length === 0)
				this.form.registry_token = ''
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

.settings-subheading {
	margin: 20px 0 4px 0;
}

.settings-help {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px 0;
	font-size: 0.9rem;
}
</style>
