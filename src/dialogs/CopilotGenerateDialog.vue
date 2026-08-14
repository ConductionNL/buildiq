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
		:name="t('openbuild', 'Generate an app with AI')"
		:canClose="state !== 'planning' && state !== 'executing'"
		@close="onCancel">
		<div class="copilot-generate">
			<h2 class="copilot-generate__title">
				{{ t('openbuild', 'Generate an app with AI') }}
			</h2>

			<template
				v-if="state === 'idle' || state === 'planning' || state === 'error'">
				<p class="copilot-generate__hint">
					{{
						t(
							'openbuild',
							'Describe the app you want to build in a sentence or two. The AI will propose schemas, pages and menu items for you to review before anything is created.',
						)
					}}
				</p>
				<NcTextArea
					v-model="brief"
					data-testid="copilot-brief-input"
					:label="t('openbuild', 'Describe your app')"
					:disabled="state === 'planning'"
					:placeholder="
						t(
							'openbuild',
							'e.g. A tool library where members can borrow and return tools',
						)
					"
					:rows="4" />
				<p v-if="errorMessage" class="copilot-generate__error" role="alert">
					{{ errorMessage }}
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
						<h3>{{ t('openbuild', 'Schemas') }}</h3>
						<ul>
							<li
								v-for="(step, idx) in schemaSteps"
								:key="'schema-' + idx">
								{{ step.arguments.title || step.arguments.slug }}
							</li>
						</ul>
					</div>

					<div v-if="pageSteps.length" class="copilot-generate__group">
						<h3>{{ t('openbuild', 'Pages') }}</h3>
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
						<h3>{{ t('openbuild', 'Menu items') }}</h3>
						<ul>
							<li
								v-for="(step, idx) in menuSteps"
								:key="'menu-' + idx">
								{{ step.arguments.label }}
							</li>
						</ul>
					</div>

					<p
						v-if="!canApprove"
						class="copilot-generate__error"
						role="alert">
						{{
							t(
								'openbuild',
								'The proposed manifest did not pass validation, so it cannot be created. Try rephrasing your brief.',
							)
						}}
					</p>
				</div>
			</template>

			<div class="copilot-generate__actions">
				<NcButton
					data-testid="copilot-cancel"
					:disabled="state === 'planning' || state === 'executing'"
					@click="onCancel">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton
					v-if="
						state === 'idle' || state === 'planning' || state === 'error'
					"
					type="primary"
					:disabled="!brief.trim() || state === 'planning'"
					@click="onGenerate">
					{{
						state === 'planning'
							? t('openbuild', 'Generating…')
							: t('openbuild', 'Generate')
					}}
				</NcButton>
				<NcButton
					v-else
					data-testid="copilot-confirm"
					type="primary"
					:disabled="!canApprove || state === 'executing'"
					@click="onConfirm">
					{{
						state === 'executing'
							? t('openbuild', 'Creating…')
							: t('openbuild', 'Confirm & create')
					}}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextArea } from '@nextcloud/vue'
import { useCopilot } from '../composables/useCopilot.js'

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
		 * Proposed `upsertSchema` steps, for the "Schemas" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		schemaSteps() {
			return this.stepsByTool('openbuild.upsertSchema')
		},

		/**
		 * Proposed `upsertPage` steps, for the "Pages" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		pageSteps() {
			return this.stepsByTool('openbuild.upsertPage')
		},

		/**
		 * Proposed `upsertMenuItem` steps, for the "Menu items" review group.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		menuSteps() {
			return this.stepsByTool('openbuild.upsertMenuItem')
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
		 * @param {string} tool - the tool id, e.g. `openbuild.upsertPage`.
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

.copilot-generate__title {
	margin: 0;
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

.copilot-generate__error {
	color: var(--color-error);
}

.copilot-generate__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
