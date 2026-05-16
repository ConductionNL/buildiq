<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  Step 3 — Custom chain composer
  Add-row list with drag-to-reorder and keyboard ↑/↓ accessible buttons.
  Only shown when preset === 'custom'.
  spec: openbuilt-app-creation-wizard REQ-OBWIZ-004, REQ-OBWIZ-005, REQ-OBWIZ-006
-->
<template>
	<div class="wizard-step3">
		<h3 class="wizard-step3__heading">
			{{ t('openbuilt', 'Define your version chain') }}
		</h3>
		<p class="wizard-step3__description">
			{{ t('openbuilt', 'Versions are ordered top-to-bottom: upstream (e.g. Development) at top, downstream (e.g. Production) at bottom.') }}
		</p>

		<ul class="wizard-step3__rows" aria-label="Version chain rows">
			<li
				v-for="(row, index) in localVersions"
				:key="row._id"
				class="wizard-step3__row"
				:draggable="true"
				@dragstart="onDragStart(index)"
				@dragover.prevent="onDragOver(index)"
				@drop.prevent="onDrop(index)"
				@dragend="onDragEnd">

				<!-- Drag handle (visual enhancement; ↑/↓ are accessibility path) -->
				<span class="wizard-step3__drag-handle" aria-hidden="true" title="Drag to reorder">⠿</span>

				<!-- Name input -->
				<div class="wizard-step3__row-name">
					<input
						:id="'wizard-version-name-' + index"
						class="wizard-step3__input"
						type="text"
						:value="row.name"
						:placeholder="t('openbuilt', 'Version name (e.g. Production)')"
						autocomplete="off"
						@input="onNameInput(index, $event)" />
				</div>

				<!-- Slug chip + Advanced toggle -->
				<div class="wizard-step3__row-slug">
					<code
						class="wizard-step3__slug-chip"
						:class="{
							'wizard-step3__slug-chip--error': getSlugError(index),
							'wizard-step3__slug-chip--duplicate': isDuplicate(index),
						}">
						{{ row.slug || '—' }}
					</code>
					<button
						type="button"
						class="wizard-step3__advanced-toggle"
						@click="toggleAdvanced(index)">
						{{ advancedOpen[index] ? t('openbuilt', 'Hide') : t('openbuilt', 'Advanced') }}
					</button>
				</div>

				<div v-if="advancedOpen[index]" class="wizard-step3__advanced">
					<input
						:id="'wizard-version-slug-' + index"
						class="wizard-step3__input"
						:class="{ 'wizard-step3__input--error': getSlugError(index) }"
						type="text"
						:value="row.slug"
						:placeholder="t('openbuilt', 'kebab-case-slug')"
						autocomplete="off"
						@input="onSlugInput(index, $event)" />
					<p v-if="getSlugError(index)" class="wizard-step3__error-msg" role="alert">
						{{ getSlugError(index) }}
					</p>
				</div>

				<!-- Reorder + remove buttons -->
				<div class="wizard-step3__row-actions">
					<button
						type="button"
						class="wizard-step3__btn-icon"
						:disabled="index === 0"
						:aria-label="t('openbuilt', 'Move version up')"
						:title="t('openbuilt', 'Move up')"
						@click="moveUp(index)">
						↑
					</button>
					<button
						type="button"
						class="wizard-step3__btn-icon"
						:disabled="index === localVersions.length - 1"
						:aria-label="t('openbuilt', 'Move version down')"
						:title="t('openbuilt', 'Move down')"
						@click="moveDown(index)">
						↓
					</button>
					<button
						type="button"
						class="wizard-step3__btn-icon wizard-step3__btn-remove"
						:aria-label="t('openbuilt', 'Remove version')"
						:title="t('openbuilt', 'Remove')"
						@click="removeRow(index)">
						×
					</button>
				</div>
			</li>
		</ul>

		<p v-if="minRowError" class="wizard-step3__error-msg" role="alert">
			{{ minRowError }}
		</p>

		<button type="button" class="wizard-step3__add-btn" @click="addRow">
			+ {{ t('openbuilt', 'Add version') }}
		</button>
	</div>
</template>

<script>
import { toKebabCase, validateSlug } from '../../utils/slugPattern.js'

let _idCounter = 0
function nextId() {
	return ++_idCounter
}

