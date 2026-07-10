<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageDesigner — three-pane visual designer for OpenBuild application
  - manifests. Toolbar: undo / redo (OQ-1) + the save-and-preview action.
  - Left: page list + menu tree. Centre: per-page-type sub-editor
  - dispatched by `page.type` (the sub-editors paint inline validator
  - marks via the `pageEditorValidator` this view provides — task 5.5).
  - Right: validator error-list side panel (REQ-OBPD-011); the live
  - preview pane is deferred to chain spec #2 (see useLivePreview.js).
  - Implements REQ-OBPD-003.
  -->
<template>
	<div class="page-designer">
		<header class="page-designer__toolbar">
			<div class="page-designer__toolbar-group">
				<button
					type="button"
					class="page-designer__tool-btn"
					:disabled="!canUndo"
					:title="t('openbuild', 'Undo (Ctrl+Z)')"
					@click="undo">
					↶ {{ t('openbuild', 'Undo') }}
				</button>
				<button
					type="button"
					class="page-designer__tool-btn"
					:disabled="!canRedo"
					:title="t('openbuild', 'Redo (Ctrl+Shift+Z / Ctrl+Y)')"
					@click="redo">
					↷ {{ t('openbuild', 'Redo') }}
				</button>
			</div>
			<div class="page-designer__toolbar-group">
				<button
					type="button"
					class="page-designer__tool-btn page-designer__tool-btn--primary"
					:disabled="!canSaveAndPreview"
					@click="saveAndPreview">
					{{ t('openbuild', 'Save & open preview') }}
				</button>
			</div>
		</header>

		<div class="page-designer__panes">
			<aside class="page-designer__left">
				<PageListEditor
					:pages="pages"
					:selected-index="selectedIndex"
					@update:pages="onPagesUpdate"
					@select="selectPage" />
				<MenuTreeEditor
					:menu="menu"
					@update:menu="onMenuUpdate"
					@depth-violation="onDepthViolation" />
			</aside>

			<section class="page-designer__centre">
				<div v-if="selectedPage" class="page-designer__sub-editor">
					<component
						:is="subEditorFor(selectedPage.type)"
						:config="selectedPage.config || {}"
						:page-type="selectedPage.type"
						:app-slug="slug"
						:data-registers="applicationDataRegisters"
						:parent-route="selectedPage.route || ''"
						@update:config="onConfigUpdate" />
				</div>
				<div v-else class="page-designer__empty">
					<p>{{ t('openbuild', 'Select a page on the left, or add one to start designing.') }}</p>
				</div>
			</section>

			<aside class="page-designer__right">
				<!-- REQ-OBPD-008: sandboxed live-preview pane. The "available"
				     branch mounts a CnAppRoot rendered from the in-flight
				     (unsaved) manifest via the in-memory useAppManifest overload
				     (chain spec #2). It is a READ-ONLY render — no PUT/save is
				     ever issued to OpenRegister from this instance. The v-else
				     branch is the degraded fallback for environments whose
				     @conduction/nextcloud-vue predates the overload. -->
				<div v-if="previewAvailable && livePreviewProps" class="page-designer__preview">
					<h4>{{ t('openbuild', 'Live preview') }}</h4>
					<div class="page-designer__preview-surface">
						<CnAppRoot
							:key="livePreviewProps.key"
							:app-id="livePreviewProps.appId"
							:manifest="livePreviewProps.manifest"
							:registry="previewRegistry"
							:custom-components="previewFlatRegistry"
							:page-types="previewPageTypes"
							:translate="translateForPreview"
							:permissions="previewPermissions" />
					</div>
				</div>
				<div v-else class="page-designer__preview-fallback">
					<h4>{{ t('openbuild', 'Live preview') }}</h4>
					<p class="page-designer__preview-message">
						{{ t('openbuild', 'Live preview is not yet installed. Save and open the built app to preview your changes.') }}
					</p>
					<button
						type="button"
						class="page-designer__preview-btn"
						:disabled="!canSaveAndPreview"
						@click="saveAndPreview">
						{{ t('openbuild', 'Save & open preview') }}
					</button>
				</div>
				<div class="page-designer__errors">
					<h4>{{ t('openbuild', 'Validation') }}</h4>
					<p v-if="depthError" class="page-designer__error-row" role="alert">
						{{ t('openbuild', 'Menu depth is limited to two levels.') }}
					</p>
					<ul v-if="validatorErrors.length" class="page-designer__error-list">
						<li v-for="(err, i) in validatorErrors" :key="i" class="page-designer__error-row">
							{{ err }}
						</li>
					</ul>
					<p v-else-if="!depthError" class="page-designer__ok">
						{{ t('openbuild', 'No validation errors.') }}
					</p>
				</div>
			</aside>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, defaultPageTypes } from '@conduction/nextcloud-vue'
