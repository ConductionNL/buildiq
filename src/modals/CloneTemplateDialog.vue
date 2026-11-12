<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcModal
		v-if="open"
		size="normal"
		labelId="clone-template-dialog-title"
		@close="onClose">
		<div class="clone-dialog">
			<h2 id="clone-template-dialog-title">
				{{ dialogHeading }}
			</h2>
			<p v-if="template" class="clone-dialog__summary">
				{{ dialogSummaryLead }}
				<strong>{{ resolvedTitle }}</strong
				>.
				{{ t('buildiq', 'You can edit everything after cloning.') }}
			</p>
			<NcTextField
				:modelValue="localName"
				:label="t('buildiq', 'Application name')"
				:placeholder="t('buildiq', 'My permits')"
				@update:modelValue="onNameInput" />
			<NcTextField
				:modelValue="localSlug"
				:label="t('buildiq', 'Slug (kebab-case, max 32 chars)')"
				:placeholder="t('buildiq', 'my-permits')"
				@update:modelValue="onSlugInput" />
			<NcTextField
				v-if="isLocal"
				:modelValue="localDescription"
				:label="t('buildiq', 'Description (optional)')"
				:placeholder="t('buildiq', 'What the app is for')"
				@update:modelValue="localDescription = $event" />
			<p v-if="error" class="clone-dialog__error" role="alert">
				{{ error }}
			</p>
			<div class="clone-dialog__actions">
				<NcButton @click="onClose">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="!canSubmit || submitting"
					@click="submit">
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

/**
 * The name a template suggests: its title, else its slug.
 *
 * @param {object} tpl The template.
 * @return {string} The name.
 */
function templateName(tpl) {
	return tpl.title || tpl.slug || ''
}

/**
 * What the suggested slug is made from: the template's slug, else its title.
 *
 * @param {object} tpl The template.
 * @return {string} The source string.
 */
function templateSlugSource(tpl) {
	return tpl.slug || tpl.title || ''
}

/**
 * The GitHub install request body: the repository, the new app, and the ref
 * when the repository names one.
 *
 * @param {{owner: string, repo: string, ref?: string}} repo The repository.
 * @param {{name: string, slug: string}} payload The new app.
 * @return {object} The body.
 */
function githubInstallBody(repo, payload) {
	const body = {
		owner: repo.owner,
		repo: repo.repo,
		name: payload.name,
		slug: payload.slug,
	}
	if (repo.ref) {
		body.ref = repo.ref
	}
	return body
}

/**
 * What to say when an install failed: the server's detail or error, the
 * request's message, or a generic line.
 *
 * @param {Error|object|null} e The failure.
 * @return {string} The message.
 */
function installFailureMessage(e) {
	const data = e?.response?.data
	return (
		data?.detail
		|| data?.error
		|| e?.message
		|| t('buildiq', 'Install failed.')
	)
}

/**
 * The install failure message, naming the offending file when the GitHub
 * install endpoint returned one (it returns a generic-but-actionable error
 * carrying the parser error code + offending file path).
 *
 * @param {Error|object|null} e The failure.
 * @return {string} The message.
 */
