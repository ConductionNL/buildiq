// SPDX-License-Identifier: EUPL-1.2
/**
 * applicationEditor store — Pinia slice that holds the in-flight
 * Application + manifest state shared between the Design and Raw JSON
 * tabs (REQ-OBPD-010 / MODIFIED REQ-OBR-005).
 *
 * CRUD is delegated to the shared @conduction/nextcloud-vue `useObjectStore`
 * (registered as type `application` against the shared `openbuilt` register
 * in store/store.js). This store owns only the editor-local UI state
 * (manifest draft, raw JSON, dirty flag, validation mirror).
 *
 * Round-trip-lossless contract (design.md Risk mitigation):
 * The original Application object is stored unmodified in `original`;
 * UI-controlled manifest fields are surgical-merged on `serialize()` so
 * externally authored keys the editor does not understand are preserved.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useApplicationEditorStore = defineStore('applicationEditor', {
	state: () => ({
		// The full Application object as loaded from OR (untouched copy).
		original: null,
		// In-flight manifest blob (mutable; what the editors author).
		manifest: null,
		// Loading / saving flags (mirrored from the shared object store).
		loading: false,
		saving: false,
		// Last validation result errors (mirrored from validator composable).
		validationErrors: [],
		// Raw JSON tab content (string). Allows the textarea to round-trip
		// formatting choices when the user is editing JSON directly.
		rawJsonDraft: '',
		// Has the in-flight state diverged from the loaded original?
		dirty: false,
		// Last load error (network / permission).
		loadError: '',
		// Last save error (network / validation).
		saveError: '',
	}),

	getters: {
		uuid(state) {
			if (!state.original) {
				return null
			}
			return state.original.uuid || state.original.id || null
		},
		slug(state) {
			if (!state.original) {
				return ''
			}
			return state.original.slug || ''
		},
		pages(state) {
			if (!state.manifest || !Array.isArray(state.manifest.pages)) {
				return []
			}
			return state.manifest.pages
		},
		menu(state) {
			if (!state.manifest || !Array.isArray(state.manifest.menu)) {
				return []
			}
			return state.manifest.menu
		},
	},

	actions: {
		/**
		 * Load an Application by its UUID via the shared object store.
		 * Populates `original`, `manifest`, and `rawJsonDraft`.
		 *
		 * @param {string} uuid - Application UUID.
		 */
		async load(uuid) {
			this.loading = true
			this.loadError = ''
			try {
				const objectStore = useObjectStore()
				const app = await objectStore.fetchObject('application', uuid)
				if (!app) {
					const err = objectStore.getError('application')
					this.loadError = `Failed to load application: ${err && err.message ? err.message : 'not found'}`
					return
				}
				this.original = app
				this.manifest = JSON.parse(JSON.stringify(app.manifest || {}))
				this.rawJsonDraft = JSON.stringify(this.manifest, null, 2)
				this.dirty = false
			} catch (e) {
				this.loadError = `Failed to load application: ${e && e.message ? e.message : e}`
			} finally {
				this.loading = false
			}
		},

		/**
		 * List Applications (lightweight; for picker in editor).
		 *
		 * @return {Promise<Array>} list of Applications.
		 */
		async listApplications() {
			try {
				const objectStore = useObjectStore()
				const results = await objectStore.fetchCollection('application', { _limit: 100 })
				return results || []
			} catch (e) {
				this.loadError = `Failed to list applications: ${e && e.message ? e.message : e}`
				return []
			}
		},

		/**
		 * Mark the in-flight manifest dirty + sync the raw JSON draft.
		 * Called by sub-editors on every edit. Cheap (O(manifest size)).
		 */
		touch() {
			this.dirty = true
			try {
				this.rawJsonDraft = JSON.stringify(this.manifest, null, 2)
			} catch {
				// Surface only — the validator will catch the structural issue.
			}
		},

		/**
		 * Update the manifest from the Raw JSON textarea draft. Returns
		 * { ok: boolean, error: string } so the textarea can surface parse
		 * errors inline.
		 *
		 * @param {string} jsonString - the JSON the user typed.
		 * @return {{ok: boolean, error: string}} parse outcome.
		 */
		commitRawJsonDraft(jsonString) {
			this.rawJsonDraft = jsonString
			try {
				const parsed = JSON.parse(jsonString)
				this.manifest = parsed
				this.dirty = true
				return { ok: true, error: '' }
			} catch (e) {
				return { ok: false, error: e.message }
			}
		},

		/**
		 * Set the validation error mirror (kept here so the Raw JSON tab
		 * and the Design tab share one error surface).
		 *
		 * @param {Array<string>} errors - validator error list.
		 */
		setValidationErrors(errors) {
			this.validationErrors = Array.isArray(errors) ? errors : []
		},

		/**
		 * Surgical merge: take the in-flight manifest's UI-controlled keys
		 * and overwrite them in the original Application; preserve every
		 * other Application field unmodified (per design.md Risk mitigation).
		 *
		 * @return {object} merged Application body suitable for save.
		 */
		serialize() {
			const base = this.original ? { ...this.original } : {}
			base.manifest = JSON.parse(JSON.stringify(this.manifest || {}))
			return base
		},

		/**
		 * Save the current in-flight Application via the shared object store.
		 * Refuses to save when `this.validationErrors` is non-empty so the
		 * caller can keep its Save button disabled.
		 *
		 * @return {Promise<boolean>} true on success.
		 */
		async save() {
			if (!this.uuid) {
				this.saveError = 'No application loaded.'
				return false
			}
			if (this.validationErrors.length > 0) {
				this.saveError = `Manifest invalid: ${this.validationErrors.join('; ')}`
				return false
			}
			this.saving = true
			this.saveError = ''
			try {
				const objectStore = useObjectStore()
				const body = this.serialize()
				// `saveObject` uses `id` to choose POST vs PUT — copy uuid into id
				// for the update path since OpenRegister identifies via uuid.
				const updateBody = { ...body, id: body.id || this.uuid }
				const saved = await objectStore.saveObject('application', updateBody)
				if (!saved) {
					const err = objectStore.getError('application')
					this.saveError = `Save failed: ${err && err.message ? err.message : 'unknown error'}`
					return false
				}
				this.original = saved
				this.dirty = false
				return true
			} catch (e) {
				this.saveError = `Save failed: ${e && e.message ? e.message : e}`
				return false
			} finally {
				this.saving = false
			}
		},

		/**
		 * Replace one page's config block in-place. Convenience for sub-editors.
		 *
		 * @param {number} pageIndex - position in manifest.pages.
		 * @param {object} config - new config block.
		 */
		updatePageConfig(pageIndex, config) {
			if (!this.manifest || !Array.isArray(this.manifest.pages)) {
				return
			}
			if (pageIndex < 0 || pageIndex >= this.manifest.pages.length) {
				return
			}
			this.manifest.pages[pageIndex] = {
				...this.manifest.pages[pageIndex],
				config: { ...config },
			}
			this.touch()
		},

		/**
		 * Replace the full pages array (after a drag-reorder or add/remove).
		 *
		 * @param {Array} pages - new pages array.
		 */
		setPages(pages) {
			if (!this.manifest) {
				this.manifest = { version: '0.1.0', menu: [], pages: [] }
			}
			this.manifest.pages = pages
			this.touch()
		},

		/**
		 * Replace the full menu array.
		 *
		 * @param {Array} menu - new menu array.
		 */
		setMenu(menu) {
			if (!this.manifest) {
				this.manifest = { version: '0.1.0', menu: [], pages: [] }
			}
			this.manifest.menu = menu
			this.touch()
		},
	},
})
