// SPDX-License-Identifier: EUPL-1.2
/**
 * journeyPreview — what the Journey Designer hands the real `CnJourney`
 * (journey-designer, task 4).
 *
 * `previewJourney` turns the journey as stored (a form step holds a
 * `formId`) into the journey as the portal renders it (a form step carries
 * the referenced form), the way the portal resolves it. A confirmation step is
 * the text the renderer shows once the run is submitted. Nothing of the
 * renderer is imitated: steps, fields, validation and branches stay
 * `CnJourney`'s own.
 *
 * `createPreviewRunStore` is a run store with the interface of nc-vue's
 * `createJourneyRunStore` that keeps the run in memory. A preview advanced
 * through a step with `writes[]` therefore sends nothing: no run is recorded
 * and no object is created in any register.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
 */
import { reactive } from 'vue'

/**
 * The run id a preview run carries.
 *
 * @type {string}
 */
export const PREVIEW_RUN_ID = 'preview'

/**
 * The journey as the renderer takes it: each form step carries the form it
 * references; confirmation steps become the success message.
 *
 * @param {{id?: string, title?: string, steps?: Array<object>, successMessage?: string}} journey - the journey as stored.
 * @param {{[id: string]: object}} forms - the form objects by id.
 * @return {object} The journey to render.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
 */
export function previewJourney(journey, forms) {
	const steps = Array.isArray(journey && journey.steps) ? journey.steps : []
	const rendered = []
	const messages = []
	for (const step of steps) {
		if (!step || typeof step !== 'object') {
			continue
		}
		if (step.type === 'confirmation') {
			if (step.message) {
				messages.push(String(step.message))
			}
			continue
		}
		if (step.type === 'form') {
			const form = (forms && forms[String(step.formId || '')]) || {}
			const rest = { ...step }
			delete rest.formId
			rendered.push({ ...rest, form: { fields: Array.isArray(form.fields) ? form.fields : [], steps: Array.isArray(form.steps) ? form.steps : [] } })
			continue
		}
		rendered.push({ ...step })
	}
	const out = { id: String((journey && journey.id) || PREVIEW_RUN_ID), title: (journey && journey.title) || '', steps: rendered }
	const message = messages.length > 0 ? messages.join('\n') : (journey && journey.successMessage)
	if (message) {
		out.successMessage = message
	}
	return out
}

/**
 * A run store that keeps the run in memory and sends nothing.
 *
 * @param {string} [journeyId] - the journey being previewed.
 * @return {{state: object, resume: Function, save: Function, submit: Function, report: Function}} The store.
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
 */
export function createPreviewRunStore(journeyId = '') {
	const state = reactive({
		runId: null,
		journeyId,
		answers: {},
		position: null,
		status: 'idle',
		saving: false,
		error: null,
		failures: [],
	})

	return {
		state,

		/**
		 * Nothing to resume: a preview always starts clean.
		 *
		 * @return {Promise<void>}
		 */
		async resume() {
		},

		/**
		 * Keep a step's answers and the next position, in memory only.
		 *
		 * @param {string} stepId - the answered step.
		 * @param {object} stepAnswers - its answers.
		 * @param {string} position - the step the run goes to.
		 * @return {Promise<void>}
		 */
		async save(stepId, stepAnswers, position) {
			state.answers = { ...state.answers, [stepId]: stepAnswers }
			state.position = position
			state.runId = PREVIEW_RUN_ID
			state.status = 'active'
		},

		/**
		 * End the preview run without submitting it anywhere.
		 *
		 * @return {Promise<{preview: boolean, answers: object}>} What a submit would have sent.
		 */
		async submit() {
			state.status = 'submitted'
			return { preview: true, answers: state.answers }
		},

		/**
		 * Keep a condition failure on the preview run.
		 *
		 * @param {Error} error - the failure.
		 * @param {object} condition - the condition that failed.
		 * @return {Promise<void>}
		 */
		async report(error, condition) {
			state.failures = [...state.failures, { message: error && error.message ? error.message : String(error), condition }]
		},
	}
}
