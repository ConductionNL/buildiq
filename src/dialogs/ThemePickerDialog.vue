<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ThemePickerDialog — standalone dialog (modal-isolation rule) to pick an
  - NL Design token set for a virtual app (REQ-NTS-002).
  -
  - List-population strategy, in order:
  -   (a) admin GET /apps/nldesign/settings/tokensets — used only when the
  -       session is admin; a 403 is treated as "list unavailable" (probed once
  -       per session, never surfaced as an error);
  -   (b) [flagged, NOT YET BUILT] a non-admin nldesign list endpoint — all of
  -       nldesign's settings/* is AuthorizedAdminSetting(Admin::class) today
  -       (verified 2026-06-15), so this leg is a feature-probe stub that
  -       activates automatically once nldesign ships the endpoint;
  -   (c) validated free-text fallback — a token-set id input verified by
  -       fetching the static css/tokens/<id>.css asset (404 ⇒ inline error),
  -       deriving swatches from the fetched `--nldesign-color-*` variables.
  -
  - "Default (Nextcloud)" removes runtime.theme. Live-preview toggle drives the
  - same applier as the runtime (useAppTheme) against the designer preview root
  - and reverts on cancel.
  -->
<template>
	<NcDialog
		:open="open"
		:name="t('openbuild', 'Choose an NL Design theme')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-theme-picker">
			<p v-if="!nldesignAvailable" class="ob-theme-picker__warn">
				{{ t('openbuild', 'NL Design (nldesign) is not installed or enabled on this instance.') }}
			</p>

			<!-- (a) admin list path -->
			<NcSelect
				v-if="listAvailable"
				v-model="selectedOption"
				:input-label="t('openbuild', 'Token set')"
				:options="tokenSetOptions"
				:loading="loadingList"
				label="label" />

			<!-- (c) validated free-text fallback (non-admin / no list) -->
			<div v-else class="ob-theme-picker__freetext">
				<NcTextField
					:value="freeTextId"
					:label="t('openbuild', 'Token set id')"
					:placeholder="t('openbuild', 'e.g. rijkshuisstijl')"
					@update:value="onFreeTextInput" />
				<p class="ob-theme-picker__hint">
					{{ t('openbuild', 'A visual token-set list is only available to administrators today. Enter an NL Design token-set id; it is validated against the published stylesheet.') }}
				</p>
				<p v-if="freeTextError" class="ob-theme-picker__error" role="alert">
					{{ t('openbuild', 'Unknown token set — no published stylesheet was found for this id.') }}
				</p>
			</div>

			<!-- swatches + description for the resolved candidate -->
			<div v-if="candidate" class="ob-theme-picker__candidate">
				<span class="ob-theme-picker__swatch" :style="{ background: candidate.primaryColor || 'var(--color-primary-element)' }" />
				<span class="ob-theme-picker__swatch" :style="{ background: candidate.backgroundColor || 'var(--color-main-background)' }" />
				<div class="ob-theme-picker__candidate-meta">
					<strong>{{ candidate.tokenSetName }}</strong>
					<span v-if="candidate.description" class="ob-theme-picker__candidate-desc">{{ candidate.description }}</span>
				</div>
			</div>

			<label class="ob-theme-picker__toggle">
				<input v-model="livePreview" type="checkbox" @change="onPreviewToggle">
				{{ t('openbuild', 'Live preview in the designer') }}
			</label>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="tertiary" @click="onClearTheme">
				{{ t('openbuild', 'Default (Nextcloud)') }}
			</NcButton>
			<NcButton type="primary" :disabled="!candidate" @click="onSave">
				{{ t('openbuild', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl, generateFilePath } from '@nextcloud/router'

// Session-level memo so the admin-list 403 probe runs at most once.
let listProbe = null

export default {
	name: 'ThemePickerDialog',
	components: { NcDialog, NcButton, NcSelect, NcTextField },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		// The current runtime.theme object (null when none).
		theme: {
			type: Object,
			default: null,
		},
		// Soft capability flag for nldesign.
		nldesignAvailable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['update:open', 'save', 'clear', 'preview'],
	data() {
		return {
			tokenSets: [],
			loadingList: false,
			listAvailable: false,
			selectedOption: null,
			freeTextId: '',
			freeTextError: false,
			freeTextResolved: null,
			livePreview: false,
		}
	},
	computed: {
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		tokenSetOptions() {
			return this.tokenSets.map((s) => ({
				label: s.name || s.id,
				id: s.id,
				name: s.name || s.id,
				description: s.description || '',
				primaryColor: (s.theming && s.theming.primary_color) || s.primaryColor || '',
				backgroundColor: (s.theming && s.theming.background_color) || s.backgroundColor || '',
			}))
		},
		/**
		 * The resolved theme candidate to save, from whichever population path
		 * produced one (admin list selection or validated free-text).
		 *
		 * @return {?object} - `{ tokenSet, tokenSetName, primaryColor, backgroundColor, description }`.
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		candidate() {
			if (this.listAvailable && this.selectedOption) {
				return {
					tokenSet: this.selectedOption.id,
					tokenSetName: this.selectedOption.name,
					primaryColor: this.selectedOption.primaryColor,
					backgroundColor: this.selectedOption.backgroundColor,
					description: this.selectedOption.description,
				}
			}
			return this.freeTextResolved
		},
	},
	watch: {
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				if (this.nldesignAvailable) {
					this.populateList()
				}
			} else {
				this.revertPreview()
			}
		},
	},
	methods: {
		/**
		 * Seed the form from the current theme when reopening.
		 *
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		hydrate() {
			this.freeTextError = false
			this.livePreview = false
			if (this.theme && this.theme.tokenSet) {
				this.freeTextId = this.theme.tokenSet
				this.freeTextResolved = {
					tokenSet: this.theme.tokenSet,
					tokenSetName: this.theme.tokenSetName || this.theme.tokenSet,
					primaryColor: (this.theme.preview && this.theme.preview.primaryColor) || '',
					backgroundColor: (this.theme.preview && this.theme.preview.backgroundColor) || '',
				}
				this.selectedOption = { label: this.theme.tokenSetName || this.theme.tokenSet, id: this.theme.tokenSet, name: this.theme.tokenSetName || this.theme.tokenSet, primaryColor: '', backgroundColor: '', description: '' }
			} else {
				this.freeTextId = ''
				this.freeTextResolved = null
				this.selectedOption = null
			}
		},
		/**
		 * Populate the picker list via the admin endpoint when the session can
		 * read it; on 403 (non-admin) fall back to the validated free-text path.
		 * The 403 probe is memoised per session so a non-admin builder is not
		 * re-probed on every open (REQ-NTS-002).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		async populateList() {
			if (listProbe === 'unavailable') {
				this.listAvailable = false
				return
			}
			this.loadingList = true
			try {
				const url = generateUrl('/apps/nldesign/settings/tokensets')
				const { data } = await axios.get(url)
				const list = (data && (data.results || data.tokenSets || data.sets || data)) || []
				this.tokenSets = Array.isArray(list) ? list : []
				this.listAvailable = this.tokenSets.length > 0
				listProbe = this.listAvailable ? 'available' : 'unavailable'
			} catch {
				// 403 (non-admin) or any error → list unavailable, free-text path.
				this.listAvailable = false
				listProbe = 'unavailable'
			} finally {
				this.loadingList = false
			}
		},
		/**
		 * Debounced-ish free-text validation: verify a token-set id by fetching
		 * its static stylesheet and derive swatches from the variables.
		 *
		 * @param {string} value - the entered id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		async onFreeTextInput(value) {
			this.freeTextId = value
			this.freeTextError = false
			this.freeTextResolved = null
			const id = (value || '').trim()
			if (!id) {
				return
			}
			try {
				const url = generateFilePath('nldesign', 'css', `tokens/${id}.css`)
				const { data } = await axios.get(url, { responseType: 'text' })
				const css = typeof data === 'string' ? data : String(data || '')
				this.freeTextResolved = {
					tokenSet: id,
					tokenSetName: id,
					primaryColor: this.readVar(css, '--nldesign-color-primary'),
					backgroundColor: this.readVar(css, '--nldesign-color-bg') || this.readVar(css, '--nldesign-color-background'),
				}
			} catch {
				this.freeTextError = true
			}
		},
		/**
		 * Read a CSS custom-property value out of a token stylesheet.
		 *
		 * @param {string} css - the stylesheet text.
		 * @param {string} name - the variable name.
		 * @return {string}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		readVar(css, name) {
			const re = new RegExp(name.replace(/[-]/g, '\\-') + '\\s*:\\s*([^;}]+)')
			const m = re.exec(css)
			return m ? m[1].trim() : ''
		},
		/**
		 * Toggle live preview: emit the candidate (or null) to the host so it
		 * applies/reverts the designer-preview theme.
		 *
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		onPreviewToggle() {
			this.$emit('preview', this.livePreview ? this.buildTheme() : null)
		},
		/**
		 * Revert any live preview (used on cancel/close).
		 *
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002
		 */
		revertPreview() {
			if (this.livePreview) {
				this.livePreview = false
				this.$emit('preview', null)
			}
		},
		/**
		 * Assemble the runtime.theme object from the resolved candidate.
		 *
		 * @return {?object}
		 * @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-001
		 */
		buildTheme() {
			const c = this.candidate
			if (!c) {
				return null
			}
			const theme = { source: 'nldesign', tokenSet: c.tokenSet, tokenSetName: c.tokenSetName }
			const preview = {}
			if (c.primaryColor) {
				preview.primaryColor = c.primaryColor
			}
			if (c.backgroundColor) {
				preview.backgroundColor = c.backgroundColor
			}
			if (Object.keys(preview).length) {
				theme.preview = preview
			}
			return theme
		},
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onSave() {
			const theme = this.buildTheme()
			if (!theme) {
				return
			}
			this.revertPreview()
			this.$emit('save', theme)
			this.$emit('update:open', false)
		},
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onClearTheme() {
			this.revertPreview()
			this.$emit('clear')
			this.$emit('update:open', false)
		},
		/** @spec openspec/changes/nldesign-theme-selection/specs/nldesign-theme-selection/spec.md#req-nts-002 */
		onClose() {
			this.revertPreview()
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-theme-picker {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.ob-theme-picker__warn {
	color: var(--color-warning-text, var(--color-warning));
}
.ob-theme-picker__error {
	color: var(--color-error);
}
.ob-theme-picker__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.ob-theme-picker__candidate {
	display: flex;
	align-items: center;
	gap: 8px;
}
.ob-theme-picker__swatch {
	width: 24px;
	height: 24px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	display: inline-block;
}
.ob-theme-picker__candidate-meta {
	display: flex;
	flex-direction: column;
}
.ob-theme-picker__candidate-desc {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
.ob-theme-picker__toggle {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
