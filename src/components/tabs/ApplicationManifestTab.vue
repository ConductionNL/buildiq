<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationManifestTab — raw-JSON manifest editor, mounted as the
  - "Manifest" sidebar tab on the VirtualAppDetail (`type: detail`) page.
  - The visual designer lives at /builder/:slug/pages (PageDesigner); this
  - is the integrator-only raw fallback. Reads/writes the Application via
  - OR's REST API (ADR-022); Save is gated to editor/owner per useRole.
  -->
<template>
	<div class="ob-manifest-tab">
		<p class="ob-manifest-tab__help">
			{{ t('openbuilt', 'Integrator-only editor: edit the raw JSON manifest below. For a visual editor open "Design pages".') }}
		</p>
		<textarea
			v-model="manifestText"
			class="ob-manifest-tab__textarea"
			data-testid="openbuilt-editor-textarea"
			spellcheck="false"
			:readonly="obAppRole === 'viewer' || obAppRole === 'none'"
			:placeholder="t('openbuilt', 'Paste or edit the JSON manifest here.')" />
		<div v-if="error" class="ob-manifest-tab__error">
			{{ t('openbuilt', 'Invalid manifest') }}: {{ error }}
		</div>
		<div v-if="obAppError" class="ob-manifest-tab__error">
			{{ obAppError }}
		</div>
		<div class="ob-manifest-tab__actions">
			<NcButton
				v-if="obAppRole === 'editor' || obAppRole === 'owner'"
				type="primary"
				:disabled="!obApp || saving"
				data-testid="openbuilt-editor-save"
				@click="save">
				{{ saving ? t('openbuilt', 'Saving…') : t('openbuilt', 'Save') }}
			</NcButton>
			<span v-if="savedToast" class="ob-manifest-tab__toast">{{ savedToast }}</span>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { validateManifest } from '@conduction/nextcloud-vue'
import applicationContext from '../../mixins/applicationContext.js'

export default {
	name: 'ApplicationManifestTab',
	components: { NcButton },
	mixins: [applicationContext],
	data() {
		return {
			manifestText: '',
			error: '',
			saving: false,
			savedToast: '',
		}
	},
	watch: {
		obApp: {
			immediate: true,
			handler(app) {
				if (app) {
					this.manifestText = JSON.stringify(app.manifest || {}, null, 2)
				}
			},
		},
	},
	methods: {
		parseAndValidate() {
			let parsed
			try {
				parsed = JSON.parse(this.manifestText)
			} catch (e) {
				this.error = `${t('openbuilt', 'JSON parse error')}: ${e.message}`
				return null
			}
			const result = validateManifest ? validateManifest(parsed) : { valid: true, errors: [] }
			if (result && result.valid === false) {
				this.error = (result.errors || ['unknown']).join('; ')
				return null
			}
			this.error = ''
			return parsed
		},
		async save() {
			if (this.obAppRole !== 'editor' && this.obAppRole !== 'owner') {
				return
			}
			const parsed = this.parseAndValidate()
			if (parsed === null) {
				return
			}
			this.saving = true
			this.savedToast = ''
			try {
				await this.obPatchApp({ manifest: parsed })
				this.savedToast = t('openbuilt', 'Saved')
			} catch (e) {
				this.error = `${t('openbuilt', 'Save failed')}: ${e.message || e}`
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.ob-manifest-tab {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.ob-manifest-tab__help {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
	margin: 0;
}

.ob-manifest-tab__textarea {
	width: 100%;
	min-height: 320px;
	font-family: monospace;
	font-size: 12px;
	padding: 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	resize: vertical;
}

.ob-manifest-tab__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}

.ob-manifest-tab__actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.ob-manifest-tab__toast {
	font-size: 13px;
	color: var(--color-success-text, #2d8a3e);
}
</style>