export default {
	name: 'Step3Custom',

	props: {
		/**
		 * The current wizard payload (partial, passed down from the wizard shell).
		 */
		payload: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:payload'],

	data() {
		const versions = this.payload.versions && this.payload.versions.length > 0
			? this.payload.versions.map(v => ({ ...v, _id: nextId(), _slugManual: false }))
			: [{ name: 'Production', slug: 'production', _id: nextId(), _slugManual: false }]

		return {
			localVersions: versions,
			advancedOpen: {},
			minRowError: null,
			dragFromIndex: null,
		}
	},

	computed: {
		slugErrors() {
			return this.localVersions.map((row) => {
				if (!row.name) return t('openbuilt', 'Version name must not be empty.')
				const result = validateSlug(row.slug)
				return result.valid ? null : result.message
			})
		},

		duplicateSlugs() {
			const seen = {}
			const dupes = new Set()
			this.localVersions.forEach((row, idx) => {
				const s = (row.slug || '').toLowerCase()
				if (seen[s] !== undefined) {
					dupes.add(seen[s])
					dupes.add(idx)
				} else {
					seen[s] = idx
				}
			})
			return dupes
		},

		isValid() {
			if (this.localVersions.length === 0) return false
			if (this.duplicateSlugs.size > 0) return false
			return this.slugErrors.every(e => e === null)
		},
	},

	mounted() {
		// Emit initial validity + versions so the parent wizard shell can
		// enable/disable the Next button before the user makes any change.
		this.emit()
	},

	watch: {
		isValid(_newVal) {
			this.emit()
		},

		localVersions: {
			deep: true,
			handler() {
				this.emit()
			},
		},
	},

	methods: {
		emit() {
			const clean = this.localVersions.map(({ name, slug }) => ({ name, slug }))
			this.$emit('update:payload', {
				versions: clean,
				_step3Valid: this.isValid,
			})
		},

		getSlugError(index) {
			if (this.isDuplicate(index)) {
				return t('openbuilt', `Slug \`${this.localVersions[index].slug}\` is already used in this chain`)
			}

			return this.slugErrors[index] || null
		},

		isDuplicate(index) {
			return this.duplicateSlugs.has(index)
		},

		onNameInput(index, event) {
			const name = event.target.value
			this.localVersions[index].name = name
			if (!this.localVersions[index]._slugManual) {
				this.localVersions[index].slug = toKebabCase(name)
			}

			this.minRowError = null
		},

		onSlugInput(index, event) {
			this.localVersions[index]._slugManual = true
			this.localVersions[index].slug = event.target.value
		},

		toggleAdvanced(index) {
			this.$set(this.advancedOpen, index, !this.advancedOpen[index])
		},

		addRow() {
			this.localVersions.push({
				name: '',
				slug: '',
				_id: nextId(),
				_slugManual: false,
			})
			this.minRowError = null
		},

		removeRow(index) {
			if (this.localVersions.length <= 1) {
				this.minRowError = t('openbuilt', 'At least one version is required')
				return
			}

			this.localVersions.splice(index, 1)
			this.minRowError = null
		},

		moveUp(index) {
			if (index === 0) return
			const item = this.localVersions.splice(index, 1)[0]
			this.localVersions.splice(index - 1, 0, item)
		},

		moveDown(index) {
			if (index >= this.localVersions.length - 1) return
			const item = this.localVersions.splice(index, 1)[0]
			this.localVersions.splice(index + 1, 0, item)
		},

		onDragStart(index) {
			this.dragFromIndex = index
		},

		onDragOver(_index) {
			// Allow drop by calling .prevent in the template
		},

		onDrop(toIndex) {
			if (this.dragFromIndex === null || this.dragFromIndex === toIndex) return
			const item = this.localVersions.splice(this.dragFromIndex, 1)[0]
			this.localVersions.splice(toIndex, 0, item)
			this.dragFromIndex = null
		},

		onDragEnd() {
			this.dragFromIndex = null
		},
	},
}
</script>

<style scoped>
.wizard-step3 {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.wizard-step3__heading {
	font-size: 1.1rem;
	font-weight: 600;
	margin: 0;
}

.wizard-step3__description {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.875rem;
	margin: 0;
}

.wizard-step3__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wizard-step3__row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, #fff);
	flex-wrap: wrap;
}

.wizard-step3__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast, #aaa);
	font-size: 1.2rem;
	padding: 2px 0;
	user-select: none;
}

.wizard-step3__row-name {
	flex: 1 1 180px;
}

.wizard-step3__row-slug {
	display: flex;
	align-items: center;
	gap: 6px;
	flex: 0 0 auto;
}

.wizard-step3__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	font-size: 0.875rem;
	background: var(--color-main-background, #fff);
	box-sizing: border-box;
}

.wizard-step3__input--error {
	border-color: var(--color-error, #e9322d);
}

.wizard-step3__slug-chip {
	padding: 2px 8px;
	border-radius: 4px;
	background: var(--color-background-dark, #f5f5f5);
	font-size: 0.8rem;
}

.wizard-step3__slug-chip--error,
.wizard-step3__slug-chip--duplicate {
	background: var(--color-error-soft, #fdecea);
	color: var(--color-error, #e9322d);
}

.wizard-step3__advanced-toggle {
	border: none;
	background: none;
	color: var(--color-primary, #4376fc);
	cursor: pointer;
	font-size: 0.75rem;
	padding: 0;
}

.wizard-step3__advanced {
	flex: 1 1 100%;
	margin-top: 4px;
}

.wizard-step3__error-msg {
	color: var(--color-error, #e9322d);
	font-size: 0.8rem;
	margin: 4px 0 0;
	flex: 1 1 100%;
}

.wizard-step3__row-actions {
	display: flex;
	gap: 4px;
	align-items: center;
}

.wizard-step3__btn-icon {
	border: none;
	background: none;
	cursor: pointer;
	font-size: 1rem;
	padding: 2px 4px;
	border-radius: 4px;
	color: var(--color-text-maxcontrast, #555);
}

.wizard-step3__btn-icon:disabled {
	opacity: 0.4;
	cursor: not-allowed;
}

.wizard-step3__btn-remove {
	color: var(--color-error, #e9322d);
}

.wizard-step3__add-btn {
	padding: 8px 14px;
	border: 1px dashed var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: none;
	cursor: pointer;
	color: var(--color-primary, #4376fc);
	font-size: 0.875rem;
	text-align: left;
}

.wizard-step3__add-btn:hover {
	background: var(--color-background-hover, #f5f5f5);
}
</style>
