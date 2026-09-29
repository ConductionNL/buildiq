<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - CalculationEditor: the Calculations section of the schema designer.
  - A maker adds a calculated field, picks its type, builds its expression
  - from OpenRegister's operator catalogue and tries it on a sample record
  - before saving. Calculations are stored as the `calculation` key on their
  - property; the `x-openregister-calculations` block written in a register
  - file is listed read-only and saved back unchanged (REQ-BQCF-001 to 004).
  -->
<template>
	<section class="buildiq-calculation-editor">
		<header class="buildiq-calculation-editor__header">
			<h3>{{ t('buildiq', 'Calculations') }}</h3>
		</header>

		<NcNoteCard v-if="catalogueLoaded && !canEdit" type="info">
			{{
				t(
					'buildiq',
					'This OpenRegister does not publish its calculation operators, so calculations cannot be edited here. Calculations already on this schema are listed below.',
				)
			}}
		</NcNoteCard>

		<ul class="buildiq-calculation-editor__list">
			<li
				v-for="(calc, name) in annotation"
				:key="`annotation-${name}`"
				class="buildiq-calculation-editor__item"
				:data-calculation="name">
				<strong>{{ name }}</strong>
				<span class="buildiq-calculation-editor__meta">
					{{
						t(
							'buildiq',
							'Set in the register file, so it cannot be edited here.',
						)
					}}
				</span>
				<pre class="buildiq-calculation-editor__readonly">{{
					format(calc)
				}}</pre>
			</li>

			<li
				v-for="(calc, name) in propertyCalculations"
				:key="`property-${name}`"
				class="buildiq-calculation-editor__item"
				:data-calculation="name">
				<div class="buildiq-calculation-editor__row">
					<strong>{{ name }}</strong>
					<NcSelect
						v-if="canEdit"
						:inputLabel="t('buildiq', 'Type of the result')"
						:modelValue="calc.type"
						:options="types"
						:clearable="false"
						@update:modelValue="setType(name, $event)" />
					<span v-else>{{ calc.type }}</span>
					<NcButton
						v-if="canEdit"
						:ariaLabel="
							t('buildiq', 'Remove the calculation of {name}', {
								name,
							})
						"
						@click="removeCalculation(name)">
						{{ t('buildiq', 'Remove') }}
					</NcButton>
				</div>

				<p
					v-for="(message, index) in refusals[name] || []"
					:key="index"
					role="alert"
					class="buildiq-calculation-editor__refusal">
					{{ message }}
				</p>

				<ExpressionNodeEditor
					v-if="canEdit"
					:node="calc.expression"
					:fieldNames="fieldNames"
					:operators="operators"
					@update:node="setExpression(name, $event)" />
				<pre v-else class="buildiq-calculation-editor__readonly">{{
					format(calc)
				}}</pre>

				<div v-if="canEdit" class="buildiq-calculation-editor__trial">
					<NcTextField
						v-for="field in sampleFields(name)"
						:key="field"
						:modelValue="sampleText(name, field)"
						:label="t('buildiq', 'Sample value for {field}', { field })"
						@update:modelValue="setSample(name, field, $event)" />
					<NcButton @click="runTrial(name)">
						{{ t('buildiq', 'Try') }}
					</NcButton>
					<p
						v-if="trials[name]"
						class="buildiq-calculation-editor__result"
						:data-trial="name"
						aria-live="polite">
						<template v-if="trials[name].ok">
							{{
								t('buildiq', 'Result: {value}', {
									value: format(trials[name].value),
								})
							}}
						</template>
						<template v-else>
							{{
								t('buildiq', 'Not calculated: {reason}', {
									reason: trials[name].error?.message || '',
								})
							}}
						</template>
					</p>
				</div>
			</li>
		</ul>

		<p v-if="isEmpty" class="buildiq-calculation-editor__empty">
			{{ t('buildiq', 'No calculations declared on this schema.') }}
		</p>

		<div v-if="canEdit" class="buildiq-calculation-editor__add">
			<NcTextField
				:modelValue="newName"
				:label="t('buildiq', 'Name of the calculated field')"
				:error="newNameError !== ''"
				:helperText="newNameError"
				@update:modelValue="newName = $event" />
			<NcButton
				:disabled="newName === '' || newNameError !== ''"
				@click="addCalculation">
				{{ t('buildiq', 'Add calculated field') }}
			</NcButton>
		</div>
	</section>
</template>

<script>
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ExpressionNodeEditor, { parseLiteral } from './ExpressionNodeEditor.vue'
import {
	CALCULATION_TYPES,
	loadOperatorCatalogue,
	referencedFields,
	tryCalculation,
} from '../../services/calculations.js'

