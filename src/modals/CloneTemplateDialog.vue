<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcModal v-if="open" size="normal" @close="onClose">
		<div class="clone-dialog">
			<h2>{{ remote ? t('openbuild', 'Install template') : t('openbuild', 'Use this template') }}</h2>
			<p v-if="template" class="clone-dialog__summary">
				{{ remote ? t('openbuild', 'Install a new application from') : t('openbuild', 'Create a new application from') }}
				<strong>{{ resolvedTitle }}</strong>.
				{{ t('openbuild', 'You can edit everything after cloning.') }}
			</p>
			<NcTextField
				:value="localName"
				:label="t('openbuild', 'Application name')"
				:placeholder="t('openbuild', 'My permits')"
				@update:value="localName = $event" />
			<NcTextField
				:value="localSlug"
				:label="t('openbuild', 'Slug (kebab-case, max 32 chars)')"
				:placeholder="t('openbuild', 'my-permits')"
				@update:value="localSlug = $event" />
			<p v-if="error" class="clone-dialog__error" role="alert">
				{{ error }}
			</p>
			<div class="clone-dialog__actions">
				<NcButton @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
					{{ submitLabel }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'CloneTemplateDialog',
	components: { NcButton, NcModal, NcTextField },
	props: {
		open: { type: Boolean, default: false },
		template: { type: Object, default: null },
		// When true the dialog installs a REMOTE store template via the
		// store install endpoint instead of emitting to the local clone path.
		remote: { type: Boolean, default: false },
		// The remote template slug to install (the {slug} path segment).
		remoteSlug: { type: String, default: '' },
	},
	emits: ['close', 'submit', 'installed'],
	data() {
		return {
			localName: '',
			localSlug: '',
			error: '',
			submitting: false,
		}
	},
	computed: {
		/**
		 * Observed behaviour of `resolvedTitle` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		resolvedTitle() {
			if (!this.template) return ''
			return t('openbuild', this.template.title || this.template.slug)
		},
		/**
		 * Observed behaviour of `canSubmit` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		canSubmit() {
			return this.localName.trim().length > 0
				&& /^[a-z0-9]+(-[a-z0-9]+)*$/.test(this.localSlug)
				&& this.localSlug.length <= 32
		},
		/**
		 * Label for the primary action button — installing for a remote store
		 * template, cloning for a local built-in template.
		 *
		 * @return {string} The translated button label.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		submitLabel() {
			if (this.remote) {
				return this.submitting ? t('openbuild', 'Installing…') : t('openbuild', 'Install')
			}
			return this.submitting ? t('openbuild', 'Cloning…') : t('openbuild', 'Clone template')
		},
	},
	watch: {
		/**
		 * Observed behaviour of `open` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		open(value) {
			if (value) {
				// Prefill from the seeded template: name from title, slug suggested
				// from the (remote) template slug, sanitised to kebab-case.
				const tpl = this.template || {}
				this.localName = tpl.title || tpl.slug || ''
				this.localSlug = this.suggestSlug(tpl.slug || tpl.title || '')
				this.error = ''
				this.submitting = false
			}
		},
	},
	methods: {
		/**
		 * Observed behaviour of `onClose` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
		/**
		 * Observed behaviour of `submit` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		async submit() {
			if (!this.canSubmit) {
				this.error = t('openbuild', 'Provide a name and a kebab-case slug (max 32 chars).')
				return
			}
			const payload = { name: this.localName.trim(), slug: this.localSlug.trim() }
			this.submitting = true
			this.error = ''
			if (this.remote) {
				await this.installRemote(payload)
				return
			}
			try {
				await this.$emit('submit', payload)
			} catch (e) {
				this.error = e?.message || t('openbuild', 'Clone failed.')
				this.submitting = false
			}
		},
		/**
		 * Install a remote store template via the backend store install
		 * endpoint, then emit `installed` with the created application so the
		 * parent can redirect to the new app's editor.
		 *
		 * @param {object} payload The new app `{name, slug}`.
		 * @return {Promise<void>} Resolves once the request settles.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		async installRemote(payload) {
			try {
				const url = generateUrl(
					'/apps/openbuild/api/store/templates/{slug}/install',
					{ slug: this.remoteSlug },
				)
				const resp = await axios.post(url, payload)
				this.$emit('installed', resp.data)
				this.$emit('close')
			} catch (e) {
				const data = e?.response?.data
				this.error = data?.detail || data?.error || e?.message || t('openbuild', 'Install failed.')
				this.submitting = false
			}
		},
		/**
		 * Suggest a kebab-case slug from an arbitrary source string.
		 *
		 * @param {string} source The source string (remote slug or title).
		 * @return {string} A kebab-case slug, max 32 chars.
		 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
		 */
		suggestSlug(source) {
			return String(source || '')
				.toLowerCase()
				.replace(/[^a-z0-9]+/g, '-')
				.replace(/^-+|-+$/g, '')
				.slice(0, 32)
				.replace(/-+$/g, '')
		},
		/**
		 * Observed behaviour of `setError` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		setError(message) {
			this.error = message
			this.submitting = false
		},
	},
}
</script>

<style scoped>
.clone-dialog {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.clone-dialog__summary {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.clone-dialog__error {
	color: var(--color-error);
	margin: 4px 0 0 0;
}

.clone-dialog__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 12px;
}
</style>
