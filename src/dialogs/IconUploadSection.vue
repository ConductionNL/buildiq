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
  -             — upload the SVG as JSON { name, content }; OR writes content verbatim
  -   - DELETE /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}/files/{filename}
  -             — remove the attached file
  -   - PATCH  /index.php/apps/openregister/api/objects/{register}/{schema}/{uuid}
  -             — partial-merge the icon / iconDark refs on the Application record
  -             (a PUT would replace the whole object and fail validation)
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
			 * @param {{icon?: {ref: string}, iconDark?: {ref: string}}} app - The
			 *   incoming `application` prop; only its two icon refs are mirrored into
			 *   local state, so the Remove buttons track the server record. Runs
			 *   immediately, and may receive `undefined` while the parent is still
			 *   loading the record.
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
		 * @param {Event} e - The `<img>` `error` event fired when the light-icon
		 *   preview URL 404s (no icon attached yet, or the app-icon route has not
		 *   picked the upload up); `e.target` is the image, which is hidden so the
		 *   broken-image glyph never shows.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		onLightPreviewError(e) {
			e.target.style.display = 'none'
		},
		/**
		 * Observed behaviour of `onDarkPreviewError` (retrofit annotation).
		 *
		 * @param {Event} e - The `<img>` `error` event for the dark-icon preview; same
		 *   contract as `onLightPreviewError`.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		onDarkPreviewError(e) {
			e.target.style.display = 'none'
		},

		/**
		 * Observed behaviour of `validateSvgFile` (retrofit annotation).
		 *
		 * @param {File|undefined} file - The picked file, or `undefined` when the user
		 *   dismissed the picker without choosing one.
		 * @return {boolean} `true` when a file was picked and its name ends in `.svg`.
		 *   Extension-only — the file's bytes and MIME type are not inspected here.
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
		 * @param {Event} event - `change` event from the light-icon file input;
		 *   `event.target.files[0]` is the picked SVG. A non-SVG pick is rejected
		 *   inline and the input is cleared so the same file can be re-picked.
		 * @return {Promise<void>}
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
		 * @param {Event} event - `change` event from the dark-icon file input; same
		 *   contract as `onLightFileChange`, routed to the `dark` variant.
		 * @return {Promise<void>}
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
		 * Observed behaviour of `uploadIcon` (retrofit annotation).
		 *
		 * Attaches the SVG to the Application in OpenRegister, points the matching ref
		 * field at it, then bumps the preview nonce and emits `updated`. Never throws:
		 * a failed request surfaces in `uploadError`.
		 *
		 * @param {File} file - The validated SVG; its text is read client-side and
		 *   POSTed as the file's `content`.
		 * @param {'light'|'dark'} variant - Which icon slot is being written. Picks
		 *   both the stored filename (`app-icon.svg` / `app-icon-dark.svg`) and the
		 *   Application field (`icon` / `iconDark`) — the filenames are fixed, so
		 *   re-uploading replaces the previous icon rather than accumulating files.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async uploadIcon(file, variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const filename = variant === 'dark' ? 'app-icon-dark.svg' : 'app-icon.svg'

			try {
				// 1. Upload the SVG to OR's files#create endpoint, which takes
				//    JSON { name, content } and writes the content verbatim.
				const content = await file.text()
				const uploadUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files`,
				)
				await axios.post(uploadUrl, { name: filename, content })

				// 2. PATCH (partial merge) the icon ref — a PUT would replace the
				//    whole object and fail validation on the missing name/slug.
				const field = variant === 'dark' ? 'iconDark' : 'icon'
				const patchUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}`,
				)
				await axios.patch(patchUrl, { [field]: { ref: filename } })

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
		 * Detaches the stored SVG from the Application and clears the ref field, then
		 * emits `updated` with a `null` ref. Never throws: a failed request surfaces in
		 * `uploadError`.
		 *
		 * @param {'light'|'dark'} variant - Which icon slot to clear; selects both the
		 *   attached filename to delete (`app-icon.svg` / `app-icon-dark.svg`) and the
		 *   Application field to null out (`icon` / `iconDark`).
		 * @return {Promise<void>}
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
				// 1. Delete the file from OR.
				const deleteUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}/files/${filename}`,
				)
				await axios.delete(deleteUrl)

				// 2. Clear the ref on the Application (partial merge, not replace).
				const patchUrl = generateUrl(
					`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}`,
				)
				await axios.patch(patchUrl, { [field]: null })

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

/* intentional: simulated light/dark canvas for icon preview — must NOT track the theme */
.ob-icon-section__preview--light {
	background: #ffffff;
	border: 1px solid var(--color-border, #ddd);
}

/* intentional: simulated light/dark canvas for icon preview — must NOT track the theme */
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
	color: var(--color-primary-element-text, #fff);
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
