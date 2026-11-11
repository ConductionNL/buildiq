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
			{{
				t(
					'buildiq',
					'Integrator-only editor: edit the raw JSON manifest below. For a visual editor open "Design pages".',
				)
			}}
		</p>
		<textarea
			v-model="manifestText"
			class="ob-manifest-tab__textarea"
			data-testid="buildiq-editor-textarea"
			spellcheck="false"
			:readonly="obAppRole === 'viewer' || obAppRole === 'none'"
			:placeholder="t('buildiq', 'Paste or edit the JSON manifest here.')"
			:aria-label="t('buildiq', 'Paste or edit the JSON manifest here.')" />
		<div v-if="error" class="ob-manifest-tab__error">
			{{ t('buildiq', 'Invalid manifest') }}: {{ error }}
		</div>
		<div v-if="obAppError" class="ob-manifest-tab__error">
			{{ obAppError }}
		</div>
		<div class="ob-manifest-tab__actions">
			<NcButton
				v-if="obAppRole === 'editor' || obAppRole === 'owner'"
				variant="primary"
				:disabled="!obApp || saving"
				data-testid="buildiq-editor-save"
				@click="save">
				{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
			</NcButton>
			<span v-if="savedToast" class="ob-manifest-tab__toast">{{
				savedToast
			}}</span>
		</div>
	</div>
</template>

<script>
import { validateManifest } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import applicationContext from '../../mixins/applicationContext.js'

/**
 * A manifest as the editor's pretty-printed text; `{}` when there is none.
 *
 * @param {?object} manifest - the manifest.
 * @return {string}
 */
function manifestJson(manifest) {
	return JSON.stringify(manifest || {}, null, 2)
}

/**
 * Read the active version's manifest of an app.
 *
 * @param {string} slug - the app slug.
 * @return {Promise<?object>} the manifest, bare as the endpoint returns it.
 */
async function readActiveManifest(slug) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/buildiq/api/applications/${encodeURIComponent(slug)}/manifest`,
		),
	)
	return data
}

/**
 * The validator's complaint about a parsed manifest, or null when it passes
 * (or when no validator is available).
 *
 * @param {object} parsed - the parsed manifest.
 * @return {?string} the errors joined with "; ", or null.
 */
function validationErrorOf(parsed) {
	const result = validateManifest
		? validateManifest(parsed)
		: { valid: true, errors: [] }
	if (result && result.valid === false) {
		return (result.errors || ['unknown']).join('; ')
	}
	return null
}

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
			/**
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
			 * @param {{manifest?: object}} app - The Application resolved by the
			 *   `applicationContext` mixin. Its manifest is re-serialised into the
			 *   editor's text buffer whenever the record changes, so unsaved edits are
			 *   deliberately discarded on a refresh. Runs immediately, and is falsy
			 *   while the record is still loading (in which case the buffer is left
			 *   alone rather than blanked).
			 *
			 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
			 */
			handler(app) {
				if (app) {
					this.loadManifest(app)
				}
			},
		},
	},

	methods: {
		/**
		 * Load the app's manifest into the editor buffer, from the ACTIVE VERSION.
		 *
		 * This tab used to seed itself with `JSON.stringify(app.manifest || {})`.
		 * Under the versioned model (ADR-002) the manifest lives on the
		 * ApplicationVersion, and the Application record carries no `manifest` key
		 * at all — `ApplicationsController` says as much itself ("reading
		 * `applicationArray['manifest']` directly returns null for every app"). So
		 * the integrator-facing raw JSON editor showed `{}` for EVERY application,
		 * and its Save then wrote that onto a field nothing reads back: empty on
		 * read, dead on write.
		 *
		 * `GET /api/applications/{slug}/manifest` resolves the active version's
		 * manifest, which is what the editor is supposed to show.
		 *
		 * @param {{slug?: string, manifest?: object}} app - the resolved Application.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async loadManifest(app) {
			const slug = app && app.slug
			if (!slug) {
				this.manifestText = manifestJson(app?.manifest)
				return
			}
			try {
				this.manifestText = manifestJson(await readActiveManifest(slug))
			} catch (e) {
				// Fall back to whatever the record carries rather than blanking the
				// editor; the error surface below reports the failure.
				this.manifestText = manifestJson(app.manifest)
				this.error = `${t('buildiq', 'Could not load the manifest')}: ${e.message || e}`
			}
		},

		/**
		 * Observed behaviour of `parseAndValidate` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		parseAndValidate() {
			const parsed = this.parseManifestText()
			if (parsed === undefined) {
				return null
			}
			const invalid = validationErrorOf(parsed)
			if (invalid !== null) {
				this.error = invalid
				return null
			}
			this.error = ''
			return parsed
		},

		/**
		 * Parse the editor buffer, reporting a JSON error.
		 *
		 * @return {object|Array|string|number|boolean|null|undefined} the parsed
		 *   value, or undefined when it is not JSON.
		 */
		parseManifestText() {
			try {
				return JSON.parse(this.manifestText)
			} catch (e) {
				this.error = `${t('buildiq', 'JSON parse error')}: ${e.message}`
				return undefined
			}
		},

		/**
		 * Observed behaviour of `save` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
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
				// PUT the ACTIVE VERSION's manifest, not a field on the Application.
				// `obPatchApp({ manifest })` wrote onto the Application record, which
				// nothing reads back — see loadManifest() for the full writeup.
				// Note the endpoint's asymmetry: GET returns the manifest bare, PUT
				// expects it wrapped in `{ manifest }`.
				await axios.put(
					generateUrl(
						`/apps/buildiq/api/applications/${encodeURIComponent(this.obApp.slug)}/manifest`,
					),
					{ manifest: parsed },
				)
				this.savedToast = t('buildiq', 'Saved')
			} catch (e) {
				this.error = `${t('buildiq', 'Save failed')}: ${e.message || e}`
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
