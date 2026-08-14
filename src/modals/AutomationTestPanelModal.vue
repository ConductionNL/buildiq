<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AutomationTestPanelModal — dry-run sandbox for one automation (spec
  - automation-designer REQ-AUTD-007), modelled on RuleSetTestSandboxModal.
  - Accepts a sample JSON payload and calls
  - `POST /api/automations/{uuid}/dry-run`, which compiles the automation
  - in-memory to its rules-backend representation and evaluates it with
  - `dryRun: true` — no side effect is ever dispatched and no compiled
  - artifact is mutated (design.md Decision 9).
  -->
<template>
	<NcModal size="normal" @close="$emit('close')">
		<div class="automation-test-panel">
			<h2>
				{{ t('openbuild', 'Test automation') }} —
				{{ automation.name || automation.slug }}
			</h2>

			<NcTextArea
				v-model="payloadText"
				:label="t('openbuild', 'Sample payload (JSON)')"
				data-testid="dry-run-payload" />

			<NcButton
				variant="primary"
				:disabled="running"
				data-testid="dry-run-button"
				@click="run">
				{{ running ? t('openbuild', 'Running…') : t('openbuild', 'Run') }}
			</NcButton>

			<div
				v-if="result"
				class="automation-test-panel__result"
				data-testid="dry-run-result">
				<p
					v-if="result.conditionMatched"
					class="automation-test-panel__matched">
					{{ t('openbuild', 'Condition matched — would-be actions:') }}
				</p>
				<p
					v-else
					class="automation-test-panel__unmatched"
					data-testid="dry-run-no-match">
					{{
						t(
							'openbuild',
							'Condition did not match — no actions would run.',
						)
					}}
				</p>

				<ul
					v-if="result.conditionMatched"
					class="automation-test-panel__actions">
					<li
						v-for="(action, index) in result.actions"
						:key="index"
						data-testid="dry-run-action">
						{{ action }}
					</li>
				</ul>

				<p
					v-if="result.errors && result.errors.length > 0"
					class="automation-test-panel__errors">
					{{ result.errors.join(', ') }}
				</p>

				<p class="automation-test-panel__duration">
					{{ t('openbuild', 'Duration') }}: {{ result.durationMs }}ms
				</p>

				<p
					v-if="result.approvalState && result.approvalState !== 'none'"
					class="automation-test-panel__approval-state"
					data-testid="dry-run-approval-state">
					{{ t('openbuild', 'Approval state') }}: {{ approvalStateLabel }}
				</p>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcModal, NcNoteCard, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'AutomationTestPanelModal',
	components: { NcButton, NcModal, NcNoteCard, NcTextArea },
	props: {
		automation: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],
	data() {
		return {
			payloadText: '{}',
			running: false,
			result: null,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * Human label for the automation's live approval state
		 * (spec REQ-AUTD-007 — dry-run reports the aggregate state alongside
		 * the would-be actions).
		 *
		 * @return {string}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#5.2
		 */
		approvalStateLabel() {
			const labels = {
				pending: t('openbuild', 'Pending'),
				approved: t('openbuild', 'Approved'),
				rejected: t('openbuild', 'Rejected'),
			}
			return (this.result && labels[this.result.approvalState]) || ''
		},
	},

	methods: {
		/**
		 * Run the dry-run evaluation (spec REQ-AUTD-007).
		 *
		 * @return {Promise<void>}
		 */
		async run() {
			this.running = true
			this.errorMessage = ''
			this.result = null
			let payload = {}
			try {
				payload = JSON.parse(this.payloadText || '{}')
			} catch (error) {
				this.errorMessage = t(
					'openbuild',
					'The sample payload is not valid JSON.',
				)
				this.running = false
				return
			}

			try {
				const url = generateUrl(
					`/apps/openbuild/api/automations/${this.automation.id}/dry-run`,
				)
				const { data } = await axios.post(url, { payload })
				this.result = data
			} catch (error) {
				this.errorMessage = t('openbuild', 'Dry-run failed.')
			} finally {
				this.running = false
			}
		},
	},
}
</script>

<style scoped>
.automation-test-panel {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.automation-test-panel__result {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.automation-test-panel__matched {
	color: var(--color-success);
}

.automation-test-panel__unmatched {
	color: var(--color-text-maxcontrast);
}

.automation-test-panel__actions {
	margin: 0 0 8px;
	padding-left: 20px;
}

.automation-test-panel__errors {
	color: var(--color-error);
}

.automation-test-panel__duration {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.automation-test-panel__approval-state {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
	font-weight: bold;
}
</style>
