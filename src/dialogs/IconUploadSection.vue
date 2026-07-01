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
  -   - POST   /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}/filesMultipart
  -             — upload SVG as multipart/form-data (the `/files` endpoint expects
  -               name+content params, NOT a multipart file — use filesMultipart)
  -   - DELETE /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}/files/{filename}
  -             — remove the attached file
  -   - PUT    /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}
  -             — patch icon / iconDark refs on the Application record
  -
  - REQ-OBICON-004 / openbuild-nextcloud-nav
  -->
<template>
	<div class="ob-icon-section">
		<h3 class="ob-icon-section__heading">
			{{ t('openbuild', 'App icon') }}
		</h3>

		<!-- Light icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('openbuild', 'Light icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--light">
				<img
					v-if="iconLightUrl"
					:src="iconLightUrl"
					:alt="t('openbuild', 'Light icon preview')"
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
				<span>{{ t('openbuild', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="lightRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeLightIcon">
				{{ t('openbuild', 'Remove') }}
			</button>
			<span v-if="lightError" class="ob-icon-section__error">{{ lightError }}</span>
		</div>

		<!-- Dark icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('openbuild', 'Dark icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--dark">
				<img
					v-if="iconDarkUrl"
					:src="iconDarkUrl"
					:alt="t('openbuild', 'Dark icon preview')"
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
				<span>{{ t('openbuild', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="darkRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeDarkIcon">
				{{ t('openbuild', 'Remove') }}
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

const REGISTER = 'openbuild'
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
		/**
		 * Observed behaviour of `objectUuid` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		objectUuid() {
			const self = this.application['@self'] || {}
			return self.id || this.application.uuid || this.application.id || ''
		},
		/**
		 * Observed behaviour of `iconLightUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		iconLightUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/openbuild/icons/${this.application.slug}.svg?v=${this.lightNonce}`
		},
		/**
		 * Observed behaviour of `iconDarkUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		iconDarkUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/openbuild/icons/${this.application.slug}-dark.svg?v=${this.darkNonce}`
		},
	},

	watch: {
		application: {
			immediate: true,
			/**
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
			 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
			 */
			handler(app) {
				this.lightRef = app?.icon?.ref || null
				this.darkRef = app?.iconDark?.ref || null
			},
		},
	},

	methods: {
		/**
		 * Observed behaviour of `onLightPreviewError` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		onLightPreviewError(e) {
			e.target.style.display = 'none'
		},
		/**
		 * Observed behaviour of `onDarkPreviewError` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		onDarkPreviewError(e) {
			e.target.style.display = 'none'
		},

		/**
		 * Observed behaviour of `validateSvgFile` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		validateSvgFile(file) {
			if (!file) return false
			if (!file.name.toLowerCase().endsWith('.svg')) {
				return false
			}
			return true
		},

		/**
		 * Observed behaviour of `onLightFileChange` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async onLightFileChange(event) {
			this.lightError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.lightError = t('openbuild', 'Only .svg files are accepted')
				this.$refs.lightInput.value = ''
				return
			}
			await this.uploadIcon(file, 'light')
		},

		/**
		 * Observed behaviour of `onDarkFileChange` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async onDarkFileChange(event) {
			this.darkError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.darkError = t('openbuild', 'Only .svg files are accepted')
				this.$refs.darkInput.value = ''
				return
			}
			await this.uploadIcon(file, 'dark')
		},

		/**
		 * Persist a single icon field (`icon` / `iconDark`) on the Application.
		 *
		 * OR's object PUT is a full REPLACE that revalidates required fields, so a
		 * partial body like `{ icon: { ref } }` 400s with "required properties
		 * (slug, name) are missing". We also can't just spread the `application`
		 * prop: it isn't refreshed after a save, so a second upload would clobber
		 * the sibling ref set moments earlier. GET the current object fresh, merge
		 * the one field, and PUT the whole thing back.
		 *
		 * @param {string}      field The Application field to set (`icon` | `iconDark`).
		 * @param {object|null} value The new value (`{ ref }`), or null to clear it.
		 * @return {Promise<void>}
		 */
		async patchIconField(field, value) {
			const objectUrl = generateUrl(
				`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}`,
			)
			const { data } = await axios.get(objectUrl)
			const body = (data && data.results) ? data.results : data
			await axios.put(objectUrl, { ...body, [field]: value })
		},
		/**
		 * Observed behaviour of `uploadIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async uploadIcon(file, variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const filename = variant === 'dark' ? 'app-icon-dark.svg' : 'app-icon.svg'

			try {
				// 1. Upload the file to OR's files-attached-to-object endpoint.
				const formData = new FormData()
				formData.append('file', file, filename)

				// Use the multipart endpoint: the plain `/files` (files#create) route
				// expects `name` + `content` params, not an uploaded file, and 400s
				// with "File name is required (use name or filename)". files#createMultipart
				// reads the `file` field via extractUploadedFiles().
				const uploadUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/filesMultipart`,
				)
				await axios.post(uploadUrl, formData, {
					headers: { 'Content-Type': 'multipart/form-data' },
				})

				// 2. Patch the Application record with the new icon ref.
				const field = variant === 'dark' ? 'iconDark' : 'icon'
				await this.patchIconField(field, { ref: filename })

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
					|| t('openbuild', 'Upload failed — please try again')
			} finally {
				this.uploading = false
				if (variant === 'dark') {
					this.$refs.darkInput.value = ''
				} else {
					this.$refs.lightInput.value = ''
				}
			}
		},

		/**
		 * Observed behaviour of `removeLightIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async removeLightIcon() {
			await this.removeIcon('light')
		},

		/**
		 * Observed behaviour of `removeDarkIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async removeDarkIcon() {
			await this.removeIcon('dark')
		},

		/**
		 * Observed behaviour of `removeIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async removeIcon(variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const filename = variant === 'dark' ? 'app-icon-dark.svg' : 'app-icon.svg'
			const field = variant === 'dark' ? 'iconDark' : 'icon'

			try {
				// 1. Delete the file from OR. The delete route requires the numeric
				//    file id, NOT the filename (deleting by name falls through to the
				//    app HTML shell), so resolve the id from the files index first.
				const filesUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files`,
				)
				const { data } = await axios.get(filesUrl)
				const files = Array.isArray(data) ? data : (data && data.results) || []
				const match = files.find((f) => (f.title || f.name) === filename)
				if (match && match.id) {
					await axios.delete(generateUrl(
						`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files/${match.id}`,
					))
				}

				// 2. Clear the ref on the Application.
				await this.patchIconField(field, null)

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
					|| t('openbuild', 'Remove failed — please try again')
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
