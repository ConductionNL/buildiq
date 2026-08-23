<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormStepsManager — authors `config.steps[]` for `type: "form"` pages
  - (the `manifest-form-logic` leaf, REQ-OBFEL-001). Controlled component:
  - `:steps` (config.steps) + `:fields` (config.fields, for the key pool)
  - in, `update:steps` out. Steps reference existing `config.fields[].key`
  - values — this component never edits field definitions.
  -
  - Absent/empty steps renders the single-step state (an "add step"
  - affordance only); deleting the last step emits `null` so the caller
  - (FormPageEditor.update) removes the `steps` key entirely rather than
  - writing `steps: []` (the leaf schema requires `minItems: 1`).
  -
  - Field-key assignment is pool-based: the "Unassigned fields" strip
  - lists every `config.fields[].key` not yet referenced by any step; each
  - step row offers a select of the SAME pool plus an "Assign" button, so
  - the UI cannot create a duplicate assignment on its own (Raw-JSON-authored
  - duplicates are still caught by the leaf validator / formLogic.js).
  -
  - Dangling step->field references (a key no longer present in
  - `config.fields[]`, e.g. after the field was deleted) are warned about
  - live via InlineFieldMark and are NEVER dropped from the manifest
  - (REQ-OBFEL-004 / Decision 5).
  -->
<template>
	<div class="form-steps-manager">
		<div v-if="unassignedKeys.length" class="form-steps-manager__pool">
			<span class="form-steps-manager__pool-label">{{
				t('buildiq', 'Unassigned fields:')
			}}</span>
			<span
				v-for="key in unassignedKeys"
				:key="key"
				class="form-steps-manager__pool-key"
				>{{ key }}</span
			>
			<p v-if="localSteps.length" class="form-steps-manager__pool-note">
				{{
					t(
						'buildiq',
						'Unassigned fields are automatically added to the last step when you save.',
					)
				}}
			</p>
		</div>

		<p v-if="!localSteps.length" class="form-steps-manager__empty">
			{{
				t(
					'buildiq',
					'This form renders as a single step. Add a step to build a multi-step wizard.',
				)
			}}
		</p>

		<div
			v-for="(step, index) in localSteps"
			:key="stepKey(step, index)"
			class="form-steps-manager__step">
			<div class="form-steps-manager__step-header">
				<input
					:value="step.title || ''"
					type="text"
					class="form-steps-manager__field"
					:placeholder="t('buildiq', 'Step title')"
					:aria-label="t('buildiq', 'Step title')"
					@input="onTitleInput(index, $event.target.value)" />
				<input
					:value="step.id || ''"
					type="text"
					class="form-steps-manager__field form-steps-manager__field--narrow"
					:placeholder="t('buildiq', 'step-id')"
					:aria-label="t('buildiq', 'Step id')"
					@input="updateStepField(index, 'id', $event.target.value)" />
				<button
					type="button"
					class="form-steps-manager__icon-button"
					:disabled="index === 0"
					:title="t('buildiq', 'Move step up')"
					@click="moveStep(index, -1)">
					▲
				</button>
				<button
					type="button"
					class="form-steps-manager__icon-button"
					:disabled="index === localSteps.length - 1"
					:title="t('buildiq', 'Move step down')"
					@click="moveStep(index, 1)">
					▼
				</button>
				<button
					type="button"
					class="form-steps-manager__remove"
					:title="t('buildiq', 'Delete step')"
					@click="removeStep(index)">
					✕
				</button>
			</div>
			<input
				:value="step.description || ''"
				type="text"
				class="form-steps-manager__field form-steps-manager__description"
				:placeholder="t('buildiq', 'Description (optional)')"
				:aria-label="t('buildiq', 'Step description')"
				@input="
					updateStepField(index, 'description', $event.target.value)
				" />

			<div class="form-steps-manager__fields">
				<span
					v-for="(key, fIndex) in step.fields || []"
					:key="key + fIndex"
					class="form-steps-manager__field-chip"
					:class="{
						'form-steps-manager__field-chip--dangling': isDangling(key),
					}">
					{{ key }}
					<button
						type="button"
						class="form-steps-manager__chip-remove"
						:title="t('buildiq', 'Remove from step')"
						@click="removeFieldFromStep(index, fIndex)">
						✕
					</button>
				</span>
				<span
					v-if="!(step.fields || []).length"
					class="form-steps-manager__no-fields">
					{{ t('buildiq', 'No fields assigned yet.') }}
				</span>
			</div>
			<InlineFieldMark :error="danglingMark(step)" />

			<div v-if="unassignedKeys.length" class="form-steps-manager__assign">
				<select
					v-model="pendingAssignment[index]"
					class="form-steps-manager__select"
					:aria-label="t('buildiq', 'Field to assign')">
					<option value="">
						{{ t('buildiq', '— select field —') }}
					</option>
					<option v-for="key in unassignedKeys" :key="key" :value="key">
						{{ key }}
					</option>
				</select>
				<button
					type="button"
					:disabled="!pendingAssignment[index]"
					@click="assignField(index)">
					{{ t('buildiq', 'Assign') }}
				</button>
			</div>
		</div>

		<button type="button" class="form-steps-manager__add" @click="addStep">
			+ {{ t('buildiq', 'Add step') }}
		</button>
	</div>
