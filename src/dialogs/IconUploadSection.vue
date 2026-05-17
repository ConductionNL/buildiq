<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - IconUploadSection — icon upload and preview section for the Application
  - detail page.  Rendered as a plain section (not a modal) so it can live
  - inline inside the detail page's tab list.
  -
  - Exposes:
  -   - Light icon slot: file input, uploads to OR files-attached-to-object,
  -     patches top-level icon.ref on the Application.
  -   - Dark icon slot: same flow for top-level iconDark.ref.
  -   - Live preview: white-bg (light) + dark-bg (dark) 48×48 boxes.
  -   - Remove buttons: detach from OR and clear the ref field.
  -
  - Calls:
  -   - POST   /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}/files
  -             — upload SVG as multipart/form-data
  -   - DELETE /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}/files/{filename}
  -             — remove the attached file
  -   - PUT    /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}
  -             — patch icon / iconDark refs on the Application record
  -
  - REQ-OBICON-004 / openbuilt-nextcloud-nav
  -->
<template>
	<div class="ob-icon-section">
		<h3 class="ob-icon-section__heading">
			{{ t('openbuilt', 'App icon') }}
		</h3>

		<!-- Light icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('openbuilt', 'Light icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--light">
				<img
					v-if="iconLightUrl"
					:src="iconLightUrl"
					:alt="t('openbuilt', 'Light icon preview')"
					class="ob-icon-section__preview-img"
					@error="onLightPreviewError">
				<span v-else class="ob-icon-section__preview-empty">—</span>
			</div>
			<label class="ob-icon-section__file-label">
				<input
					ref="lightInput"
					type="file"
					accept=".svg"
					class="ob-icon-section__file-input"
					:disabled="uploading"
					@change="onLightFileChange">
				<span>{{ t('openbuilt', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="lightRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeLightIcon">
				{{ t('openbuilt', 'Remove') }}
			</button>
			<span v-if="lightError" class="ob-icon-section__error">{{ lightError }}</span>
		</div>

		<!-- Dark icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('openbuilt', 'Dark icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--dark">
				<img
					v-if="iconDarkUrl"
					:src="iconDarkUrl"
					:alt="t('openbuilt', 'Dark icon preview')"
					class="ob-icon-section__preview-img"
					@error="onDarkPreviewError">
				<span v-else class="ob-icon-section__preview-empty">—</span>
			</div>
			<label class="ob-icon-section__file-label">
				<input
					ref="darkInput"
					type="file"
					accept=".svg"
					class="ob-icon-section__file-input"
					:disabled="uploading"
					@change="onDarkFileChange">
				<span>{{ t('openbuilt', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="darkRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeDarkIcon">
				{{ t('openbuilt', 'Remove') }}
			</button>
			<span v-if="darkError" class="ob-icon-section__error">{{ darkError }}</span>
		</div>

		<p v-if="uploadError" class="ob-icon-section__global-error">
			{{ uploadError }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER = 'openbuilt'
const SCHEMA = 'application'

export default {
	name: 'IconUploadSection',

	props: {
		/** Application object from OR (includes uuid/@self.id, icon, iconDark). */
		application: { type: Object, required: true },
	},

	emits: ['updated'],

	data() {
		return {
			lightRef: null,
			darkRef: null,
			uploading: false,
			lightError: '',
			darkError: '',
			uploadError: '',
			// Cache-busting nonces appended to preview URLs after upload.
			lightNonce: Date.now(),
			darkNonce: Date.now(),
		}
	},

	computed: {
		objectUuid() {
			const self = this.application['@self'] || {}
			return self.id || this.application.uuid || this.application.id || ''
		},
		iconLightUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/openbuilt/icons/${this.application.slug}.svg?v=${this.lightNonce}`
		},
		iconDarkUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/openbuilt/icons/${this.application.slug}-dark.svg?v=${this.darkNonce}`
		},
	},

	watch: {
		application: {
			immediate: true,
			handler(app) {
				this.lightRef = app?.icon?.ref || null
				this.darkRef = app?.iconDark?.ref || null
			},
		},
	},

	methods: {
		onLightPreviewError(e) {
			e.target.style.display = 'none'
		},
		onDarkPreviewError(e) {
			e.target.style.display = 'none'
		},

		validateSvgFile(file) {
			if (!file) return false
			if (!file.name.toLowerCase().endsWith('.svg')) {
				return false
			}
			return true
		},

		async onLightFileChange(event) {
			this.lightError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.lightError = t('openbuilt', 'Only .svg files are accepted')
				this.$refs.lightInput.value = ''
				return
			}
			await this.uploadIcon(file, 'light')
		},

		async onDarkFileChange(event) {
			this.darkError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.darkError = t('openbuilt', 'Only .svg files are accepted')
				this.$refs.darkInput.value = ''
				return
			}
			await this.uploadIcon(file, 'dark')
		},

		async uploadIcon(file, variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const filename = variant === 'dark' ? 'app-icon-dark.svg' : 'app-icon.svg'

			try {
				// 1. Upload the file to OR's files-attached-to-object endpoint.
				const formData = new FormData()
				formData.append('file', file, filename)

				const uploadUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files`,
				)
				await axios.post(uploadUrl, formData, {
					headers: { 'Content-Type': 'multipart/form-data' },
				})

				// 2. Patch the Application record with the new icon ref.
				const field = variant === 'dark' ? 'iconDark' : 'icon'
				const payload = { [field]: { ref: filename } }
				const patchUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}`,
				)
				await axios.put(patchUrl, payload)

				// 3. Update local state and notify parent.
				if (variant === 'dark') {
					this.darkRef = filename
					this.darkNonce = Date.now()
				} else {
					this.lightRef = filename
					this.lightNonce = Date.now()
				}

				this.$emit('updated', { field, ref: filename })
			} catch (error) {
				this.uploadError = error?.response?.data?.message
					|| t('openbuilt', 'Upload failed — please try again')
			} finally {
				this.uploading = false
				if (variant === 'dark') {
					this.$refs.darkInput.value = ''
				} else {
					this.$refs.lightInput.value = ''
				}
			}
		},

		async removeLightIcon() {
			await this.removeIcon('light')
		},

		async removeDarkIcon() {
			await this.removeIcon('dark')
		},

		async removeIcon(variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const filename = variant === 'dark' ? 'app-icon-dark.svg' : 'app-icon.svg'
			const field = variant === 'dark' ? 'iconDark' : 'icon'

			try {
				// 1. Delete the file from OR.
				const deleteUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files/${filename}`,
				)
				await axios.delete(deleteUrl)

				// 2. Clear the ref on the Application.
				const patchUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}`,
				)
				await axios.put(patchUrl, { [field]: null })

				// 3. Update local state.
				if (variant === 'dark') {
					this.darkRef = null
					this.darkNonce = Date.now()
				} else {
					this.lightRef = null
					this.lightNonce = Date.now()
				}

				this.$emit('updated', { field, ref: null })
			} catch (error) {
				this.uploadError = error?.response?.data?.message
					|| t('openbuilt', 'Remove failed — please try again')
			} finally {
				this.uploading = false
			}
		},
	},
}
</script>

<style scoped>
.ob-icon-section {
	padding: 12px 0;
}

.ob-icon-section__heading {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 12px;
}

.ob-icon-section__row {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 10px;
	margin-bottom: 16px;
}

.ob-icon-section__label {
	width: 90px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.ob-icon-section__preview {
	width: 48px;
	height: 48px;
	border-radius: var(--border-radius, 4px);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.ob-icon-section__preview--light {
	background: #ffffff;
	border: 1px solid var(--color-border, #ddd);
}

.ob-icon-section__preview--dark {
	background: #1c1c1e;
	border: 1px solid var(--color-border, #ddd);
}

.ob-icon-section__preview-img {
	width: 32px;
	height: 32px;
	object-fit: contain;
}

.ob-icon-section__preview-empty {
	font-size: 18px;
	color: var(--color-text-maxcontrast, #888);
}

.ob-icon-section__file-input {
	display: none;
}

.ob-icon-section__file-label {
	cursor: pointer;
	padding: 4px 10px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-primary-element, #0082c9);
	color: #fff;
	font-size: 12px;
	user-select: none;
}

.ob-icon-section__file-label:has(input:disabled) {
	opacity: 0.6;
	cursor: not-allowed;
}

.ob-icon-section__remove-btn {
	padding: 4px 10px;
	border-radius: var(--border-radius, 4px);
	background: transparent;
	border: 1px solid var(--color-border, #ddd);
	font-size: 12px;
	cursor: pointer;
}

.ob-icon-section__remove-btn:disabled {
	opacity: 0.6;
	cursor: not-allowed;
}

.ob-icon-section__error {
	font-size: 11px;
	color: var(--color-error-text, #c00);
}

.ob-icon-section__global-error {
	font-size: 12px;
	color: var(--color-error-text, #c00);
	margin-top: 4px;
}
</style>
