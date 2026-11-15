<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  JourneyDesigner — authors a journey beside the Page Designer
  (journey-designer, ADR-085 §6): its steps over form-library forms, the
  branch rules and writes of the selected step, and a live preview through
  the real CnJourney. Saving checks what needs no server first, then goes
  through buildiq's save route, which refuses anyone who may not author
  journeys and judges every write through the validator the submit path uses.

  @spec openspec/changes/journey-designer/specs/journey-designer/spec.md
-->
<template>
	<div class="journey-designer">
		<header class="journey-designer__head">
			<input
				v-model="journey.title"
				type="text"
				class="journey-designer__name"
				:aria-label="t('buildiq', 'Journey title')"
				:placeholder="t('buildiq', 'Journey title')"
				data-testid="journey-title">
			<button
				type="button"
				class="journey-designer__save"
				:disabled="saving || loading"
				data-testid="journey-save"
				@click="save">
				{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save journey') }}
			</button>
		</header>

		<p v-if="loading" class="journey-designer__loading">
			{{ t('buildiq', 'Loading the journey…') }}
		</p>
		<p v-if="loadError" class="journey-designer__error" role="alert" data-testid="journey-load-error">
			{{ loadError }}
		</p>
		<p v-if="refusal" class="journey-designer__error" role="alert" data-testid="journey-refusal">
			{{ refusal }}
		</p>
		<p v-if="savedNote" class="journey-designer__saved" role="status" data-testid="journey-saved">
			{{ savedNote }}
		</p>
		<ul v-if="findingTexts.length > 0" class="journey-designer__findings" role="alert" data-testid="journey-findings">
			<li v-for="(text, index) in findingTexts" :key="index">
				{{ text }}
			</li>
		</ul>

		<div v-if="!loading" class="journey-designer__body">
			<section class="journey-designer__steps" :aria-label="t('buildiq', 'Steps')">
				<h3>{{ t('buildiq', 'Steps') }}</h3>
				<JourneyStepsManager
					:modelValue="journey.steps"
					:forms="forms"
					:formOptions="formOptions"
					:selected="selectedId"
					@update:modelValue="setSteps"
					@select="selectedId = $event" />
			</section>

			<section v-if="selectedStep" class="journey-designer__step" :aria-label="t('buildiq', 'Selected step')">
				<h3>{{ selectedStep.title || selectedStep.id }}</h3>
				<h4>{{ t('buildiq', 'Branch rules') }}</h4>
				<JourneyBranchEditor
					:modelValue="selectedStep.branch || []"
					:fields="fieldsHere"
					:targets="branchTargets"
					@update:modelValue="patchSelected({ branch: $event })" />
				<template v-if="selectedStep.type === 'form'">
					<h4>{{ t('buildiq', 'Writes') }}</h4>
					<JourneyWritesEditor
						:modelValue="selectedStep.writes || []"
						:fields="fieldsHere"
						:earlierWrites="earlierWrites"
						:findings="serverFindingsHere"
						@update:modelValue="patchSelected({ writes: $event })" />
				</template>
			</section>

			<JourneyPreviewPane class="journey-designer__preview" :journey="journey" :forms="forms" />
		</div>
	</div>
</template>

<script>
import JourneyBranchEditor from '../components/journey-editor/JourneyBranchEditor.vue'
import JourneyPreviewPane from '../components/journey-editor/JourneyPreviewPane.vue'
import JourneyStepsManager from '../components/journey-editor/JourneyStepsManager.vue'
import JourneyWritesEditor from '../components/journey-editor/JourneyWritesEditor.vue'
import { fetchJourney, fetchJourneyForms, saveJourney } from '../services/journeyStore.js'
import { fieldsAskedBy, journeyFindings } from '../services/journeyValidation.js'

export default {
	name: 'JourneyDesigner',

	components: { JourneyBranchEditor, JourneyPreviewPane, JourneyStepsManager, JourneyWritesEditor },

	props: {
		/** The journey to open; empty or `new` for a new journey. Falls back to the route's `id`. */
		journeyId: {
			type: String,
			default: '',
		},

		/** Axios-like client (tests inject one). */
		client: {
			type: [Object, Function],
			default: undefined,
		},
	},

	data() {
		return {
			journey: { id: '', title: '', description: '', steps: [] },
			forms: {},
			formOptions: [],
			selectedId: '',
			loading: true,
			loadError: '',
			saving: false,
			refusal: '',
			savedNote: '',
			localFindings: [],
			serverFindings: [],
		}
	},

	computed: {
		/** @return {string} The id of the journey being designed, or empty for a new one. */
		openId() {
			const id = this.journeyId || (this.$route && this.$route.params && this.$route.params.id) || ''
			return id === 'new' ? '' : String(id)
		},

		/** @return {number} The position of the selected step, or -1. */
		selectedIndex() {
			return this.journey.steps.findIndex((step) => step.id === this.selectedId)
		},

		/** @return {object|null} The selected step. */
		selectedStep() {
			return this.selectedIndex >= 0 ? this.journey.steps[this.selectedIndex] : null
		},

		/**
		 * The fields a rule or write on the selected step may read.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-branch-rules-must-be-authored-only-from-the-shared-operator-set
		 */
		fieldsHere() {
			return this.selectedIndex >= 0 ? fieldsAskedBy(this.journey, this.forms, this.selectedIndex) : []
		},

		/** @return {Array<object>} The steps a rule can go to: every other step. */
		branchTargets() {
			return this.journey.steps.filter((step) => step.id !== this.selectedId).map((step) => ({ id: step.id, title: step.title }))
		},

		/**
		 * The `as` names of the writes of the steps before the selected one.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-write-mappings-must-be-validated-against-the-target-schema-at-author-time
		 */
		earlierWrites() {
			return this.journey.steps.slice(0, Math.max(this.selectedIndex, 0))
				.flatMap((step) => (Array.isArray(step.writes) ? step.writes : []))
				.map((write) => String(write.as || ''))
				.filter((as) => as !== '')
		},

		/** @return {Array<object>} The server's findings on the selected step. */
		serverFindingsHere() {
			return this.serverFindings.filter((finding) => String(finding.step || '') === this.selectedId)
		},

		/**
		 * Every finding as a sentence.
		 *
		 * @return {Array<string>}
		 */
		findingTexts() {
			return [...this.localFindings, ...this.serverFindings].map((finding) => this.findingText(finding))
		},
	},

	async created() {
		await this.load()
	},

	methods: {
		/**
		 * Load the form library and, for an existing journey, the journey.
		 *
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-designer-must-author-steps-over-existing-form-objects
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const { forms, options } = await fetchJourneyForms(this.client)
				this.forms = forms
				this.formOptions = options
				if (this.openId !== '') {
					this.journey = await fetchJourney(this.openId, this.client)
				}
				this.selectedId = this.journey.steps.length > 0 ? String(this.journey.steps[0].id || '') : ''
				// A broken form reference shows on open, not only on save.
				this.localFindings = journeyFindings(this.journey, this.forms).filter((f) => f.code === 'step-form-gone')
			} catch {
				this.loadError = this.t('buildiq', 'The journey could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Replace the steps; keep a selection that still exists.
		 *
		 * @param {Array<object>} steps - the new steps.
		 */
		setSteps(steps) {
			this.journey = { ...this.journey, steps }
			if (!steps.some((step) => step.id === this.selectedId)) {
				this.selectedId = steps.length > 0 ? String(steps[0].id || '') : ''
			}
		},

		/**
		 * Change the selected step.
		 *
		 * @param {object} patch - the changed keys.
		 */
		patchSelected(patch) {
			const steps = [...this.journey.steps]
			steps[this.selectedIndex] = { ...steps[this.selectedIndex], ...patch }
			this.journey = { ...this.journey, steps }
		},

		/**
		 * A finding as a sentence: the server's own message, or the
		 * designer's sentence for its code.
		 *
		 * @param {object} finding - the finding.
		 * @return {string}
		 */
		findingText(finding) {
			if (finding.message) {
				return this.t('buildiq', 'Step {step}: {message}', { step: finding.step, message: finding.message })
			}
			const params = { step: finding.step, form: finding.form, type: finding.type, goto: finding.goto, operator: finding.operator, field: finding.field, askedAt: finding.askedAt, write: finding.write, property: finding.property }
			const texts = {
				'step-id-missing': this.t('buildiq', 'A step has no id.'),
				'step-id-duplicate': this.t('buildiq', 'Two steps share the id {step}.', params),
				'step-type-unknown': this.t('buildiq', 'Step {step} has the unknown type {type}.', params),
				'step-copies-fields': this.t('buildiq', 'Step {step} carries fields of its own; a step references a form.', params),
				'step-form-missing': this.t('buildiq', 'Step {step} has no form.', params),
				'step-form-gone': this.t('buildiq', 'Step {step} uses form {form}, which no longer exists.', params),
				'branch-target-unknown': this.t('buildiq', 'A branch rule on step {step} goes to {goto}, which is no step.', params),
				'branch-operator-unknown': this.t('buildiq', 'A branch rule on step {step} uses the unknown operator {operator}.', params),
				'branch-field-unknown': this.t('buildiq', 'A branch rule on step {step} reads {field}, which no form asks.', params),
				'branch-field-later': this.t('buildiq', 'A branch rule on step {step} reads {field}, which is first asked later, on step {askedAt}.', params),
				'write-unknown': this.t('buildiq', 'Step {step} refers to write {write} before it is made.', params),
				'write-as-duplicate': this.t('buildiq', 'Two writes are named {write}.', params),
			}
			return texts[finding.code] || this.t('buildiq', 'Step {step}: {code}', { step: finding.step, code: finding.code })
		},

		/**
		 * Save: the checks that need no server first, then buildiq's save
		 * route. A refusal shows its reason; findings show per step.
		 *
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-authoring-a-journey-must-be-separately-authorised
		 */
		async save() {
			this.refusal = ''
			this.savedNote = ''
			this.serverFindings = []
			this.localFindings = journeyFindings(this.journey, this.forms)
			if (this.localFindings.length > 0) {
				return
			}
			this.saving = true
			try {
				const result = await saveJourney(this.journey, this.client)
				if (result.saved) {
					this.journey = { ...this.journey, id: result.id || this.journey.id }
					this.savedNote = this.t('buildiq', 'The journey is saved.')
					return
				}
				this.serverFindings = result.findings
				if (result.findings.length === 0) {
					this.refusal = result.message || this.t('buildiq', 'The journey could not be saved.')
				}
			} catch {
				this.refusal = this.t('buildiq', 'The journey could not be saved.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.journey-designer {
	padding: 16px;
}

.journey-designer__head {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 12px;
}

.journey-designer__name {
	flex: 1 1 auto;
}

.journey-designer__body {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
}

.journey-designer__error,
.journey-designer__findings {
	color: var(--color-error-text);
}

.journey-designer__saved {
	color: var(--color-success-text);
}
</style>
