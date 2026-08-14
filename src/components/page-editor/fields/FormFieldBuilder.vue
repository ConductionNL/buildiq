<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormFieldBuilder — authors the `formField` $def with validation rules.
  - Used by FormPageEditor AND SettingsPageEditor's flat-field section bodies.
  - REQ-OBPD-006.
  -
  - `show-logic` (default false, opt-in) gates the manifest-form-logic
  - additions (REQ-OBFEL-002/003/004): a per-row expandable details area
  - with a Conditions (`VisibleWhenBuilder`) and Validation
  - (`FieldValidationBuilder`) section, replacing the collapsed row's flat
  - `required` checkbox / `pattern` input with a compact summary. Only
  - `FormPageEditor` passes `show-logic`; `SettingsSectionBuilder`'s
  - settings-page fields keep the original flat inline inputs unchanged —
  - visibleWhen/validation authoring is form-page-only by design (design.md
  - Non-Goals).
  -->
<template>
	<div class="form-field-builder">
		<div
			v-for="(field, index) in localFields"
			:key="index"
			class="form-field-builder__row">
			<input
				:value="field.key || ''"
				type="text"
				class="form-field-builder__field"
				:placeholder="t('openbuild', 'Key')"
				:aria-label="t('openbuild', 'Key')"
				@input="updateField(index, 'key', $event.target.value)" />
			<input
				:value="field.label || ''"
				type="text"
				class="form-field-builder__field"
				:placeholder="t('openbuild', 'Label')"
				:aria-label="t('openbuild', 'Label')"
				@input="updateField(index, 'label', $event.target.value)" />
			<select
				:value="field.type || 'string'"
				class="form-field-builder__field form-field-builder__field--narrow"
				@change="updateField(index, 'type', $event.target.value)">
				<option v-for="t in FIELD_TYPES" :key="t" :value="t">
					{{ t }}
				</option>
			</select>

			<template v-if="!showLogic">
				<label class="form-field-builder__inline">
					<input
						type="checkbox"
						:checked="!!field.required"
						@change="
							updateField(index, 'required', $event.target.checked)
						" />
					{{ t('openbuild', 'Required') }}
				</label>
				<input
					:value="field.pattern || ''"
					type="text"
					class="form-field-builder__field form-field-builder__field--narrow"
					:placeholder="t('openbuild', 'Pattern')"
					:aria-label="t('openbuild', 'Pattern')"
					@input="updateField(index, 'pattern', $event.target.value)" />
			</template>
			<template v-else>
				<span class="form-field-builder__summary">{{
					summaryFor(field)
				}}</span>
				<button
					type="button"
					class="form-field-builder__disclosure"
					:aria-expanded="isExpanded(index)"
					@click="toggleExpanded(index)">
					{{ isExpanded(index) ? '▲' : '▼' }}
					{{ t('openbuild', 'Details') }}
				</button>
			</template>

			<button
				type="button"
				class="form-field-builder__remove"
				:title="t('openbuild', 'Remove field')"
				@click="removeField(index)">
				✕
			</button>

			<div
				v-if="showLogic && isExpanded(index)"
				class="form-field-builder__details">
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('openbuild', 'Conditions')
					}}</span>
					<VisibleWhenBuilder
						:modelValue="field.visibleWhen || null"
						:fieldOptions="siblingKeys(index)"
						@update:modelValue="updateVisibleWhen(index, $event)" />
					<InlineFieldMark :error="danglingConditionMark(field)" />
				</div>
				<div class="form-field-builder__section">
					<span class="form-field-builder__section-label">{{
						t('openbuild', 'Validation')
					}}</span>
					<FieldValidationBuilder
						:modelValue="field.validation || null"
						:legacyRequired="!!field.required"
						:legacyPattern="field.pattern || ''"
						@update:modelValue="updateValidation(index, $event)" />
				</div>
			</div>
		</div>
		<button type="button" class="form-field-builder__add" @click="addField">
			+ {{ t('openbuild', 'Add field') }}
		</button>
	</div>
</template>

<script>
import FieldValidationBuilder from './FieldValidationBuilder.vue'
import InlineFieldMark from './InlineFieldMark.vue'
import VisibleWhenBuilder from './VisibleWhenBuilder.vue'

const FIELD_TYPES = ['string', 'number', 'boolean', 'select', 'textarea', 'date']

