<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageDesigner — three-pane visual designer for Buildiq application
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
					:title="t('buildiq', 'Undo (Ctrl+Z)')"
					@click="undo">
					↶ {{ t('buildiq', 'Undo') }}
				</button>
				<button
					type="button"
					class="page-designer__tool-btn"
					:disabled="!canRedo"
					:title="t('buildiq', 'Redo (Ctrl+Shift+Z / Ctrl+Y)')"
					@click="redo">
					↷ {{ t('buildiq', 'Redo') }}
				</button>
			</div>
			<div class="page-designer__toolbar-group">
				<button
					type="button"
					class="page-designer__tool-btn"
					@click="blocksSidebarOpen = true">
					{{ t('buildiq', 'Blocks') }}
				</button>
				<button
					type="button"
					class="page-designer__tool-btn page-designer__tool-btn--primary"
					:disabled="!canSaveAndPreview"
					@click="saveAndPreview">
					{{ t('buildiq', 'Save & open preview') }}
				</button>
			</div>
		</header>

		<!-- component-blocks: block-library panel, an NcAppSidebar panel per
		     design.md's Open Question (resolved: sidebar, not a designer tab).
		     Insert deep-copies via BlockInsertService then merges the new
		     widgetEntry objects onto the selected page through
		     mergeManifestDelta — the app's existing keyed structural-merge
		     engine — rather than splicing the manifest by hand. -->
		<NcAppSidebar
			v-if="blocksSidebarOpen"
			:name="t('buildiq', 'Blocks')"
			@close="blocksSidebarOpen = false">
			<BlockLibraryPanel
				:open="blocksSidebarOpen"
				:targetSchemaSlugs="targetSchemaSlugs"
				:targetWidgets="(selectedPage && selectedPage.widgets) || []"
				@insertWidgets="onInsertWidgets" />
		</NcAppSidebar>

		<div class="page-designer__panes">
			<aside class="page-designer__left">
				<PageListEditor
					:pages="pages"
					:selectedIndex="selectedIndex"
					@update:pages="onPagesUpdate"
					@select="selectPage" />
				<MenuTreeEditor
					:menu="menu"
					@update:menu="onMenuUpdate"
					@depthViolation="onDepthViolation" />
			</aside>

			<section class="page-designer__centre">
				<div v-if="selectedPage" class="page-designer__sub-editor">
					<component
						:is="subEditorFor(selectedPage.type)"
						:config="selectedPage.config || {}"
						:pageType="selectedPage.type"
						:appSlug="slug"
						:data-registers="applicationDataRegisters"
						:parentRoute="selectedPage.route || ''"
						:title="
							t('buildiq', 'Unsupported page type: {type}', {
								type: selectedPage.type,
							})
						"
						:message="
							t(
								'buildiq',
								'No visual editor exists for this page type yet. Edit the raw config below; unknown keys are preserved.',
							)
						"
						:pageId="selectedPage.id || ''"
						:runtimeExternalForms="externalForms"
						@update:config="onConfigUpdate"
						@update:runtimeExternalForms="onExternalFormsUpdate" />
					<!-- component-blocks task 2.2: widget/section selection
					     affordance feeding SaveBlockDialog. Operates on the
					     page's uniform v2 widgets[] array. -->
					<WidgetSelectionPanel
						:widgets="selectedPage.widgets || []"
						:application="{ slug }"
						:existingBlocks="existingBlocks"
						@saved="onBlockSaved" />
				</div>
				<div v-else class="page-designer__empty">
					<p>
						{{
							t(
								'buildiq',
								'Select a page on the left, or add one to start designing.',
							)
						}}
					</p>
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
				<div
					v-if="previewAvailable && livePreviewProps"
					class="page-designer__preview">
					<h4>{{ t('buildiq', 'Live preview') }}</h4>
					<div class="page-designer__preview-surface">
						<CnAppRoot
							:key="livePreviewProps.key"
							:appId="livePreviewProps.appId"
							:manifest="livePreviewProps.manifest"
							:registry="previewRegistry"
							:customComponents="previewFlatRegistry"
							:pageTypes="previewPageTypes"
							:translate="translateForPreview"
							:permissions="previewPermissions" />
					</div>
				</div>
				<div v-else class="page-designer__preview-fallback">
					<h4>{{ t('buildiq', 'Live preview') }}</h4>
					<p class="page-designer__preview-message">
						{{
							t(
								'buildiq',
								'Live preview is not yet installed. Save and open the built app to preview your changes.',
							)
						}}
					</p>
					<button
						type="button"
						class="page-designer__preview-btn"
						:disabled="!canSaveAndPreview"
						@click="saveAndPreview">
						{{ t('buildiq', 'Save & open preview') }}
					</button>
				</div>
				<div class="page-designer__errors">
					<h4>{{ t('buildiq', 'Validation') }}</h4>
					<p
						v-if="depthError"
						class="page-designer__error-row"
						role="alert">
						{{ t('buildiq', 'Menu depth is limited to two levels.') }}
					</p>
					<ul
						v-if="validatorErrors.length"
						class="page-designer__error-list">
						<li
							v-for="(err, i) in validatorErrors"
							:key="i"
							class="page-designer__error-row">
							{{ err }}
						</li>
					</ul>
					<p v-else-if="!depthError" class="page-designer__ok">
						{{ t('buildiq', 'No validation errors.') }}
					</p>
				</div>
			</aside>
		</div>
	</div>
