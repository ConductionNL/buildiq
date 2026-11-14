<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  JourneyStepsManager — the ordered steps of a journey (journey-designer,
  task 1). A form step REFERENCES a form from the form library by id; its
  fields are authored in the form editor and never here. A reference to a
  form that no longer exists is shown on its row, so a journey missing a step
  is not saved as if it were whole.

  @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
-->
<template>
	<div class="journey-steps">
		<ol class="journey-steps__list">
			<li
				v-for="(step, index) in localSteps"
				:key="step.id || index"
				class="journey-steps__row"
				:class="{ 'journey-steps__row--selected': step.id === selected, 'journey-steps__row--broken': isBroken(step) }"
				:data-testid="`journey-step-${index}`">
				<button
					type="button"
					class="journey-steps__select"
					:aria-pressed="step.id === selected ? 'true' : 'false'"
					@click="$emit('select', step.id)">
					{{ typeLabel(step.type) }}
				</button>
				<input
					:value="step.title || ''"
					type="text"
					class="journey-steps__title"
					:aria-label="t('buildiq', 'Step title')"
					:placeholder="t('buildiq', 'Step title')"
					@input="update(index, { title: $event.target.value })">
				<select
					v-if="step.type === 'form'"
					:value="step.formId || ''"
					class="journey-steps__form"
					:aria-label="t('buildiq', 'Form')"
					@change="update(index, { formId: $event.target.value })">
					<option value="">
						{{ t('buildiq', '— form —') }}
					</option>
					<option v-if="isBroken(step)" :value="step.formId">
						{{ t('buildiq', 'Missing form {id}', { id: step.formId }) }}
					</option>
					<option v-for="form in formOptions" :key="form.id" :value="form.id">
						{{ form.label }}
					</option>
				</select>
				<textarea
					v-if="step.type === 'confirmation'"
					:value="step.message || ''"
					class="journey-steps__message"
					:aria-label="t('buildiq', 'Confirmation text')"
					:placeholder="t('buildiq', 'Confirmation text')"
					@input="update(index, { message: $event.target.value })" />
				<p
					v-if="isBroken(step)"
					class="journey-steps__broken"
					role="alert"
					data-testid="journey-step-broken">
					{{ t('buildiq', 'The form this step uses no longer exists. Pick another form or remove the step.') }}
				</p>
				<div class="journey-steps__actions">
					<button
						type="button"
						:disabled="index === 0"
						:aria-label="t('buildiq', 'Move step up')"
						data-testid="journey-step-up"
						@click="move(index, index - 1)">
						↑
					</button>
					<button
						type="button"
						:disabled="index === localSteps.length - 1"
						:aria-label="t('buildiq', 'Move step down')"
						data-testid="journey-step-down"
						@click="move(index, index + 1)">
						↓
					</button>
					<button
						type="button"
						:aria-label="t('buildiq', 'Remove step')"
						data-testid="journey-step-remove"
						@click="remove(index)">
						×
					</button>
				</div>
			</li>
		</ol>
		<div class="journey-steps__add">
			<button
				v-for="type in stepTypes"
				:key="type"
				type="button"
				:data-testid="`journey-add-${type}`"
				@click="add(type)">
				{{ t('buildiq', 'Add {type} step', { type: typeLabel(type) }) }}
			</button>
		</div>
	</div>
</template>

<script>
import { JOURNEY_STEP_TYPES, moveStep, storedFormStep } from '../../services/journeyValidation.js'

export default {
	name: 'JourneyStepsManager',

	props: {
		/** The journey's steps. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The form objects by id, to tell a live reference from a broken one. */
		forms: {
			type: Object,
			default: () => ({}),
		},

		/** The forms a step can reference: `[{ id, label }]`. */
		formOptions: {
			type: Array,
			default: () => [],
		},

		/** The id of the step being edited. */
		selected: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue', 'select'],

	computed: {
		/**
		 * The steps, or none.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		localSteps() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/** @return {ReadonlyArray<string>} The step types a journey holds. */
		stepTypes() {
			return JOURNEY_STEP_TYPES
		},
	},

	methods: {
		/**
		 * The label of a step type.
		 *
		 * @param {string} type - the step type.
		 * @return {string}
		 */
		typeLabel(type) {
			const labels = {
				form: this.t('buildiq', 'Form'),
				review: this.t('buildiq', 'Review'),
				confirmation: this.t('buildiq', 'Confirmation'),
			}
			return labels[type] || String(type)
		},

		/**
		 * Whether a form step references a form that is not there.
		 *
		 * @param {object} step - the step.
		 * @return {boolean}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		isBroken(step) {
			const formId = String(step.formId || '')
			return step.type === 'form' && formId !== '' && !this.forms[formId]
		},

		/**
		 * Emit the steps with one step changed. A form step is emitted as it
		 * is stored, so no field definition rides along.
		 *
		 * @param {number} index - the step's position.
		 * @param {object} patch - the changed keys.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		update(index, patch) {
			const steps = [...this.localSteps]
			const next = { ...steps[index], ...patch }
			steps[index] = next.type === 'form' ? storedFormStep(next) : next
			this.$emit('update:modelValue', steps)
		},

		/**
		 * Emit the steps in a new order; every step is unchanged.
		 *
		 * @param {number} from - the step's position.
		 * @param {number} to - where it goes.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		move(from, to) {
			this.$emit('update:modelValue', moveStep(this.localSteps, from, to))
		},

		/**
		 * Emit the steps without one.
		 *
		 * @param {number} index - the step's position.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		remove(index) {
			this.$emit('update:modelValue', this.localSteps.filter((_, i) => i !== index))
		},

		/**
		 * Emit the steps with a new step of a type at the end, and select it.
		 *
		 * @param {string} type - the step type.
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		add(type) {
			const ids = new Set(this.localSteps.map((step) => String(step.id || '')))
			let n = this.localSteps.length + 1
			while (ids.has(`step-${n}`)) {
				n++
			}
			const step = { id: `step-${n}`, type, title: this.typeLabel(type) }
			this.$emit('update:modelValue', [...this.localSteps, type === 'form' ? storedFormStep(step) : step])
			this.$emit('select', step.id)
		},
	},
}
</script>

<style scoped>
.journey-steps__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.journey-steps__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.journey-steps__row--selected {
	border-color: var(--color-primary-element);
}

.journey-steps__row--broken {
	border-color: var(--color-error);
}

.journey-steps__broken {
	flex-basis: 100%;
	margin: 0;
	color: var(--color-error-text);
}

.journey-steps__title {
	flex: 1 1 160px;
}

.journey-steps__message {
	flex-basis: 100%;
}

.journey-steps__add {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 12px;
}
</style>