export default {
	name: 'FormFieldBuilder',
	components: { VisibleWhenBuilder, FieldValidationBuilder, InlineFieldMark },
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},

		// Opt-in (REQ-OBFEL-002/003/004): mount the Conditions/Validation
		// details area. Only FormPageEditor sets this; SettingsSectionBuilder
		// keeps the original flat required/pattern inputs unchanged.
		showLogic: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],
	data() {
		return {
			FIELD_TYPES,
			expandedIndices: [],
		}
	},

	computed: {
		/**
		 * Observed behaviour of `localFields` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localFields() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * Every declared `key` across the field list (used for the
		 * dangling-condition-reference check, REQ-OBFEL-004).
		 *
		 * @return {string[]}
		 */
		declaredKeys() {
			return this.localFields
				.map((f) => f && f.key)
				.filter((k) => typeof k === 'string' && k !== '')
		},
	},

	methods: {
		/**
		 * Observed behaviour of `updateField` (retrofit annotation).
		 *
		 * @param {number} index - position of the field in the `fields` array.
		 * @param {'key'|'label'|'type'|'required'|'pattern'} key - the formField property
		 *   the bound control edits (`required`/`pattern` only exist in the flat,
		 *   `show-logic`-off layout).
		 * @param {string|boolean} value - the control's new value: input text, the
		 *   selected `type`, or the `required` checkbox state. An empty string or
		 *   `false` DELETES the key, except for the identity keys `key`, `label` and
		 *   `type`, which are always written.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.localFields.slice()
			const current = next[index] || {}
			if (
				(value === '' || value === false)
				&& key !== 'key'
				&& key !== 'label'
				&& key !== 'type'
			) {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write (or delete, on `null`) one field's `visibleWhen` — the
		 * `VisibleWhenBuilder` output (REQ-OBFEL-002). Unknown sibling keys
		 * on the field survive via the shallow spread.
		 *
		 * @param {number} index - the field index.
		 * @param {?object} value - the next `visibleWhen`, or `null` to clear.
		 * @return {void}
		 */
		updateVisibleWhen(index, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null) {
				delete current.visibleWhen
			} else {
				current.visibleWhen = value
			}
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write (or delete, on `null`) one field's structured `validation`
		 * object (REQ-OBFEL-003). Per Decision 4, writing `validation` also
		 * migrates away THIS field's legacy flat `required` / `pattern`
		 * keys — sibling fields that are never edited keep theirs.
		 *
		 * @param {number} index - the field index.
		 * @param {?object} value - the next `validation`, or `null` to clear.
		 * @return {void}
		 */
		updateValidation(index, value) {
			const next = this.localFields.slice()
			const current = { ...next[index] }
			if (value === null) {
				delete current.validation
			} else {
				current.validation = value
			}
			delete current.required
			delete current.pattern
			next[index] = current
			this.$emit('update:modelValue', next)
		},

		/**
		 * Observed behaviour of `addField` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addField() {
			const next = this.localFields.slice()
			next.push({ key: '', label: '', type: 'string' })
			this.$emit('update:modelValue', next)
		},

		/**
		 * Remove a field row, keeping the expanded-details state pinned to the
		 * SAME fields it was pinned to before.
		 *
		 * `expandedIndices` holds positions, not identities, so a bare splice
		 * silently re-points every open panel one field to the left: delete row
		 * 0 while row 1 is open and the panel stays open showing what is now row
		 * 1 — a different field. The author goes on editing conditions and
		 * validation believing they belong to the row they opened, and a live
		 * dangling-reference warning on the shifted field vanishes because its
		 * panel is no longer the expanded one (REQ-OBFEL-004 warns *inside* the
		 * details area). Remap instead: drop the removed index, shift the rest.
		 *
		 * @param {number} index - position of the field to drop from the `fields` array.
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeField(index) {
			const next = this.localFields.slice()
			next.splice(index, 1)
			this.expandedIndices = this.expandedIndices
				.filter((i) => i !== index)
				.map((i) => (i > index ? i - 1 : i))
			this.$emit('update:modelValue', next)
		},

		/**
		 * Sibling `key` values available to the Conditions field picker —
		 * every declared key EXCEPT the field currently being edited
		 * (REQ-OBFEL-002).
		 *
		 * @param {number} index - the field index being edited.
		 * @return {string[]}
		 */
		siblingKeys(index) {
			return this.localFields
				.map((f, i) => (i === index ? null : f && f.key))
				.filter((k) => typeof k === 'string' && k !== '')
		},

		/**
		 * The `{ hasError, message }` bag for a field's dangling LOCAL
		 * `visibleWhen.field` reference (REQ-OBFEL-004) — never mutates,
		 * purely a live, read-only warning (Decision 5).
		 *
		 * @param {object} field - the field entry.
		 * @return {{hasError: boolean, message: string}}
		 */
		danglingConditionMark(field) {
			const vw = field && field.visibleWhen
			if (!vw || vw.endpoint || vw.source || typeof vw.field !== 'string') {
				return { hasError: false, message: '' }
			}
			if (this.declaredKeys.includes(vw.field)) {
				return { hasError: false, message: '' }
			}
			return {
				hasError: true,
				message: t(
					'openbuild',
					"Condition references removed field '{key}'",
					{ key: vw.field },
				),
			}
		},

		/**
		 * A compact collapsed-row summary of a field's logic, e.g.
		 * "required · pattern · 1 condition" (task 4.3).
		 *
		 * @param {object} field - the field entry.
		 * @return {string}
		 */
		summaryFor(field) {
			const validation = field && field.validation
			const parts = []
			const hasRequired = validation
				? !!validation.required
				: !!(field && field.required)
			if (hasRequired) {
				parts.push(t('openbuild', 'required'))
			}
			const hasPattern =
				validation && validation.pattern !== undefined
					? true
					: !!(field && field.pattern)
			if (hasPattern) {
				parts.push(t('openbuild', 'pattern'))
			}
			if (field && field.visibleWhen) {
				parts.push(t('openbuild', '1 condition'))
			}
			return parts.join(' · ')
		},

		/**
		 * Whether a field row's details area is expanded.
		 *
		 * @param {number} index - the field index.
		 * @return {boolean}
		 */
		isExpanded(index) {
			return this.expandedIndices.includes(index)
		},

		/**
		 * Toggle a field row's details-area disclosure.
		 *
		 * @param {number} index - the field index.
		 * @return {void}
		 */
		toggleExpanded(index) {
			const at = this.expandedIndices.indexOf(index)
			if (at === -1) {
				this.expandedIndices.push(index)
			} else {
				this.expandedIndices.splice(at, 1)
			}
		},
	},
}
</script>

<style scoped>
.form-field-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.form-field-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}

.form-field-builder__field {
	flex: 1 1 120px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.form-field-builder__field--narrow {
	flex: 0 0 110px;
}

.form-field-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.form-field-builder__summary {
	flex: 1 1 auto;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.form-field-builder__disclosure {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-field-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-field-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-field-builder__details {
	flex-basis: 100%;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	margin-top: 4px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.form-field-builder__section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.form-field-builder__section-label {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}
</style>
