<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CopyAppDialog: start a variant of an app (REQ-BQCP-001). Asks a name and
  - a slug and calls POST /api/applications/{slug}/copy, which gives the copy
  - its own register and a copy of the source's schemas and current pages,
  - without records. Emits `copied` with the new app.
  -
  - @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
  -->
<template>
	<NcModal
		v-if="open"
		size="normal"
		labelId="copy-app-dialog-title"
		@close="close">
		<div class="copy-app-dialog">
			<h2 id="copy-app-dialog-title">
				{{ t('buildiq', 'Copy app') }}
			</h2>
			<p class="copy-app-dialog__summary">
				{{
					t(
						'buildiq',
						'The copy gets the pages and data model of {name}, and none of its records. Changing the copy leaves {name} as it is.',
						{ name: sourceName },
					)
				}}
			</p>
			<NcTextField
				:modelValue="localName"
				:label="t('buildiq', 'Application name')"
				@update:modelValue="onNameInput" />
			<NcTextField
				:modelValue="localSlug"
				:label="t('buildiq', 'Slug (kebab-case, max 32 chars)')"
				@update:modelValue="onSlugInput" />
			<p v-if="error" class="copy-app-dialog__error" role="alert">
				{{ error }}
			</p>
			<div class="copy-app-dialog__actions">
				<NcButton @click="close">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canSubmit || submitting"
					@click="submit">
					{{
						submitting
							? t('buildiq', 'Copying…')
							: t('buildiq', 'Copy app')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

/**
 * A kebab-case slug for a name, at most 32 characters.
 *
 * @param {string} name The name.
 * @return {string}
 */
function slugFor(name) {
	return String(name || '')
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 32)
		.replace(/-+$/g, '')
}

export default {
	name: 'CopyAppDialog',
	components: { NcButton, NcModal, NcTextField },
	props: {
		open: { type: Boolean, default: false },
		// The source app: `{ slug, name }`.
		application: { type: Object, required: true },
	},

	emits: ['close', 'copied'],

	data() {
		const name = t('buildiq', 'Copy of {name}', {
			name: this.application.name || this.application.slug,
		})
		return {
			localName: name,
			localSlug: slugFor(name),
			slugEdited: false,
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * The source app's name, for the summary.
		 *
		 * @return {string}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
		 */
		sourceName() {
			return this.application.name || this.application.slug
		},

		/**
		 * Whether the name and slug can be sent.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
		 */
		canSubmit() {
			return (
				this.localName.trim() !== ''
				&& /^[a-z0-9]+(-[a-z0-9]+)*$/.test(this.localSlug)
				&& this.localSlug.length <= 32
			)
		},
	},

	methods: {
		/**
		 * Take a new name; the slug follows until it is edited.
		 *
		 * @param {string} value The name.
		 * @return {void}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
		 */
		onNameInput(value) {
			this.localName = value
			if (!this.slugEdited) {
				this.localSlug = slugFor(value)
			}
		},

		/**
		 * Take a slug typed by the maker.
		 *
		 * @param {string} value The slug.
		 * @return {void}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
		 */
		onSlugInput(value) {
			this.slugEdited = true
			this.localSlug = value
		},

		/**
		 * Ask the server for the copy.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-copying-an-app-has-the-gates-of-cloning-a-template-req-bqcp-002
		 */
		async submit() {
			if (!this.canSubmit || this.submitting) {
				return
			}
			this.submitting = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/buildiq/api/applications/'
							+ encodeURIComponent(this.application.slug)
							+ '/copy',
					),
					{ name: this.localName.trim(), slug: this.localSlug },
				)
				this.$emit('copied', data)
			} catch (e) {
				this.error = this.messageFor(e && e.response ? e.response.status : 0)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * What to tell the maker when the copy is refused.
		 *
		 * @param {number} status The HTTP status.
		 * @return {string}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-copying-an-app-has-the-gates-of-cloning-a-template-req-bqcp-002
		 */
		messageFor(status) {
			if (status === 409) {
				return t('buildiq', 'That slug is taken. Pick another one.')
			}
			if (status === 403) {
				return t(
					'buildiq',
					'Only an administrator who owns or edits this app can copy it.',
				)
			}
			if (status === 429) {
				return t(
					'buildiq',
					'You copied or cloned several apps in a short time. Try again later.',
				)
			}
			return t('buildiq', 'The app could not be copied. Try again later.')
		},

		/**
		 * Close without copying.
		 *
		 * @return {void}
		 * @spec openspec/changes/apps-copy-app-and-page/specs/copy-app-page-and-form/spec.md#requirement-a-maker-copies-an-app-req-bqcp-001
		 */
		close() {
			this.error = ''
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.copy-app-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
}

.copy-app-dialog__error {
	color: var(--color-error-text, var(--color-error));
}

.copy-app-dialog__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
