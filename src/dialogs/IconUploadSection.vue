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
  - REQ-OBICON-004 / buildiq-nextcloud-nav
  -->
<template>
	<div class="ob-icon-section">
		<h3 class="ob-icon-section__heading">
			{{ t('buildiq', 'App icon') }}
		</h3>

		<!-- Light icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('buildiq', 'Light icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--light">
				<img
					v-if="iconLightUrl"
					:src="iconLightUrl"
					:alt="t('buildiq', 'Light icon preview')"
					class="ob-icon-section__preview-img"
					@error="onLightPreviewError" />
				<span v-else class="ob-icon-section__preview-empty">—</span>
			</div>
			<label class="ob-icon-section__file-label">
				<input
					ref="lightInput"
					type="file"
					accept=".svg"
					class="ob-icon-section__file-input"
					:disabled="uploading"
					@change="onLightFileChange" />
				<span>{{ t('buildiq', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="lightRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeLightIcon">
				{{ t('buildiq', 'Remove') }}
			</button>
			<span v-if="lightError" class="ob-icon-section__error">{{
				lightError
			}}</span>
		</div>

		<!-- Dark icon -->
		<div class="ob-icon-section__row">
			<div class="ob-icon-section__label">
				{{ t('buildiq', 'Dark icon') }}
			</div>
			<div class="ob-icon-section__preview ob-icon-section__preview--dark">
				<img
					v-if="iconDarkUrl"
					:src="iconDarkUrl"
					:alt="t('buildiq', 'Dark icon preview')"
					class="ob-icon-section__preview-img"
					@error="onDarkPreviewError" />
				<span v-else class="ob-icon-section__preview-empty">—</span>
			</div>
			<label class="ob-icon-section__file-label">
				<input
					ref="darkInput"
					type="file"
					accept=".svg"
					class="ob-icon-section__file-input"
					:disabled="uploading"
					@change="onDarkFileChange" />
				<span>{{ t('buildiq', 'Upload SVG') }}</span>
			</label>
			<button
				v-if="darkRef"
				class="ob-icon-section__remove-btn"
				:disabled="uploading"
				@click="removeDarkIcon">
				{{ t('buildiq', 'Remove') }}
			</button>
			<span v-if="darkError" class="ob-icon-section__error">{{
				darkError
			}}</span>
		</div>

		<p v-if="uploadError" class="ob-icon-section__global-error">
			{{ uploadError }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER = 'buildiq'
const SCHEMA = 'built-app'

/**
 * Per-variant icon slot: the stored filename, the Application field, the
 * local ref and nonce keys, and the file input ref. The filenames are fixed,
 * so re-uploading replaces the previous icon rather than accumulating files.
 */
const ICON_SLOTS = {
	light: {
		filename: 'app-icon.svg',
		field: 'icon',
		refKey: 'lightRef',
		nonceKey: 'lightNonce',
		input: 'lightInput',
	},
	dark: {
		filename: 'app-icon-dark.svg',
		field: 'iconDark',
		refKey: 'darkRef',
		nonceKey: 'darkNonce',
		input: 'darkInput',
	},
}

/**
 * The icon slot of a variant; anything but `dark` is the light slot.
 *
 * @param {string} variant - `light` or `dark`.
 * @return {object}
 */
function iconSlot(variant) {
	return variant === 'dark' ? ICON_SLOTS.dark : ICON_SLOTS.light
}

/**
 * The generic message of a failed upload.
 *
 * @return {string}
 */
function uploadFailedText() {
	return t('buildiq', 'Upload failed — please try again')
}

/**
 * The generic message of a failed removal.
 *
 * @return {string}
 */
function removeFailedText() {
	return t('buildiq', 'Remove failed — please try again')
}

/**
 * The server's message of a failed request, else the generic one.
 *
 * @param {Error} error - The rejected request.
 * @param {function(): string} fallback - Returns the generic message.
 * @return {string}
 */
function requestFailure(error, fallback) {
	return error?.response?.data?.message || fallback()
}

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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		objectUuid() {
			const self = this.application['@self'] || {}
			return self.id || this.application.uuid || this.application.id || ''
		},

		/**
		 * Observed behaviour of `iconLightUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		iconLightUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/buildiq/icons/${this.application.slug}.svg?v=${this.lightNonce}`
		},

		/**
		 * Observed behaviour of `iconDarkUrl` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		iconDarkUrl() {
			if (!this.objectUuid) return null
			return `/index.php/apps/buildiq/icons/${this.application.slug}-dark.svg?v=${this.darkNonce}`
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
			 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async onLightFileChange(event) {
			this.lightError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.lightError = t('buildiq', 'Only .svg files are accepted')
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async onDarkFileChange(event) {
			this.darkError = ''
			const file = event.target.files?.[0]
			if (!this.validateSvgFile(file)) {
				this.darkError = t('buildiq', 'Only .svg files are accepted')
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
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async uploadIcon(file, variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const slot = iconSlot(variant)

			try {
				await this.storeIcon(file, slot)
			} catch (error) {
				this.uploadError = requestFailure(error, uploadFailedText)
			} finally {
				this.uploading = false
				this.$refs[slot.input].value = ''
			}
		},

		/**
		 * Attach the SVG to the Application, point the slot's field at it,
		 * then refresh the preview and emit `updated`.
		 *
		 * @param {File} file - The validated SVG.
		 * @param {object} slot - The icon slot being written.
		 * @return {Promise<void>}
		 */
		async storeIcon(file, slot) {
			// 1. Upload the SVG to OR's files#create endpoint, which takes
			//    JSON { name, content } and writes the content verbatim.
			const content = await file.text()
			await axios.post(this.objectUrl('/files'), {
				name: slot.filename,
				content,
			})

			// 2. PATCH (partial merge) the icon ref — a PUT would replace the
			//    whole object and fail validation on the missing name/slug.
			await axios.patch(this.objectUrl(''), {
				[slot.field]: { ref: slot.filename },
			})

			// 3. Update local state and notify parent.
			this.showIconRef(slot, slot.filename)
			this.$emit('updated', { field: slot.field, ref: slot.filename })
		},

		/**
		 * The OpenRegister URL of this Application, plus a sub-path.
		 *
		 * @param {string} suffix - The sub-path (`''`, `/files`, ...).
		 * @return {string}
		 */
		objectUrl(suffix) {
			return generateUrl(
				`/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${this.objectUuid}${suffix}`,
			)
		},

		/**
		 * Set a slot's local ref and bump its preview nonce.
		 *
		 * @param {object} slot - The icon slot.
		 * @param {?string} ref - The new ref, or null when removed.
		 * @return {void}
		 */
		showIconRef(slot, ref) {
			this[slot.refKey] = ref
			this[slot.nonceKey] = Date.now()
		},

		/**
		 * Observed behaviour of `removeLightIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
		 */
		async removeLightIcon() {
			await this.removeIcon('light')
		},

		/**
		 * Observed behaviour of `removeDarkIcon` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-creation-wizard-ui/tasks.md#task-4
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
		 * ⚠️ THE DELETE IS ADDRESSED BY NUMERIC FILE ID, NOT BY FILENAME.
		 *
		 * This method used to call
		 * `DELETE .../objects/{register}/{schema}/{uuid}/files/app-icon-dark.svg`.
		 * OpenRegister's route for that verb is
		 *
		 *   ['name' => 'files#delete',
		 *    'url'  => '/api/objects/{register}/{schema}/{id}/files/{fileId}',
		 *    'verb' => 'DELETE',
		 *    'requirements' => ['id' => '[^/]+', 'fileId' => '\d+']]
		 *
		 * — `fileId` is constrained to `\d+`, so a filename never matched the
		 * route at all and Nextcloud answered its HTML **404** page. The
		 * `catch` below then painted the generic "Remove failed" string, so the
		 * button looked implemented and could never work. Measured both ways on
		 * a live instance: DELETE by filename → 404; DELETE by the numeric id
		 * from `GET .../files` → 200 and the attachment is gone.
		 *
		 * The id is therefore resolved from the object's own file index, where
		 * each entry carries `{ id: <int>, title: '<filename>' }`.
		 *
		 * @param {'light'|'dark'} variant - Which icon slot to clear; selects both the
		 *   attached filename to delete (`app-icon.svg` / `app-icon-dark.svg`) and the
		 *   Application field to null out (`icon` / `iconDark`).
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/app-icon-management/spec.md#user-removes-the-dark-icon
		 */
		async removeIcon(variant) {
			if (!this.objectUuid) return
			this.uploading = true
			this.uploadError = ''
			const slot = iconSlot(variant)

			try {
				await this.detachIconFile(slot)
				await this.clearIconRef(slot)
			} catch (error) {
				this.uploadError = requestFailure(error, removeFailedText)
			} finally {
				this.uploading = false
			}
		},

		/**
		 * Resolve the slot's attachment by its NUMERIC id, then delete by it.
		 *
		 * @param {object} slot - The icon slot being cleared.
		 * @return {Promise<void>}
		 */
		async detachIconFile(slot) {
			const listed = await axios.get(this.objectUrl('/files'))
			const attachment = (listed?.data?.results || []).find(
				(f) => f?.title === slot.filename,
			)

			if (!attachment?.id) {
				// The ref points at a file OR no longer holds. Nothing to
				// detach — fall through and clear the ref so the record stops
				// advertising an attachment that is not there.
				// eslint-disable-next-line no-console
				console.warn(
					`[buildiq] no OR attachment named ${slot.filename}; clearing the ref only`,
				)
				return
			}
			await axios.delete(this.objectUrl(`/files/${attachment.id}`))
		},

		/**
		 * Clear the slot's ref on the Application (partial merge, not
		 * replace), then locally, and emit `updated`.
		 *
		 * @param {object} slot - The icon slot being cleared.
		 * @return {Promise<void>}
		 */
		async clearIconRef(slot) {
			await axios.patch(this.objectUrl(''), { [slot.field]: null })
			this.showIconRef(slot, null)
			this.$emit('updated', { field: slot.field, ref: null })
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
