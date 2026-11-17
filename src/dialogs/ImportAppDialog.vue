<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ImportAppDialog — import an app archive exported from another buildiq as a
  - new app owned by the maker. Picking the archive asks the server what it
  - holds (name, pages, schemas, records) without creating anything; the maker
  - then keeps or changes the slug, chooses whether the records come along, and
  - imports. A refused package shows the parser's code and the file at fault; a
  - taken slug asks for another one. Kept in its own file per the
  - modal-isolation gate.
  -
  - spec: openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('buildiq', 'Import app')"
		:noClose="busy"
		@closing="close">
		<div class="import-app">
			<p class="import-app__hint">
				{{ t('buildiq', 'Pick an app archive exported from buildiq. It becomes a new app that you own.') }}
			</p>
			<label class="import-app__file">
				<span>{{ t('buildiq', 'App archive') }}</span>
				<input
					type="file"
					accept=".zip,application/zip"
					:disabled="busy"
					data-testid="import-app-file"
					@change="onFile">
			</label>

			<div v-if="summary" class="import-app__summary" data-testid="import-app-summary">
				<p class="import-app__name">
					{{ summary.name }}
				</p>
				<ul>
					<li>{{ n('buildiq', '%n page', '%n pages', summary.pages) }}</li>
					<li>{{ n('buildiq', '%n schema', '%n schemas', summary.schemas) }}</li>
					<li>{{ n('buildiq', '%n record', '%n records', summary.records) }}</li>
				</ul>
			</div>

			<NcTextField
				v-if="summary"
				v-model="slug"
				:label="t('buildiq', 'Slug')"
				:disabled="busy"
				:helperText="t('buildiq', 'Lowercase letters, digits and hyphens. The app\'s address uses it.')" />

			<NcCheckboxRadioSwitch
				v-if="summary && summary.records > 0"
				v-model="importRecords"
				:disabled="busy">
				{{ t('buildiq', 'Import records') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="error" type="error" data-testid="import-app-error">
				{{ error }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="close">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !summary || slug.trim() === ''"
				data-testid="import-app-submit"
				@click="submit">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ busy ? t('buildiq', 'Importing…') : t('buildiq', 'Import') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'

/**
 * The message for a refused preview or import: a taken slug asks for another;
 * anything else names the code, the file at fault and the detail.
 *
 * @param {object} error The axios error.
 * @param {string} slug The slug that was asked for.
 * @return {string} The message.
 */
export function importErrorMessage(error, slug) {
	const response = error && error.response ? error.response : null
	const data = response && response.data ? response.data : {}
	if (response && response.status === 409) {
		return t('buildiq', 'The slug "{slug}" is already taken. Choose another slug.', { slug })
	}
	if (!data.error) {
		return t('buildiq', 'The archive could not be imported.')
	}
	let message = String(data.error)
	if (data.path) {
		message = t('buildiq', '{code} in {path}', { code: message, path: String(data.path) })
	}
	if (data.detail) {
		message = message + ': ' + String(data.detail)
	}
	return message
}

/**
 * A form with the archive, plus the given fields.
 *
 * @param {File} file The archive.
 * @param {object} fields More fields.
 * @return {FormData} The form.
 */
function archiveForm(file, fields = {}) {
	const form = new FormData()
	form.append('archive', file)
	Object.entries(fields).forEach(([key, value]) => form.append(key, value))
	return form
}

export default {
	name: 'ImportAppDialog',
	components: { NcDialog, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextField },
	props: {
		/** Whether the dialog is shown (bind with `v-model:open`). */
		open: { type: Boolean, default: false },
	},

	emits: ['update:open', 'imported'],
	data() {
		return {
			file: null,
			summary: null,
			slug: '',
			importRecords: false,
			busy: false,
			error: '',
		}
	},

	watch: {
		open(value) {
			if (value) {
				this.reset()
			}
		},
	},

	methods: {
		/**
		 * Forget the previous archive, summary and choices.
		 *
		 * @return {void}
		 */
		reset() {
			this.file = null
			this.summary = null
			this.slug = ''
			this.importRecords = false
			this.busy = false
			this.error = ''
		},

		/**
		 * Close the dialog, unless an import is running.
		 *
		 * @return {void}
		 */
		close() {
			if (!this.busy) {
				this.$emit('update:open', false)
			}
		},

		/**
		 * Read the picked archive's summary from the server; nothing is created.
		 *
		 * @param {Event} event The file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
		 */
		async onFile(event) {
			const files = event && event.target ? event.target.files : null
			this.file = files && files.length > 0 ? files[0] : null
			this.summary = null
			this.importRecords = false
			this.error = ''
			if (!this.file) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl('/apps/buildiq/api/applications/import/preview'),
					archiveForm(this.file),
				)
				this.summary = data
				this.slug = data.slug || ''
			} catch (error) {
				this.error = importErrorMessage(error, '')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Import the archive under the chosen slug, with its records when ticked.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-records-come-along-only-when-asked-req-bqic-003
		 */
		async submit() {
			if (!this.file || !this.summary) {
				return
			}
			this.busy = true
			this.error = ''
			const slug = this.slug.trim()
			try {
				const { data } = await axios.post(
					generateUrl('/apps/buildiq/api/applications/import'),
					archiveForm(this.file, { slug, records: this.importRecords ? '1' : '0' }),
				)
				this.busy = false
				this.$emit('imported', data)
				this.$emit('update:open', false)
			} catch (error) {
				this.error = importErrorMessage(error, slug)
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.import-app {
	padding: 16px 24px 8px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.import-app__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.import-app__file {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.import-app__summary ul {
	margin: 4px 0 0;
	padding-inline-start: 20px;
	list-style: disc;
}

.import-app__name {
	font-weight: bold;
	margin: 0;
}
</style>
