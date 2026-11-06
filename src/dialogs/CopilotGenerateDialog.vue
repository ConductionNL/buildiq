<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CopilotGenerateDialog — "Generate with AI" flow for the creation wizard
  (spec `ai-copilot` REQ-OBAIC-006). Standalone NcModal per the
  modal-isolation gate (ADR-004): describe the app -> generating state ->
  review the proposed plan (summary, step list grouped as schemas / pages /
  menu items, canonical-validator verdict) -> Confirm & create -> execute.
  Cancel/discard at any stage applies nothing (POST /api/copilot/plan is a
  read; only Confirm & create sends the execute request).
-->
<template>
	<NcModal
		v-if="open"
		:name="t('buildiq', 'Generate an app with AI')"
		:noClose="state === 'executing'"
		@close="onCancel">
		<div class="copilot-generate">
			<template
				v-if="state === 'idle' || state === 'planning' || state === 'error'">
				<p class="copilot-generate__hint">
					{{
						t(
							'buildiq',
							'Describe the app you want to build in a sentence or two. The AI will propose schemas, pages and menu items for you to review before anything is created.',
						)
					}}
				</p>
				<NcTextArea
					v-model="brief"
					data-testid="copilot-brief-input"
					:label="t('buildiq', 'Describe your app')"
					:disabled="state === 'planning'"
					:placeholder="
						t(
							'buildiq',
							'e.g. A tool library where members can borrow and return tools',
						)
					"
					:rows="4" />
				<p v-if="state === 'planning'" class="copilot-generate__waiting">
					{{
						t(
							'buildiq',
							'Asking the AI provider. This usually takes a few seconds.',
						)
					}}
				</p>
				<p v-if="errorMessage" class="copilot-generate__error" role="alert">
					{{ errorMessage }}
				</p>
				<p v-if="errorDetail" class="copilot-generate__error-detail">
					{{ errorDetail }}
				</p>
			</template>

			<template v-else-if="state === 'review' || state === 'executing'">
				<div
					data-testid="copilot-plan-review"
					class="copilot-generate__review">
					<p class="copilot-generate__summary">
						{{ plan && plan.summary }}
					</p>

					<div v-if="schemaSteps.length" class="copilot-generate__group">
						<h3>{{ t('buildiq', 'Schemas') }}</h3>
						<ul>
							<li
								v-for="(step, idx) in schemaSteps"
								:key="'schema-' + idx">
								{{ step.arguments.title || step.arguments.slug }}
							</li>
						</ul>
					</div>

					<div v-if="pageSteps.length" class="copilot-generate__group">
						<h3>{{ t('buildiq', 'Pages') }}</h3>
						<ul>
							<li
								v-for="(step, idx) in pageSteps"
								:key="'page-' + idx">
								{{ step.arguments.title || step.arguments.pageId }}
								({{ step.arguments.type }})
							</li>
						</ul>
					</div>

					<div v-if="menuSteps.length" class="copilot-generate__group">
						<h3>{{ t('buildiq', 'Menu items') }}</h3>
						<ul>
							<li
								v-for="(step, idx) in menuSteps"
								:key="'menu-' + idx">
								{{ step.arguments.label }}
							</li>
						</ul>
					</div>

					<div
						v-if="!canApprove"
						class="copilot-generate__error"
						data-testid="copilot-validation-errors"
						role="alert">
						<p>
							{{
								t(
									'buildiq',
									'This app cannot be created yet. These checks on the proposed app failed:',
								)
							}}
						</p>
						<ul class="copilot-generate__error-list">
							<li
								v-for="(line, idx) in validationErrors"
								:key="'ve-' + idx">
								{{ line }}
							</li>
						</ul>
						<p v-if="hiddenValidationErrorCount">
							{{
								n(
									'buildiq',
									'And %n more check.',
									'And %n more checks.',
									hiddenValidationErrorCount,
								)
							}}
						</p>
						<p>
							{{
								t(
									'buildiq',
									'Generate again, or pass these lines to your administrator if they keep coming back.',
								)
							}}
						</p>
					</div>
				</div>
			</template>

			<div class="copilot-generate__actions">
				<!-- Cancel stays live while a plan is in flight: waiting for the
				     provider is the longest state in this dialog, and a dialog
				     you cannot leave is the worst thing to hit in a demo.
				     Cancelling sends nothing and applies nothing. -->
				<NcButton
					data-testid="copilot-cancel"
					:disabled="state === 'executing'"
					@click="onCancel">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="
						state === 'idle' || state === 'planning' || state === 'error'
					"
					variant="primary"
					:disabled="!brief.trim() || state === 'planning'"
					@click="onGenerate">
					{{
						state === 'planning'
							? t('buildiq', 'Generating…')
							: t('buildiq', 'Generate')
					}}
				</NcButton>
				<NcButton
					v-else
					data-testid="copilot-confirm"
					variant="primary"
					:disabled="!canApprove || state === 'executing'"
					@click="onConfirm">
					{{
						state === 'executing'
							? t('buildiq', 'Creating…')
							: t('buildiq', 'Confirm & create')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextArea } from '@nextcloud/vue'
import { useCopilot } from '../composables/useCopilot.js'

/**
 * How many validator messages the review screen lists before it stops and
 * counts the rest. One broken widget can produce a message per field per
 * widget, and a wall of them buries the first one, which is usually the one
 * worth reading.
 */
const VALIDATION_ERRORS_SHOWN = 6

export default {
	name: 'CopilotGenerateDialog',

	components: { NcModal, NcButton, NcTextArea },

	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'created'],

	setup() {
		return { copilot: useCopilot() }
	},

	data() {
		return {
			brief: '',
		}
	},

	computed: {
		/**
		 * The copilot state machine's current state.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		state() {
			return this.copilot.state.value
		},

		/**
		 * The current plan response, or null before one has been generated.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		plan() {
			return this.copilot.plan.value
		},

		/**
		 * Whether every predicted manifest passes the canonical validator.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		canApprove() {
			return this.copilot.canApprove.value
		},

		/**
		 * The current error message, or '' when there is none.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		errorMessage() {
			return this.copilot.errorMessage.value
		},

		/**
		 * What the AI provider itself said about the failure, shown under the
		 * message so a missing provider does not read as a bad brief.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		errorDetail() {
			return this.copilot.errorDetail.value
		},

		/**
		 * Every canonical-validator message across the plan's predicted
		 * manifests, flattened and capped for display.
		 *
		 * The dialog used to say only that validation had failed and suggest
		 * rephrasing the brief. Most of these failures are the builder's own
		 * fault, not the reader's wording, and a reader who cannot see which
		 * field failed can only guess. The version key each list is filed
		 * under is dropped: it names an internal record, and the field paths
		 * below it are what a reader can act on.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		validationErrors() {
			return this.allValidationErrors.slice(0, VALIDATION_ERRORS_SHOWN)
		},

		/**
		 * How many validator messages the cap left out, so a long list still
		 * says how long it really is.
		 *
		 * @return {number}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		hiddenValidationErrorCount() {
			return Math.max(
				0,
				this.allValidationErrors.length - VALIDATION_ERRORS_SHOWN,
			)
		},

		/**
		 * The uncapped, de-duplicated validator message list.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		allValidationErrors() {
			const seen = new Set()
			for (const errors of this.copilot.manifestErrors.value.values()) {
				for (const line of errors || []) {
					if (typeof line === 'string' && line.length > 0) {
						seen.add(line)
					}
				}
			}
			return [...seen]
		},

		/**
		 * Proposed `upsertSchema` steps, for the "Schemas" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		schemaSteps() {
			return this.stepsByTool('buildiq.upsertSchema')
		},

		/**
		 * Proposed `upsertPage` steps, for the "Pages" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		pageSteps() {
			return this.stepsByTool('buildiq.upsertPage')
		},

		/**
		 * Proposed `upsertMenuItem` steps, for the "Menu items" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		menuSteps() {
			return this.stepsByTool('buildiq.upsertMenuItem')
		},
	},

	watch: {
		open(value) {
			if (value) {
				this.brief = ''
				this.copilot.discard()
				this.copilot.checkHealth()
			}
		},
	},

	methods: {
		/**
		 * Filter the plan's steps by tool id.
		 *
		 * @param {string} tool - the tool id, e.g. `buildiq.upsertPage`.
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		stepsByTool(tool) {
			const steps = (this.plan && this.plan.steps) || []
			return steps.filter((s) => s && s.tool === tool)
		},

		/**
		 * Request a plan for the current brief.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		async onGenerate() {
			if (!this.brief.trim()) {
				return
			}
			await this.copilot.generatePlan(this.brief.trim())
		},

		/**
		 * Approve and execute the reviewed plan; on success emit `created` with
		 * the new app's slug and close the dialog.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		async onConfirm() {
			await this.copilot.approve()
			if (this.copilot.state.value !== 'done') {
				return
			}
			const results =
				(this.copilot.executeResult.value
					&& this.copilot.executeResult.value.results)
				|| []
			const createResult = results.find(
				(r) => r && r.created === true && r.app,
			)
			const appSlug = createResult ? createResult.app.slug : ''
			this.$emit('created', appSlug)
			this.$emit('update:open', false)
		},

		/**
		 * Discard the dialog without sending any execute request.
		 *
		 * @return {void}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		onCancel() {
			this.copilot.discard()
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.copilot-generate {
	padding: 20px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.copilot-generate__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.copilot-generate__summary {
	font-weight: 600;
}

.copilot-generate__group h3 {
	margin: 8px 0 4px;
	font-size: 0.95rem;
}

.copilot-generate__group ul {
	margin: 0;
	padding-left: 20px;
}

.copilot-generate__waiting {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.copilot-generate__error {
	color: var(--color-error);
}

.copilot-generate__error-detail {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}

.copilot-generate__error-list {
	margin: 0.5em 0;
	padding-inline-start: 1.5em;
	font-size: 0.9em;
	overflow-wrap: anywhere;
}

.copilot-generate__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
