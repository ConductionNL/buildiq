<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageDesigner — three-pane visual designer for OpenBuilt application
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
					:title="t('openbuilt', 'Undo (Ctrl+Z)')"
					@click="undo">
					↶ {{ t('openbuilt', 'Undo') }}
				</button>
				<button
					type="button"
					class="page-designer__tool-btn"
					:disabled="!canRedo"
					:title="t('openbuilt', 'Redo (Ctrl+Shift+Z / Ctrl+Y)')"
					@click="redo">
					↷ {{ t('openbuilt', 'Redo') }}
				</button>
			</div>
			<div class="page-designer__toolbar-group">
				<button
					type="button"
					class="page-designer__tool-btn page-designer__tool-btn--primary"
					:disabled="!canSaveAndPreview"
					@click="saveAndPreview">
					{{ t('openbuilt', 'Save & open preview') }}
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
						:parent-route="selectedPage.route || ''"
						@update:config="onConfigUpdate" />
				</div>
				<div v-else class="page-designer__empty">
					<p>{{ t('openbuilt', 'Select a page on the left, or add one to start designing.') }}</p>
				</div>
			</section>

			<aside class="page-designer__right">
				<!-- TODO(chain-spec-2): live preview pane requires in-memory useAppManifest -->
				<div v-if="!previewAvailable" class="page-designer__preview-fallback">
					<h4>{{ t('openbuilt', 'Live preview') }}</h4>
					<p class="page-designer__preview-message">
						{{ t('openbuilt', 'openbuilt.page-designer.preview.unavailable — chain spec #2 not yet installed. Save and open the built app to preview your changes.') }}
					</p>
					<button
						type="button"
						class="page-designer__preview-btn"
						:disabled="!canSaveAndPreview"
						@click="saveAndPreview">
						{{ t('openbuilt', 'Save & open preview') }}
					</button>
				</div>
				<div class="page-designer__errors">
					<h4>{{ t('openbuilt', 'Validation') }}</h4>
					<p v-if="depthError" class="page-designer__error-row" role="alert">
						{{ t('openbuilt', 'openbuilt.page-designer.menu.error.nesting-depth — menu depth limited to two levels.') }}
					</p>
					<ul v-if="validatorErrors.length" class="page-designer__error-list">
						<li v-for="(err, i) in validatorErrors" :key="i" class="page-designer__error-row">
							{{ err }}
						</li>
					</ul>
					<p v-else-if="!depthError" class="page-designer__ok">
						{{ t('openbuilt', 'No validation errors.') }}
					</p>
				</div>
			</aside>
		</div>
	</div>
</template>

<script>
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
		}
	},
	computed: {
		pages() {
			return Array.isArray(this.manifest && this.manifest.pages) ? this.manifest.pages : []
		},
		menu() {
			return Array.isArray(this.manifest && this.manifest.menu) ? this.manifest.menu : []
		},
		selectedPage() {
			if (this.selectedIndex < 0 || this.selectedIndex >= this.pages.length) {
				return null
			}
			return this.pages[this.selectedIndex]
		},
		validatorErrors() {
			return this.validator.errors.value || []
		},
		canSaveAndPreview() {
			return !!this.slug && this.validatorErrors.length === 0
		},
		canUndo() {
			return !!(this.history && this.history.canUndo.value)
		},
		canRedo() {
			return !!(this.history && this.history.canRedo.value)
		},
	},
	watch: {
		manifest: {
			deep: true,
			immediate: true,
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
		subEditorFor(type) {
			return SUB_EDITOR_MAP[type] || 'StubPageEditor'
		},
		selectPage(index) {
			this.selectedIndex = index
		},
		emitManifest(next) {
			this.$emit('update:manifest', next)
		},
		onPagesUpdate(pages) {
			const next = { ...(this.manifest || {}), pages }
			this.emitManifest(next)
		},
		onMenuUpdate(menu) {
			const next = { ...(this.manifest || {}), menu }
			this.depthError = false
			this.emitManifest(next)
		},
		onDepthViolation() {
			this.depthError = true
		},
		onConfigUpdate(config) {
			if (this.selectedIndex < 0) {
				return
			}
			const pages = this.pages.slice()
			pages[this.selectedIndex] = { ...pages[this.selectedIndex], config }
			const next = { ...(this.manifest || {}), pages }
			this.emitManifest(next)
		},
		saveAndPreview() {
			this.$emit('save-and-preview')
		},
		// --- Undo / redo (OQ-1) -------------------------------------------
		undo() {
			if (!this.history) {
				return
			}
			const prev = this.history.undo()
			if (prev !== null) {
				this.emitManifest(prev)
			}
		},
		redo() {
			if (!this.history) {
				return
			}
			const next = this.history.redo()
			if (next !== null) {
				this.emitManifest(next)
			}
		},
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
		configPathPrefix(configKey) {
			if (this.selectedIndex < 0) {
				return ''
			}
			return `/pages/${this.selectedIndex}/config/${configKey}`
		},
		registerConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (prefix && this.validator && typeof this.validator.register === 'function') {
				this.validator.register(prefix)
			}
		},
		unregisterConfigField(configKey) {
			const prefix = this.configPathPrefix(configKey)
			if (prefix && this.validator && typeof this.validator.unregister === 'function') {
				this.validator.unregister(prefix)
			}
		},
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

.page-designer__preview-fallback {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

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