</template>

<script>
import {
	CnAppRoot,
	defaultPageTypes,
	mergeManifestDelta,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcAppSidebar } from '@nextcloud/vue'
import BlockLibraryPanel from '../components/page-editor/BlockLibraryPanel.vue'
import ChatPageEditor from '../components/page-editor/ChatPageEditor.vue'
import CustomPageEditor from '../components/page-editor/CustomPageEditor.vue'
import DashboardPageEditor from '../components/page-editor/DashboardPageEditor.vue'
import DetailPageEditor from '../components/page-editor/DetailPageEditor.vue'
import FilesPageEditor from '../components/page-editor/FilesPageEditor.vue'
import FormPageEditor from '../components/page-editor/FormPageEditor.vue'
import IndexPageEditor from '../components/page-editor/IndexPageEditor.vue'
import LogsPageEditor from '../components/page-editor/LogsPageEditor.vue'
import MapPageEditor from '../components/page-editor/MapPageEditor.vue'
import MenuTreeEditor from '../components/page-editor/MenuTreeEditor.vue'
import PageListEditor from '../components/page-editor/PageListEditor.vue'
import RoadmapPageEditor from '../components/page-editor/RoadmapPageEditor.vue'
import SearchPageEditor from '../components/page-editor/SearchPageEditor.vue'
import SettingsPageEditor from '../components/page-editor/SettingsPageEditor.vue'
import StubPageEditor from '../components/page-editor/StubPageEditor.vue'
import WidgetSelectionPanel from '../components/page-editor/WidgetSelectionPanel.vue'
import WikiPageEditor from '../components/page-editor/WikiPageEditor.vue'
import { useApplicationVersion } from '../composables/useApplicationVersion.js'
import { useLivePreview } from '../composables/useLivePreview.js'
import { useManifestValidator } from '../composables/useManifestValidator.js'
import { useRegisterPicker } from '../composables/useRegisterPicker.js'
import { useSessionHistory } from '../composables/useSessionHistory.js'
import registry from '../registry.js'
import { isEditableTarget } from '../utils/isEditableTarget.js'

// Mapping of page.type → sub-editor component, covering every canonical v2
// page type that ships a renderer component (REQ-PEC-001). Adding a new
// type requires both the schema enum bump in `app-manifest-v2.schema.json`
// AND a new entry here. Types absent from this map (unknown/future types)
// fall back to StubPageEditor, whose required `title`/`message` props are
// bound on the `<component :is>` dispatch site below.
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
	map: 'MapPageEditor',
	roadmap: 'RoadmapPageEditor',
	search: 'SearchPageEditor',
	wiki: 'WikiPageEditor',
}