</template>

<script>
import InlineFieldMark from './InlineFieldMark.vue'

/**
 * Derive a kebab-case slug from a human title.
 *
 * @param {string} title - the human title.
 * @return {string}
 */
function slugify(title) {
	return String(title || '')
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

/**
 * Suffix `-2`, `-3`, … until the slug is unique against `taken`.
 *
 * @param {string} base - the candidate slug.
 * @param {string[]} taken - ids already used by other steps.
 * @return {string}
 */
function uniqueSlug(base, taken) {
	if (!base) {
		return base
	}
	if (!taken.includes(base)) {
		return base
	}
	let n = 2
	while (taken.includes(`${base}-${n}`)) {
		n++
	}
	return `${base}-${n}`
}

export default {
	name: 'FormStepsManager',
	components: { InlineFieldMark },
	props: {
		steps: {
			type: Array,
			default: () => [],
		},

		fields: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:steps'],
	data() {
		return {
			// Per-step index -> the key currently selected in that step's
			// "assign from pool" native select (transient UI state only).
			pendingAssignment: {},
		}
	},

	computed: {
		/**
		 * The steps array, tolerant of an absent/non-array prop.
		 *
		 * @return {Array<object>}
		 */
		localSteps() {
			return Array.isArray(this.steps) ? this.steps : []
		},

		/**
		 * `config.fields[].key` values declared on the sibling field list.
		 *
		 * @return {string[]}
		 */
		declaredKeys() {
			return (Array.isArray(this.fields) ? this.fields : [])
				.map((f) => f && f.key)
				.filter((k) => typeof k === 'string' && k !== '')
		},

		/**
		 * Declared field keys not yet referenced by any step's `fields[]`.
		 *
		 * @return {string[]}
		 */
		unassignedKeys() {
			const assigned = new Set()
			this.localSteps.forEach((s) =>
				(Array.isArray(s && s.fields) ? s.fields : []).forEach((k) =>
					assigned.add(k),
				),
			)
			return this.declaredKeys.filter((k) => !assigned.has(k))
		},
	},

	methods: {
		/**
		 * A stable-ish v-for key: prefer the step id, fall back to index.
		 *
		 * @param {object} step - the step entry.
		 * @param {number} index - its array index.
		 * @return {string}
		 */
		stepKey(step, index) {
			return (step && step.id) || `step-${index}`
		},

		/**
		 * Whether a field key referenced by a step no longer exists in
		 * `config.fields[]` (REQ-OBFEL-004).
		 *
		 * @param {string} key - the referenced field key.
		 * @return {boolean}
		 */
		isDangling(key) {
			return !this.declaredKeys.includes(key)
		},

		/**
		 * The `{ hasError, message }` bag for a step's dangling field
		 * references, shaped for `<InlineFieldMark>`. Never mutates —
		 * purely a live, read-only warning (Decision 5).
		 *
		 * @param {object} step - the step entry.
		 * @return {{hasError: boolean, message: string}}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		danglingMark(step) {
			const dangling = (
				Array.isArray(step && step.fields) ? step.fields : []
			).filter((k) => this.isDangling(k))
			if (!dangling.length) {
				return { hasError: false, message: '' }
			}
			return {
				hasError: true,
				message: t('buildiq', 'Step references removed field(s): {keys}', {
					keys: dangling.join(', '),
				}),
			}
		},

		/**
		 * Emit the next steps array — `null` when it would be empty, so the
		 * caller's spread-write (`FormPageEditor.update`) drops the `steps`
		 * key entirely rather than writing the schema-invalid `steps: []`.
		 *
		 * @param {Array<object>} next - the candidate steps array.
		 * @return {void}
		 */
		emitSteps(next) {
			this.$emit('update:steps', next.length ? next : null)
		},

		/**
		 * The ids of every OTHER step (for uniqueness when deriving/typing
		 * an id).
		 *
		 * @param {number} index - the step being edited.
		 * @return {string[]}
		 */
		otherIds(index) {
			return this.localSteps
				.filter((_, i) => i !== index)
				.map((s) => s && s.id)
				.filter(Boolean)
		},

		/**
		 * Update a single step's field, shallow-cloning the array and the
		 * step object so unknown sibling keys survive.
		 *
		 * @param {number} index - the step index.
		 * @param {string} key - the step field to write.
		 * @param {*} value - the new value.
		 * @return {void}
		 */
		updateStepField(index, key, value) {
			const next = this.localSteps.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitSteps(next)
		},

		/**
		 * Title input handler — re-derives the id from the new title as
		 * long as the id still looks auto-derived (i.e. the developer has
		 * not diverged from the auto-slug), matching the schedules-editor
		 * "auto-derived, editable" precedent.
		 *
		 * @param {number} index - the step index.
		 * @param {string} value - the new title.
		 * @return {void}
		 */
		onTitleInput(index, value) {
			const step = this.localSteps[index] || {}
			const prevAutoSlug = uniqueSlug(
				slugify(step.title || ''),
				this.otherIds(index),
			)
			const isTracking = !step.id || step.id === prevAutoSlug
			const next = this.localSteps.slice()
			const updated = { ...step, title: value }
			if (isTracking) {
				updated.id = uniqueSlug(slugify(value), this.otherIds(index))
			}
			next[index] = updated
			this.emitSteps(next)
		},

		/**
		 * Move a step up (-1) or down (+1); no-op past the array bounds.
		 *
		 * @param {number} index - the step index.
		 * @param {number} direction - `-1` (up) or `1` (down).
		 * @return {void}
		 */
		moveStep(index, direction) {
			const target = index + direction
			if (target < 0 || target >= this.localSteps.length) {
				return
			}
			const next = this.localSteps.slice()
			const [item] = next.splice(index, 1)
			next.splice(target, 0, item)
			this.emitSteps(next)
		},

		/**
		 * Delete a step. Its field-key references simply drop out of every
		 * step's `fields[]` — no field DEFINITION is touched, so the keys
		 * reappear in the unassigned pool for free.
		 *
		 * @param {number} index - the step index.
		 * @return {void}
		 */
		removeStep(index) {
			const next = this.localSteps.slice()
			next.splice(index, 1)
			this.emitSteps(next)
		},

		/**
		 * Add a new step with a placeholder title and an empty field list.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		addStep() {
			const next = this.localSteps.slice()
			const title = t('buildiq', 'Step {n}', { n: next.length + 1 })
			const id = uniqueSlug(
				slugify(title),
				next.map((s) => s && s.id).filter(Boolean),
			)
			next.push({ id, title, fields: [] })
			this.emitSteps(next)
		},

		/**
		 * Assign the step's currently pending pool selection to its
		 * `fields[]` list, then clear the selection.
		 *
		 * @param {number} index - the step index.
		 * @return {void}
		 */
		assignField(index) {
			const key = this.pendingAssignment[index]
			if (!key) {
				return
			}
			const next = this.localSteps.slice()
			const step = next[index] || {}
			next[index] = { ...step, fields: [...(step.fields || []), key] }
			this.pendingAssignment[index] = ''
			this.emitSteps(next)
		},

		/**
		 * Remove one field-key reference from a step, returning it to the
		 * unassigned pool.
		 *
		 * @param {number} index - the step index.
		 * @param {number} fieldIndex - the index within that step's `fields[]`.
		 * @return {void}
		 */
		removeFieldFromStep(index, fieldIndex) {
			const next = this.localSteps.slice()
			const step = next[index] || {}
			const fields = (step.fields || []).slice()
			fields.splice(fieldIndex, 1)
			next[index] = { ...step, fields }
			this.emitSteps(next)
		},
	},
}
</script>

<style scoped>
.form-steps-manager {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.form-steps-manager__pool {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
	padding: 6px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
}

.form-steps-manager__pool-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.form-steps-manager__pool-key {
	padding: 2px 6px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 12px;
}

.form-steps-manager__pool-note {
	flex-basis: 100%;
	margin: 2px 0 0;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.form-steps-manager__empty {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.form-steps-manager__step {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.form-steps-manager__step-header {
	display: flex;
	gap: 6px;
	align-items: center;
}

.form-steps-manager__field,
.form-steps-manager__select {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.form-steps-manager__field--narrow {
	flex: 0 0 140px;
}

.form-steps-manager__description {
	width: 100%;
}

.form-steps-manager__icon-button,
.form-steps-manager__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-steps-manager__remove {
	color: var(--color-error, var(--color-main-text));
}

.form-steps-manager__fields {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	align-items: center;
}

.form-steps-manager__field-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 6px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	font-size: 12px;
}

.form-steps-manager__field-chip--dangling {
	outline: 1px solid var(--color-error);
}

.form-steps-manager__chip-remove {
	background: transparent;
	border: none;
	cursor: pointer;
	color: inherit;
	font-size: 11px;
	line-height: 1;
}

.form-steps-manager__no-fields {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.form-steps-manager__assign {
	display: flex;
	gap: 6px;
	align-items: center;
}

.form-steps-manager__assign button {
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.form-steps-manager__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
