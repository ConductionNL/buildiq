<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppSettingsModal — owner-facing app settings. Holds the publish/unpublish
  - toggle (whether the app is live in the Nextcloud app menu) and the
  - allow-user-overrides toggle. Emits intent; the parent
  - (ApplicationDetailActions) performs the API calls. Kept in its own file per
  - ADR-004 gate-modal-isolation.
  -->
<template>
	<NcModal v-if="open" :name="t('openbuild', 'App settings')" @close="$emit('update:open', false)">
		<div class="app-settings">
			<h3 class="app-settings__title">{{ appName }}</h3>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:checked="isPublished"
					:disabled="busy"
					@update:checked="$emit('set-published', $event)">
					{{ t('openbuild', 'Published') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{ t('openbuild', 'Published apps appear in the Nextcloud app menu. Drafts are hidden.') }}
				</p>
			</div>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:checked="allowUserOverrides"
					:disabled="busy"
					@update:checked="$emit('update:allow-overrides', $event)">
					{{ t('openbuild', 'Allow per-user customisation') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{ t('openbuild', 'Let each user layer their own manifest changes on top of the shared app.') }}
				</p>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

export default {
	name: 'AppSettingsModal',
	components: { NcModal, NcCheckboxRadioSwitch },
	props: {
		/** Whether the modal is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Display name of the app. */
		appName: { type: String, default: '' },
		/** Whether the app is currently published. */
		isPublished: { type: Boolean, default: false },
		/** Whether per-user overrides are enabled. */
		allowUserOverrides: { type: Boolean, default: false },
		/** Whether an action is in flight (disables the switches). */
		busy: { type: Boolean, default: false },
	},
	emits: ['update:open', 'set-published', 'update:allow-overrides'],
}
</script>

<style scoped>
.app-settings {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.app-settings__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.app-settings__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.app-settings__hint {
	margin: 0 0 0 44px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