function githubInstallFailureMessage(e) {
	const data = e?.response?.data
	const file = data?.file || data?.path
	const base = installFailureMessage(e)
	return file
		? t('buildiq', '{message} (in {file})', {
				message: base,
				file,
			})
		: base
}

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
		// When true the dialog installs a GitHub-shop app via the GitHub shop
		// install endpoint (github-shop-catalogue) instead of the local clone
		// path or the remote store path.
		github: { type: Boolean, default: false },
		// The GitHub repo identity to install `{ owner, repo, ref? }`.
		githubRepo: { type: Object, default: null },
	},

	emits: ['close', 'submit', 'installed'],
	data() {
		return {
			localName: '',
			localSlug: '',
			localDescription: '',
			// True once the user typed in the slug field. Until then the slug
			// follows the name.
			slugEdited: false,
			error: '',
			submitting: false,
		}
	},

	computed: {
		/**
		 * Whether the dialog clones a built-in template (neither a remote
		 * store template nor a GitHub app).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/template-catalogue-ui/spec.md
		 */
		isLocal() {
			return !this.remote && !this.github
		},

		/**
		 * Title shown in the dialog heading and used as the NcModal `name`
		 * (required for accessibility — provides the modal's accessible label).
		 *
		 * @return {string} The translated dialog title.
		 *
		 * @spec openspec/specs/application-creation-wizard/spec.md
		 */
		dialogTitle() {
			return this.remote
				? t('buildiq', 'Install template')
				: t('buildiq', 'Use this template')
		},

		/**
		 * Observed behaviour of `resolvedTitle` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		resolvedTitle() {
			if (!this.template) return ''
			return t('buildiq', this.template.title || this.template.slug)
		},

		/**
		 * Observed behaviour of `canSubmit` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		canSubmit() {
			return (
				this.localName.trim().length > 0
				&& /^[a-z0-9]+(-[a-z0-9]+)*$/.test(this.localSlug)
				&& this.localSlug.length <= 32
			)
		},

		/**
		 * Label for the primary action button — installing for a remote store
		 * template, cloning for a local built-in template.
		 *
		 * @return {string} The translated button label.
		 * @spec openspec/specs/openbuild-remote-template-store/spec.md
		 */
		submitLabel() {
			if (this.remote || this.github) {
				return this.submitting
					? t('buildiq', 'Installing…')
					: t('buildiq', 'Install')
			}
			return this.submitting
				? t('buildiq', 'Creating…')
				: t('buildiq', 'Create')
		},

		/**
		 * Modal heading — install wording for a remote store / GitHub app,
		 * clone wording for a local template.
		 *
		 * @return {string} The translated heading.
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		dialogHeading() {
			if (this.github) {
				return t('buildiq', 'Install app from GitHub')
			}
			return this.remote
				? t('buildiq', 'Install template')
				: t('buildiq', 'Use this template')
		},

		/**
		 * Lead-in sentence of the summary line, matching the install/clone verb.
		 *
		 * @return {string} The translated lead-in.
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		dialogSummaryLead() {
			if (this.remote || this.github) {
				return t('buildiq', 'Install a new application from')
			}
			return t('buildiq', 'Create a new application from')
		},
	},

	watch: {
		/**
		 * Observed behaviour of `open` (retrofit annotation).
		 *
		 * @param {boolean} value - The dialog's new `open` state. Opening re-seeds the
		 *   name/slug fields from the selected template and clears any error left over
		 *   from a previous attempt; closing is ignored, so the fields survive until
		 *   the next open.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		open(value) {
			if (value) {
				this.seedFromTemplate()
			}
		},
	},

	methods: {
		/**
		 * Prefill from the seeded template: name from title, slug suggested
		 * from the (remote) template slug, sanitised to kebab-case; clear the
		 * description, the slug-edited flag and any earlier error.
		 *
		 * @return {void}
		 */
		seedFromTemplate() {
			const tpl = this.template || {}
			this.localName = templateName(tpl)
			this.localSlug = this.suggestSlug(templateSlugSource(tpl))
			this.localDescription = ''
			this.slugEdited = false
			this.error = ''
			this.submitting = false
		},

		/**
		 * Name field input: store it, and let the slug follow until the user
		 * has typed in the slug field.
		 *
		 * @param {string} value The new name.
		 * @return {void}
		 * @spec openspec/specs/template-catalogue-ui/spec.md
		 */
		onNameInput(value) {
			this.localName = value
			if (!this.slugEdited) {
				this.localSlug = this.suggestSlug(value)
			}
		},

		/**
		 * Slug field input: from now on the slug stays as typed.
		 *
		 * @param {string} value The new slug.
		 * @return {void}
		 * @spec openspec/specs/template-catalogue-ui/spec.md
		 */
		onSlugInput(value) {
			this.localSlug = value
			this.slugEdited = true
		},

		/**
		 * Observed behaviour of `onClose` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},

		/**
		 * Observed behaviour of `submit` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
		 */
		async submit() {
			if (!this.canSubmit) {
				this.rejectIncomplete()
				return
			}
			const payload = this.newAppPayload()
			this.submitting = true
			this.error = ''
			await this.installOrClone(payload)
		},

		/**
		 * Say what a valid name and slug look like.
		 *
		 * @return {void}
		 */
		rejectIncomplete() {
			this.error = t(
				'buildiq',
				'Provide a name and a kebab-case slug (max 32 chars).',
			)
		},

		/**
		 * The new app `{name, slug}`, trimmed, plus the description for a local
		 * template.
		 *
		 * @return {{name: string, slug: string, description?: string}} The payload.
		 */
		newAppPayload() {
			const payload = {
				name: this.localName.trim(),
				slug: this.localSlug.trim(),
			}
			if (this.isLocal) {
				payload.description = this.localDescription.trim()
			}
			return payload
		},

		/**
		 * Install from GitHub, install from the store, or hand a local clone to
		 * the parent.
		 *
		 * @param {object} payload The new app.
		 * @return {Promise<void>} Resolves once the request settles.
		 */
		async installOrClone(payload) {
			if (this.github) {
				await this.installGithub(payload)
				return
			}
			if (this.remote) {
				await this.installRemote(payload)
				return
			}
			await this.emitClone(payload)
		},

		/**
		 * Hand a local clone to the parent through `submit`.
		 *
		 * @param {object} payload The new app.
		 * @return {Promise<void>} Resolves once the parent's handler settles.
		 */
		async emitClone(payload) {
			try {
				await this.$emit('submit', payload)
			} catch (e) {
				this.error = e?.message || t('buildiq', 'Clone failed.')
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
		 * @spec openspec/specs/openbuild-remote-template-store/spec.md
		 */
		async installRemote(payload) {
			try {
				const url = generateUrl(
					'/apps/buildiq/api/store/templates/{slug}/install',
					{ slug: this.remoteSlug },
				)
				const resp = await axios.post(url, payload)
				this.$emit('installed', resp.data)
				this.$emit('close')
			} catch (e) {
				this.error = installFailureMessage(e)
				this.submitting = false
			}
		},

		/**
		 * Install a GitHub-shop app via the GitHub shop install endpoint, then
		 * emit `installed` with the created application so the parent can redirect
		 * to the new app's editor. A strict-parse failure returned by the endpoint
		 * is surfaced in the dialog naming the offending file, creating nothing.
		 *
		 * @param {object} payload The new app `{name, slug}`.
		 * @return {Promise<void>} Resolves once the request settles.
		 * @spec openspec/changes/github-shop-catalogue/specs/template-catalogue-ui/spec.md
		 */
		async installGithub(payload) {
			const repo = this.githubRepo || {}
			if (!repo.owner || !repo.repo) {
				this.rejectMissingRepository()
				return
			}
			try {
				await this.postGithubInstall(repo, payload)
			} catch (e) {
				this.error = githubInstallFailureMessage(e)
				this.submitting = false
			}
		},

		/**
		 * Refuse a GitHub app that does not say which repository it is.
		 *
		 * @return {void}
		 */
		rejectMissingRepository() {
			this.error = t(
				'buildiq',
				'This GitHub app is missing its repository identity.',
			)
			this.submitting = false
		},

		/**
		 * POST the GitHub install and report the created application.
		 *
		 * @param {{owner: string, repo: string, ref?: string}} repo The repository.
		 * @param {object} payload The new app `{name, slug}`.
		 * @return {Promise<void>} Resolves once installed.
		 */
		async postGithubInstall(repo, payload) {
			const url = generateUrl('/apps/buildiq/api/shop/github/install')
			const resp = await axios.post(url, githubInstallBody(repo, payload))
			this.$emit('installed', resp.data)
			this.$emit('close')
		},

		/**
		 * Suggest a kebab-case slug from an arbitrary source string.
		 *
		 * @param {string} source The source string (remote slug or title).
		 * @return {string} A kebab-case slug, max 32 chars.
		 * @spec openspec/specs/openbuild-remote-template-store/spec.md
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
		 * Public method for a parent holding a `ref` to this dialog: shows a
		 * server-side failure (e.g. "slug already in use") inside the still-open
		 * dialog and re-enables the submit button so the user can correct and retry.
		 *
		 * @param {string} message - Already-translated error text to display.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-template-catalogue-ui/tasks.md#task-2
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