import registry from '../registry.js'
import PageListEditor from '../components/page-editor/PageListEditor.vue'
import MenuTreeEditor from '../components/page-editor/MenuTreeEditor.vue'
import IndexPageEditor from '../components/page-editor/IndexPageEditor.vue'
import DetailPageEditor from '../components/page-editor/DetailPageEditor.vue'
import DashboardPageEditor from '../components/page-editor/DashboardPageEditor.vue'
import FormPageEditor from '../components/page-editor/FormPageEditor.vue'
import LogsPageEditor from '../components/page-editor/LogsPageEditor.vue'
import SettingsPageEditor from '../components/page-editor/SettingsPageEditor.vue'
import ChatPageEditor from '../components/page-editor/ChatPageEditor.vue'
import FilesPageEditor from '../components/page-editor/FilesPageEditor.vue'
import CustomPageEditor from '../components/page-editor/CustomPageEditor.vue'
import StubPageEditor from '../components/page-editor/StubPageEditor.vue'
import { useLivePreview } from '../composables/useLivePreview.js'
import { useManifestValidator } from '../composables/useManifestValidator.js'
import { useManifestHistory } from '../composables/useManifestHistory.js'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'

// Closed mapping of page.type → sub-editor component. Adding a new type
// requires both the schema enum bump in `app-manifest.schema.json` AND a
// new entry here. Unsupported types fall back to StubPageEditor.
const SUB_EDITOR_MAP = {
	index: 'IndexPageEditor',
	detail: 'DetailPageEditor',
	dashboard: 'DashboardPageEditor',
	form: 'FormPageEditor',
	logs: 'LogsPageEditor',
	settings: 'SettingsPageEditor',
	chat: 'ChatPageEditor',
	files: 'FilesPageEditor',
	custom: 'CustomPageEditor',
}