export default {
	name: 'CalculationEditor',
	components: {
		ExpressionNodeEditor,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	props: {
		/** The register file's `x-openregister-calculations` block, read-only. */
		calculations: { type: [Object, Array], default: null },
		/** Property-level calculations by property name. */
		propertyCalculations: { type: Object, default: () => ({}) },
		/** The schema's field names. */
		fieldNames: { type: Array, default: () => [] },
		/** Save refusals by calculation name. */
		refusals: { type: Object, default: () => ({}) },
	},

	emits: ['update:propertyCalculations'],
	data() {
		return {
			operators: [],
			catalogueLoaded: false,
			newName: '',
			samples: {},
			trials: {},
			types: CALCULATION_TYPES,
		}
	},

	computed: {
		canEdit() {
			return this.catalogueLoaded && this.operators.length > 0
		},

		annotation() {
			return this.calculations
				&& typeof this.calculations === 'object'
				&& !Array.isArray(this.calculations)
				? this.calculations
				: {}
		},

		isEmpty() {
			return (
				Object.keys(this.annotation).length === 0
				&& Object.keys(this.propertyCalculations || {}).length === 0
			)
		},

		/**
		 * Why the typed name cannot be added, or an empty string.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @return {string} The reason.
		 */
		newNameError() {
			const name = this.newName.trim()
			if (name === '') {
				return ''
			}
			if (!/^[A-Za-z_][A-Za-z0-9_]*$/.test(name)) {
				return this.t(
					'buildiq',
					'Use letters, digits and underscores, starting with a letter.',
				)
			}
			if (Object.hasOwn(this.annotation, name)) {
				return this.t(
					'buildiq',
					'The register file already calculates this field.',
				)
			}
			if (Object.hasOwn(this.propertyCalculations || {}, name)) {
				return this.t('buildiq', 'This field already has a calculation.')
			}
			return ''
		},
	},

	async mounted() {
		const catalogue = await loadOperatorCatalogue()
		this.operators = catalogue ? catalogue.operators : []
		this.catalogueLoaded = true
	},

	methods: {
		/**
		 * Pretty-print a value for a read-only block.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @param {unknown} value The value.
		 * @return {string} The text.
		 */
		format(value) {
			if (typeof value === 'string') {
				return value
			}
			try {
				return JSON.stringify(value, null, 2)
			} catch {
				return ''
			}
		},

		/**
		 * Emit the calculations with one entry replaced or removed.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @param {string} name The calculation.
		 * @param {object|null} calc Its declaration, or null to remove it.
		 * @return {void}
		 */
		emitWith(name, calc) {
			const next = { ...(this.propertyCalculations || {}) }
			if (calc === null) {
				delete next[name]
			} else {
				next[name] = calc
			}
			this.$emit('update:propertyCalculations', next)
		},

		/**
		 * Add a calculated field with the typed name.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @return {void}
		 */
		addCalculation() {
			const name = this.newName.trim()
			if (name === '' || this.newNameError !== '') {
				return
			}
			this.emitWith(name, { type: 'number', expression: null })
			this.newName = ''
		},

		/**
		 * Remove a calculation; its property stays as a plain field.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @param {string} name The calculation.
		 * @return {void}
		 */
		removeCalculation(name) {
			this.emitWith(name, null)
		},

		/**
		 * Change the result type of a calculation.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-adds-a-calculated-field-in-the-schema-designer-req-bqcf-001
		 * @param {string} name The calculation.
		 * @param {string} type The new type.
		 * @return {void}
		 */
		setType(name, type) {
			this.emitWith(name, { ...this.propertyCalculations[name], type })
		},

		/**
		 * Replace the expression of a calculation.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-an-expression-is-built-from-the-published-operators-req-bqcf-002
		 * @param {string} name The calculation.
		 * @param {unknown} expression The new expression.
		 * @return {void}
		 */
		setExpression(name, expression) {
			this.emitWith(name, { ...this.propertyCalculations[name], expression })
		},

		/**
		 * The fields a trial asks sample values for.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
		 * @param {string} name The calculation.
		 * @return {Array<string>} Field names.
		 */
		sampleFields(name) {
			return referencedFields(this.propertyCalculations[name]?.expression)
		},

		/**
		 * The typed sample value of one field.
		 *
		 * @param {string} name The calculation.
		 * @param {string} field The field.
		 * @return {string} The text.
		 */
		sampleText(name, field) {
			return this.samples[name]?.[field] ?? ''
		},

		/**
		 * Set a sample value for a trial.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
		 * @param {string} name The calculation.
		 * @param {string} field The field.
		 * @param {string} text The typed value.
		 * @return {void}
		 */
		setSample(name, field, text) {
			this.samples = {
				...this.samples,
				[name]: { ...(this.samples[name] || {}), [field]: text },
			}
		},

		/**
		 * Evaluate the unsaved calculation against the sample. Nothing is saved.
		 *
		 * @spec openspec/changes/data-calculated-field-authoring/specs/data-calculated-fields/spec.md#requirement-a-maker-tries-a-calculation-before-saving-req-bqcf-003
		 * @param {string} name The calculation.
		 * @return {Promise<void>}
		 */
		async runTrial(name) {
			const sample = {}
			for (const [field, text] of Object.entries(this.samples[name] || {})) {
				sample[field] = parseLiteral(text)
			}
			const result = await tryCalculation(
				this.propertyCalculations[name],
				sample,
			)
			this.trials = { ...this.trials, [name]: result }
		},
	},
}
</script>

<style scoped>
.buildiq-calculation-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buildiq-calculation-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.buildiq-calculation-editor__list {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.buildiq-calculation-editor__item,
.buildiq-calculation-editor__trial,
.buildiq-calculation-editor__add {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.buildiq-calculation-editor__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.buildiq-calculation-editor__meta,
.buildiq-calculation-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-calculation-editor__refusal {
	margin: 0;
	color: var(--color-error-text);
}

.buildiq-calculation-editor__readonly {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 13px;
	overflow: auto;
}
</style>