export default {
	name: 'PageDesigner',
	components: {
		CnAppRoot,
		NcAppSidebar,
		BlockLibraryPanel,
		WidgetSelectionPanel,
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
		MapPageEditor,
		RoadmapPageEditor,
		SearchPageEditor,
		WikiPageEditor,
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

		/**
		 * Session-boundary key (REQ-BUR-004, design.md D3). Owned by the
		 * host (`PageDesignerHost.vue`), derived from slug + version slug +
		 * a save counter. A change re-baselines the undo/redo history to
		 * the then-current manifest with both Undo and Redo disabled —
		 * fixing the cross-version undo-bleed at HEAD, where the local
		 * composable's `reset()` had no callers.
		 */
		sessionKey: {
			type: String,
			default: '',
		},
	},

	emits: ['update:manifest', 'save-and-preview'],
	/**
	 * Observed behaviour of `setup` (retrofit annotation).
	 *
	 * @param {{manifest: object, slug: string, sessionKey: string}} props - This
	 *   view's resolved props. Only `manifest` is read here, to seed the undo/redo
	 *   history with the session baseline before the first render; `slug` and
	 *   `sessionKey` are consumed by the Options API half of the component.
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
	 */
	setup(props) {
		const { available: previewAvailable, previewProps } = useLivePreview()
		const validator = useManifestValidator()
		// REQ-BUR-001 / REQ-BUR-007: shared nc-vue history engine (depth
		// 100), seeded with the incoming manifest as the session baseline.
		const history = useSessionHistory(props.manifest, { limit: 100 })
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
			// component-blocks: block-library NcAppSidebar open state (toolbar
			// "Blocks" button), the current app's companion schema slugs (for
			// insert-time remap mismatch detection), and the blocks already
			// visible to the caller (for SaveBlockDialog's slug-collision check).
			blocksSidebarOpen: false,
			targetSchemaSlugs: [],
			existingBlocks: [],
		}
	},

	computed: {
		/**
		 * Observed behaviour of `pages` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		pages() {
			return Array.isArray(this.manifest && this.manifest.pages)
				? this.manifest.pages
				: []
		},

		/**
		 * Observed behaviour of `menu` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		menu() {
			return Array.isArray(this.manifest && this.manifest.menu)
				? this.manifest.menu
				: []
		},

		/**
		 * `runtime.externalForms[]` (REQ-EFP-001/002) — read here so
		 * FormPageEditor can filter to the selected page's entry without
		 * needing the whole manifest.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
		 */
		externalForms() {
			return Array.isArray(
				this.manifest
					&& this.manifest.runtime
					&& this.manifest.runtime.externalForms,
			)
				? this.manifest.runtime.externalForms
				: []
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
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.2
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
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.3
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
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.3
		 */
		previewFlatRegistry() {
			const out = {}
			for (const [name, entry] of Object.entries(this.previewRegistry || {})) {
				const component =
					entry && typeof entry === 'object' && 'component' in entry
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
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.3
		 */
		previewPageTypes() {
			return { ...defaultPageTypes }
		},

		/**
		 * Permission flags handed to the preview's CnAppNav. Mirrors
		 * App.vue's `permissions` computed.
		 *
		 * @return {Array} Permission identifiers (empty when unavailable).
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.3
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
			 * @param {{pages: object[], menu: object[], runtime?: object}} m - The
			 *   incoming manifest, as passed by the deep+immediate watcher: either
			 *   the host's freshly-loaded manifest or one this view just emitted back
			 *   through `update:manifest`. Validated and pushed onto the history.
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

		/**
		 * REQ-BUR-004: a session-key change (save / app-slug / version
		 * switch, owned by the host) re-baselines the undo/redo history to
		 * the then-current manifest, disabling both Undo and Redo.
		 *
		 * @param {string} newKey - the new session key.
		 * @param {string} oldKey - the previous session key.
		 */
		sessionKey(newKey, oldKey) {
			if (newKey !== oldKey && this.history) {
				this.history.reset(this.manifest)
			}
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
			this.fetchBlockCaptureContext()
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
			const versionSlug =
				(this.$route && this.$route.query && this.$route.query._version)
				|| undefined
			const { applicationVersion, loading, error } = useApplicationVersion(
				this.slug,
				versionSlug,
			)
			this.applicationVersion = applicationVersion.value
			this.versionLoading = loading.value
			const unwatch = this.$watch(
				() => applicationVersion.value,
				(v) => {
					this.applicationVersion = v
				},
			)
			const unwatchLoading = this.$watch(
				() => loading.value,
				(v) => {
					this.versionLoading = v
					if (!v) {
						unwatch()
						unwatchLoading()
						this.versionError = error.value
					}
				},
			)
		}
	},

	beforeUnmount() {
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
				const url = generateUrl(
					'/apps/openregister/api/objects/openbuild/application',
				)
				const { data } = await axios.get(url, {
					params: { slug: this.slug, _limit: 1 },
				})
				const apps = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				const app = apps.find((a) => a && a.slug === this.slug) || null
				this.applicationDataRegisters =
					app && Array.isArray(app.dataRegisters) ? app.dataRegisters : []
			} catch (e) {
				this.applicationDataRegisters = []
			}
		},

		/**
		 * Resolve the current app's own companion schema slugs (via the same
		 * `useRegisterPicker` composable `openSaveAsTemplate` already uses)
		 * plus the blocks already visible to the caller — both consumed by
		 * the component-blocks surfaces (`BlockLibraryPanel`'s remap
		 * mismatch check, `SaveBlockDialog`'s slug-collision check). Failures
		 * degrade to `[]` — a schema-fetch or block-list failure should never
		 * block the rest of the page designer from rendering.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		async fetchBlockCaptureContext() {
			try {
				const picker = useRegisterPicker({
					appSlug: this.slug,
					dataRegisters: this.applicationDataRegisters,
				})
				const schemas = await picker.fetchSchemas(
					picker.resolveAppRegister(),
				)
				this.targetSchemaSlugs = Array.isArray(schemas)
					? schemas.map((s) => s && s.slug).filter(Boolean)
					: []
			} catch (e) {
				this.targetSchemaSlugs = []
			}
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openbuild/component-block',
				)
				const { data } = await axios.get(url)
				this.existingBlocks = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
			} catch (e) {
				this.existingBlocks = []
			}
		},

		/**
		 * Merge freshly-inserted widgetEntry objects (from
		 * `BlockLibraryPanel`'s `insert-widgets` event, already deep-copied
		 * and freshly id-minted by `blockInsert.js#insertBlock`) onto the
		 * selected page via `mergeManifestDelta` — the app's existing keyed
		 * structural-merge engine (`widgets[]` merges by `id`, so this ADDS
		 * the new entries without disturbing any existing widget).
		 *
		 * @param {Array<object>} widgets - the widgetEntry objects to insert.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onInsertWidgets(widgets) {
			if (
				!this.selectedPage
				|| !Array.isArray(widgets)
				|| widgets.length === 0
			) {
				return
			}
			const delta = { pages: [{ id: this.selectedPage.id, widgets }] }
			const { manifest: merged } = mergeManifestDelta(
				this.manifest || {},
				delta,
			)
			this.emitManifest(merged)
		},

		/**
		 * Refresh the block-capture context after a block is saved from the
		 * widget-selection affordance, so the block library reflects it
		 * immediately.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onBlockSaved() {
			this.fetchBlockCaptureContext()
		},

		/**
		 * Observed behaviour of `subEditorFor` (retrofit annotation).
		 *
		 * @param {string} type - The selected page's `page.type` (a canonical v2 page
		 *   type such as `index`, `detail`, `form`).
		 * @return {string} Registered component name for that page type, or
		 *   `'StubPageEditor'` for a type this build ships no visual editor for.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		subEditorFor(type) {
			return SUB_EDITOR_MAP[type] || 'StubPageEditor'
		},

		/**
		 * Observed behaviour of `selectPage` (retrofit annotation).
		 *
		 * @param {number} index - Position in `pages` of the page to open in the centre
		 *   pane, from PageListEditor's `select` event. `-1` (emitted when the selected
		 *   page is removed) clears the selection and shows the empty state.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		selectPage(index) {
			this.selectedIndex = index
		},

		/**
		 * Observed behaviour of `emitManifest` (retrofit annotation).
		 *
		 * Single write path back to the host: this view is a controlled component and
		 * never mutates the `manifest` prop in place.
		 *
		 * @param {{pages: object[], menu?: object[], runtime?: object}} next - The
		 *   complete replacement manifest (every caller builds it by spreading the
		 *   current one), emitted as `update:manifest`.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		emitManifest(next) {
			this.$emit('update:manifest', next)
		},

		/**
		 * Observed behaviour of `onPagesUpdate` (retrofit annotation).
		 *
		 * @param {Array<{id: string, route: string, type: string, title?: string,
		 *   permission?: string, config: object, widgets?: object[]}>} pages - The
		 *   complete replacement `manifest.pages` array from PageListEditor's
		 *   `update:pages` event (add / edit-field / remove / drag-reorder all emit
		 *   the whole array, never a patch).
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
		 * Also clears `depthError`: any accepted menu edit means the two-level depth
		 * limit is no longer being violated.
		 *
		 * @param {Array<{label: string, icon?: string, route?: string, href?: string,
		 *   children?: object[]}>} menu - The complete replacement `manifest.menu`
		 *   tree from MenuTreeEditor's `update:menu` event. At most two levels deep;
		 *   second-level entries carry no `children` of their own.
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
		 * @param {object} config - The selected page's complete replacement `config`
		 *   object, from the mounted sub-editor's `update:config` event. Its keys are
		 *   page-type specific (an `index` page's `{register, schema, columns,
		 *   actions}`, a `form` page's `{fields, submitMethod, mode}`, …) and unknown
		 *   keys are carried through untouched. No-ops while no page is selected.
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
		 * Persist an updated `runtime.externalForms[]` array from
		 * FormPageEditor's ExternalFormAccessDialog (REQ-EFP-001). Deletes the
		 * `runtime.externalForms` key entirely when the array empties out so an
		 * app that has never used the feature (or has fully reverted it)
		 * serializes byte-identically to the pre-feature baseline — same
		 * pattern as `ThemeSection.withTheme()`.
		 *
		 * @param {Array<object>} list - the full updated `externalForms` array.
		 * @return {void}
		 * @spec openspec/changes/external-form-provisioning/specs/external-form-provisioning/spec.md#req-efp-001
		 */
		onExternalFormsUpdate(list) {
			const next = { ...(this.manifest || {}) }
			const runtime = { ...(next.runtime || {}) }
			if (Array.isArray(list) && list.length) {
				runtime.externalForms = list
			} else {
				delete runtime.externalForms
			}
			if (Object.keys(runtime).length === 0) {
				delete next.runtime
			} else {
				next.runtime = runtime
			}
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
		 * Nextcloud's translate against the buildiq app id so preview chrome
		 * (nav labels, empty states) localises identically to the built app.
		 *
		 * @param {string} key - Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec openspec/changes/page-designer-live-preview-pane/tasks.md#task-2.3
		 */
		translateForPreview(key) {
			return ncT('buildiq', key)
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
		 * REQ-BUR-003: document-level Undo/Redo shortcut handler with an
		 * editable-target guard — chords are ignored while focus is inside
		 * an input/textarea/select/contenteditable element, so the
		 * browser's native text-field undo wins there instead of stacking a
		 * manifest-level revert on top of it (design.md D4).
		 *
		 * @param {KeyboardEvent} event - The document-level keydown. Only Ctrl/Cmd
		 *   chords are acted on: Ctrl+Z undoes, Ctrl+Shift+Z / Ctrl+Y redo; anything
		 *   else, and any chord fired while an editable element has focus, is ignored.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 * @spec openspec/specs/builder-undo-redo/spec.md#req-bur-003
		 */
		onKeydown(event) {
			if (!event || !(event.ctrlKey || event.metaKey)) {
				return
			}
			if (isEditableTarget(event)) {
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
		 * @param {string} configKey - Name of a key inside the selected page's `config`
		 *   object (e.g. `register`, `columns`), as passed by the sub-editor through
		 *   the injected `pageEditorValidator`.
		 * @return {string} The validator error path for that field
		 *   (`/pages/<selectedIndex>/config/<configKey>`), or `''` when no page is
		 *   selected — which every caller treats as "no mark".
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
		 * @param {string} configKey - Key of the selected page's `config` the calling
		 *   sub-editor wants inline validator marks for; registered with the validator
		 *   under its full manifest path so its errors stop bubbling into the
		 *   side-panel list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		registerConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (
				prefix
				&& this.validator
				&& typeof this.validator.register === 'function'
			) {
				this.validator.register(prefix)
			}
		},

		/**
		 * Observed behaviour of `unregisterConfigField` (retrofit annotation).
		 *
		 * @param {string} configKey - Key of the selected page's `config` the calling
		 *   sub-editor is releasing (on unmount / page switch), so its errors go back
		 *   to the side-panel list.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-1
		 */
		unregisterConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (
				prefix
				&& this.validator
				&& typeof this.validator.unregister === 'function'
			) {
				this.validator.unregister(prefix)
			}
		},

		/**
		 * Observed behaviour of `configErrorFor` (retrofit annotation).
		 *
		 * @param {string} configKey - Key of the selected page's `config` the calling
		 *   sub-editor is painting an inline mark for.
		 * @return {{hasError: boolean, message: string}} The validator's error bag for
		 *   that field; `{ hasError: false, message: '' }` when the field is valid, no
		 *   page is selected, or the validator exposes no `errorFor`.
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
	/* Grid items default to `min-width: auto` and so refuse to shrink below
	   their content's intrinsic minimum, which lets a wide child push a pane
	   past its own track. Defensive only — the actual overflow that painted the
	   left pane over the centre one came from a hardcoded NcSelect min-width
	   and is fixed in PageListEditor.vue, where that select lives. */
	min-width: 0;
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