export default {
	name: 'PageDesigner',
	components: {
		CnAppRoot,
		PageListEditor,
		MenuTreeEditor,
		IndexPageEditor,
		DetailPageEditor,
		DashboardPageEditor,
		FormPageEditor,
		LogsPageEditor,
		SettingsPageEditor,
		ChatPageEditor,
		FilesPageEditor,
		CustomPageEditor,
		StubPageEditor,
	},
	/**
	 * Observed behaviour of `provide` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
	 */
	provide() {
		// Sub-editors `inject` this to (a) register their config keys with
		// the validator's prefix→error map and (b) read back the
		// `{ hasError, message }` bag for inline marks. The path math
		// (`/pages/<selectedIndex>/config/<key>`) lives here so the
		// sub-editors stay index-agnostic. Methods read `this.selectedIndex`
		// at call time, so the prefix tracks the selected page.
		return {
			pageEditorValidator: {
				register: (configKey) => this.registerConfigField(configKey),
				unregister: (configKey) => this.unregisterConfigField(configKey),
				errorFor: (configKey) => this.configErrorFor(configKey),
			},
		}
	},
	props: {
		manifest: {
			type: Object,
			default: () => ({ pages: [], menu: [] }),
		},
		slug: {
			type: String,
			default: '',
		},
	},
	emits: ['update:manifest', 'save-and-preview'],
	/**
	 * Observed behaviour of `setup` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
	 */
	setup(props) {
		const { available: previewAvailable, previewProps } = useLivePreview()
		const validator = useManifestValidator()
		const history = useManifestHistory(props.manifest)
		return { previewAvailable, previewProps, validator, history }
	},
	data() {
		return {
			selectedIndex: -1,
			depthError: false,
			// REQ-OBVR-004: reactive version state resolved by useApplicationVersion.
			applicationVersion: null,
			versionLoading: false,
			versionError: null,
			// The active Application's declared `dataRegisters` bindings
			// (data-registers-runtime design.md Decision 2) — resolved via a
			// small, dedicated fetch (see fetchApplicationDataRegisters()) and
			// threaded down to the mounted sub-editor's register picker.
			applicationDataRegisters: [],
		}
	},
	computed: {
		/**
		 * Observed behaviour of `pages` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		pages() {
			return Array.isArray(this.manifest && this.manifest.pages) ? this.manifest.pages : []
		},
		/**
		 * Observed behaviour of `menu` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		menu() {
			return Array.isArray(this.manifest && this.manifest.menu) ? this.manifest.menu : []
		},
		/**
		 * Observed behaviour of `selectedPage` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		selectedPage() {
			if (this.selectedIndex < 0 || this.selectedIndex >= this.pages.length) {
				return null
			}
			return this.pages[this.selectedIndex]
		},
		/**
		 * Observed behaviour of `validatorErrors` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		validatorErrors() {
			return this.validator.errors.value || []
		},
		/**
		 * Observed behaviour of `canSaveAndPreview` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		canSaveAndPreview() {
			return !!this.slug && this.validatorErrors.length === 0
		},
		/**
		 * Observed behaviour of `canUndo` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		canUndo() {
			return !!(this.history && this.history.canUndo.value)
		},
		/**
		 * Observed behaviour of `canRedo` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		canRedo() {
			return !!(this.history && this.history.canRedo.value)
		},
		/**
		 * REQ-OBPD-008: prop bag for the sandboxed CnAppRoot preview mount —
		 * `{ appId, manifest, key }` derived from the in-flight (unsaved)
		 * manifest. `previewProps()` (from useLivePreview) returns null when
		 * the in-memory useAppManifest overload is unavailable, so this
		 * computed doubles as the render guard for the "available" branch.
		 * The `key` is the manifest content-hash, so an edit forces a clean
		 * re-mount of the sandbox (per REQ-OBPD-008's re-mount contract)
		 * rather than leaving stale internal state.
		 *
		 * @return {?object} preview props, or null when preview is unavailable.
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-22
		 */
		livePreviewProps() {
			if (!this.previewAvailable) {
				return null
			}
			return this.previewProps(this.slug, this.manifest)
		},
		/**
		 * REQ-OBPD-008 / REQ-OBPD-007: the v2 kind-tagged registry the preview
		 * sandbox resolves custom-page / slot-override components against —
		 * the SAME map the production App.vue mount uses, so a component
		 * resolves identically in the preview as it will in the built app.
		 *
		 * @return {object} v2 registry map.
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-23
		 */
		previewRegistry() {
			// Shallow clone: the lib exports frozen module objects in some
			// bundle shapes and Vue.extend() mutates component defs (_Ctor).
			return { ...registry }
		},
		/**
		 * Flattened `{ name: component }` map derived from the v2 registry,
		 * mirroring App.vue's `flatRegistry` — CnPageRenderer's
		 * `effectiveCustomComponents` resolver (beta.107) consults
		 * `customComponents`, not the v2 registry inject, so the sandbox
		 * needs both to resolve slot/custom-page names.
		 *
		 * @return {object} Map of registry key → Vue component.
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-23
		 */
		previewFlatRegistry() {
			const out = {}
			for (const [name, entry] of Object.entries(this.previewRegistry || {})) {
				const component = entry && typeof entry === 'object' && 'component' in entry
					? entry.component
					: entry
				if (component) {
					out[name] = component
				}
			}
			return out
		},
		/**
		 * The built-in page-type registry the preview sandbox dispatches
		 * `page.type` against — the same `defaultPageTypes` App.vue passes.
		 *
		 * @return {object} page-type map.
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-23
		 */
		previewPageTypes() {
			return { ...defaultPageTypes }
		},
		/**
		 * Permission flags handed to the preview's CnAppNav. Mirrors
		 * App.vue's `permissions` computed.
		 *
		 * @return {Array} Permission identifiers (empty when unavailable).
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-23
		 */
		previewPermissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},
	watch: {
		manifest: {
			deep: true,
			immediate: true,
			/**
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
			 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
			 */
			handler(m) {
				this.validator.validate(m)
				// Record every accepted manifest state. `push` no-ops on
				// structurally-identical states, so the controlled
				// component's own echoed prop updates are free.
				if (this.history) {
					this.history.push(m)
				}
			},
		},
	},
	/**
	 * Resolve the active Application's declared `dataRegisters` bindings
	 * (design.md Decision 2) so the mounted sub-editor's register picker can
	 * label/hoist them. A small, dedicated fetch — NOT routed through
	 * useApplicationVersion.js, which is shared by all four builder views and
	 * never returns the parent Application record.
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-2.2
	 */
	created() {
		if (this.slug) {
			this.fetchApplicationDataRegisters()
		}
	},
	/**
	 * Observed behaviour of `mounted` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
	 */
	mounted() {
		document.addEventListener('keydown', this.onKeydown)
		// REQ-OBVR-004: resolve the active ApplicationVersion on mount.
		// `this.slug` comes from the parent prop; `$route.query._version` reads
		// the query param from the URL (preserved by Vue Router across reloads,
		// satisfying REQ-OBVR-008 bookmarkability).
		// NOTE: no $router.replace() call here — that would strip ?_version=.
		if (this.slug) {
			const versionSlug = (this.$route && this.$route.query && this.$route.query._version) || undefined
			const { applicationVersion, loading, error } = useApplicationVersion(this.slug, versionSlug)
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			const unwatch = this.$watch(() => applicationVersion.value, (v) => {
				this.applicationVersion = v
			})
			const unwatchLoading = this.$watch(() => loading.value, (v) => {
				this.versionLoading = v
				if (!v) {
					unwatch()
					unwatchLoading()
					this.versionError = error.value
				}
			})
		}
	},
	beforeDestroy() {
		document.removeEventListener('keydown', this.onKeydown)
	},
	methods: {
		/**
		 * Fetch the Application record for `this.slug` and store its
		 * `dataRegisters` (default `[]`). Same call shape
		 * `useApplicationVersion.js` already uses internally
		 * (`GET /apps/openregister/api/objects/openbuild/application`,
		 * filtered by `slug` + `_limit: 1`) — see design.md Decision 2 for why
		 * this is a small dedicated fetch rather than widening that shared
		 * composable's contract. Failures degrade to `[]` (no bindings)
		 * rather than surfacing an error — matching the picker's own
		 * dangling-reference non-goal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/data-registers-runtime/tasks.md#task-2.2
		 */
		async fetchApplicationDataRegisters() {
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuild/application')
				const { data } = await axios.get(url, { params: { slug: this.slug, _limit: 1 } })
				const apps = Array.isArray(data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				const app = apps.find((a) => a && a.slug === this.slug) || null
				this.applicationDataRegisters = (app && Array.isArray(app.dataRegisters)) ? app.dataRegisters : []
			} catch (e) {
				this.applicationDataRegisters = []
			}
		},
		/**
		 * Observed behaviour of `subEditorFor` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		subEditorFor(type) {
			return SUB_EDITOR_MAP[type] || 'StubPageEditor'
		},
		/**
		 * Observed behaviour of `selectPage` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		selectPage(index) {
			this.selectedIndex = index
		},
		/**
		 * Observed behaviour of `emitManifest` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		emitManifest(next) {
			this.$emit('update:manifest', next)
		},
		/**
		 * Observed behaviour of `onPagesUpdate` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		onPagesUpdate(pages) {
			const next = { ...(this.manifest || {}), pages }
			this.emitManifest(next)
		},
		/**
		 * Observed behaviour of `onMenuUpdate` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		onMenuUpdate(menu) {
			const next = { ...(this.manifest || {}), menu }
			this.depthError = false
			this.emitManifest(next)
		},
		/**
		 * Observed behaviour of `onDepthViolation` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		onDepthViolation() {
			this.depthError = true
		},
		/**
		 * Observed behaviour of `onConfigUpdate` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		onConfigUpdate(config) {
			if (this.selectedIndex < 0) {
				return
			}
			const pages = this.pages.slice()
			pages[this.selectedIndex] = { ...pages[this.selectedIndex], config }
			const next = { ...(this.manifest || {}), pages }
			this.emitManifest(next)
		},
		/**
		 * Observed behaviour of `saveAndPreview` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		saveAndPreview() {
			this.$emit('save-and-preview')
		},
		/**
		 * REQ-OBPD-008: translate function handed to the sandboxed CnAppRoot
		 * preview (mirrors App.vue's `translateForApp`). Closes over
		 * Nextcloud's translate against the openbuild app id so preview chrome
		 * (nav labels, empty states) localises identically to the built app.
		 *
		 * @param {string} key - Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-23
		 */
		translateForPreview(key) {
			return ncT('openbuild', key)
		},
		// --- Undo / redo (OQ-1) -------------------------------------------
		/**
		 * Observed behaviour of `undo` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		undo() {
			if (!this.history) {
				return
			}
			const prev = this.history.undo()
			if (prev !== null) {
				this.emitManifest(prev)
			}
		},
		/**
		 * Observed behaviour of `redo` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		redo() {
			if (!this.history) {
				return
			}
			const next = this.history.redo()
			if (next !== null) {
				this.emitManifest(next)
			}
		},
		/**
		 * Observed behaviour of `onKeydown` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		onKeydown(event) {
			if (!event || !(event.ctrlKey || event.metaKey)) {
				return
			}
			const key = (event.key || '').toLowerCase()
			if (key === 'z' && !event.shiftKey) {
				event.preventDefault()
				this.undo()
			} else if ((key === 'z' && event.shiftKey) || key === 'y') {
				event.preventDefault()
				this.redo()
			}
		},
		// --- Inline validator marks (task 5.5) ----------------------------
		/**
		 * Observed behaviour of `configPathPrefix` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		configPathPrefix(configKey) {
			if (this.selectedIndex < 0) {
				return ''
			}
			return `/pages/${this.selectedIndex}/config/${configKey}`
		},
		/**
		 * Observed behaviour of `registerConfigField` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		registerConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (prefix && this.validator && typeof this.validator.register === 'function') {
				this.validator.register(prefix)
			}
		},
		/**
		 * Observed behaviour of `unregisterConfigField` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		unregisterConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (prefix && this.validator && typeof this.validator.unregister === 'function') {
				this.validator.unregister(prefix)
			}
		},
		/**
		 * Observed behaviour of `configErrorFor` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		configErrorFor(configKey) {
			const empty = { hasError: false, message: '' }
			if (!this.validator || typeof this.validator.errorFor !== 'function') {
				return empty
			}
			const prefix = this.configPathPrefix(configKey)
			if (!prefix) {
				return empty
			}
			return this.validator.errorFor(prefix) || empty
		},
	},
}
</script>

<style scoped>
.page-designer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	min-height: 60vh;
}

.page-designer__toolbar {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 4px 0;
}

.page-designer__toolbar-group {
	display: flex;
	gap: 8px;
}

.page-designer__tool-btn {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.page-designer__tool-btn--primary {
	background: var(--color-primary-element-light);
}

.page-designer__tool-btn[disabled] {
	cursor: not-allowed;
	opacity: 0.5;
}

.page-designer__panes {
	display: grid;
	grid-template-columns: minmax(280px, 320px) 1fr minmax(260px, 320px);
	gap: 12px;
	min-height: 60vh;
}

.page-designer__left,
.page-designer__centre,
.page-designer__right {
	display: flex;
	flex-direction: column;
	gap: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	background: var(--color-main-background);
}

.page-designer__centre {
	min-height: 50vh;
}

.page-designer__sub-editor {
	display: flex;
	flex: 1;
	flex-direction: column;
}

.page-designer__empty {
	display: flex;
	flex: 1;
	align-items: center;
	justify-content: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.page-designer__preview {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.page-designer__preview-surface {
	flex: 1;
	min-height: 240px;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.page-designer__preview-fallback {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.page-designer__preview h4,
.page-designer__preview-fallback h4,
.page-designer__errors h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.page-designer__preview-message {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	line-height: 1.4;
}

.page-designer__preview-btn {
	align-self: flex-start;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
	color: var(--color-main-text);
	cursor: pointer;
}

.page-designer__preview-btn[disabled] {
	cursor: not-allowed;
	opacity: 0.6;
}

.page-designer__errors {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.page-designer__error-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.page-designer__error-row {
	margin: 0;
	padding: 4px 6px;
	background: var(--color-background-hover);
	border-left: 3px solid var(--color-error);
	border-radius: var(--border-radius);
	font-size: 12px;
	color: var(--color-main-text);
}

.page-designer__ok {
	margin: 0;
	font-size: 12px;
	color: var(--color-success, var(--color-text-maxcontrast));
}

@media (max-width: 1100px) {
	.page-designer__panes {
		grid-template-columns: 1fr;
	}
}
</style>
