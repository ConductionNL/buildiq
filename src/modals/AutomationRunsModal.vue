<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AutomationRunsModal — the run log of one automation
  - (logic-automation-run-log REQ-BQRL-003, REQ-BQRL-004). Reads
  - `GET /api/automations/{uuid}/runs`, which applies the automation's own
  - access rules. Each run expands to the "Action | Outcome" table the test
  - panel uses, with its duration under it. A step OpenRegister executes is
  - labelled, never given a success or a failure.
  -->
<template>
	<NcModal size="large" @close="$emit('close')">
		<div class="automation-runs">
			<h2>
				{{ t('buildiq', 'Runs') }} · {{ automation.name || automation.slug }}
			</h2>

			<NcNoteCard
				v-if="entirelyInOpenRegister"
				type="info"
				data-testid="runs-entirely-in-openregister">
				{{ t('buildiq', 'This automation runs entirely in OpenRegister') }}
			</NcNoteCard>

			<template v-else>
				<NcSelect
					v-model="outcomeFilter"
					class="automation-runs__filter"
					:options="outcomeOptions"
					:inputLabel="t('buildiq', 'Outcome')"
					:clearable="false"
					data-testid="runs-outcome-filter"
					@update:modelValue="load" />

				<NcLoadingIcon v-if="loading" :size="32" />

				<NcNoteCard v-else-if="errorMessage" type="error">
					{{ errorMessage }}
				</NcNoteCard>

				<p v-else-if="runs.length === 0" class="automation-runs__empty" data-testid="runs-empty">
					{{ t('buildiq', 'No run in the last 90 days') }}
				</p>

				<table v-else class="automation-runs__table" data-testid="runs-table">
					<thead>
						<tr>
							<th>{{ t('buildiq', 'Time') }}</th>
							<th>{{ t('buildiq', 'Trigger') }}</th>
							<th>{{ t('buildiq', 'Object') }}</th>
							<th>{{ t('buildiq', 'Outcome') }}</th>
							<th>{{ t('buildiq', 'Duration') }}</th>
						</tr>
					</thead>
					<tbody>
						<template v-for="run in runs" :key="run.id">
							<tr
								class="automation-runs__run"
								:class="{ 'automation-runs__run--open': openRun === run.id }"
								data-testid="run-row"
								tabindex="0"
								:aria-expanded="openRun === run.id ? 'true' : 'false'"
								@click="toggle(run.id)"
								@keydown.enter="toggle(run.id)">
								<td>{{ runTime(run.startedAt) }}</td>
								<td>{{ run.trigger }}</td>
								<td>
									<a
										v-if="objectLink(run)"
										:href="objectLink(run)"
										@click.stop>{{ run.subject.uuid }}</a>
								</td>
								<td :class="`automation-runs__outcome--${run.outcome}`">
									{{ outcomeLabel(run.outcome) }}
								</td>
								<td>{{ run.durationMs }} ms</td>
							</tr>
							<tr v-if="openRun === run.id" class="automation-runs__detail" data-testid="run-detail">
								<td colspan="5">
									<p v-if="run.reason" class="automation-runs__reason">
										{{ run.reason }}
									</p>
									<table class="automation-runs__steps">
										<thead>
											<tr>
												<th>{{ t('buildiq', 'Action') }}</th>
												<th>{{ t('buildiq', 'Outcome') }}</th>
											</tr>
										</thead>
										<tbody>
											<tr
												v-for="(step, index) in stepsOf(run)"
												:key="index"
												data-testid="run-step">
												<td>{{ step.action }}</td>
												<td :class="`automation-runs__outcome--${step.outcome}`">
													{{ outcomeLabel(step.outcome) }}<span v-if="step.message && step.outcome !== 'in-openregister'">: {{ step.message }}</span>
												</td>
											</tr>
										</tbody>
									</table>
									<p class="automation-runs__duration">
										{{ t('buildiq', 'Duration') }} {{ run.durationMs }} ms
									</p>
								</td>
							</tr>
						</template>
					</tbody>
				</table>
			</template>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcModal, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { openRegisterSteps, outcomeLabel, runsEntirelyInOpenRegister, runTime } from '../utils/automationRuns.js'

export default {
	name: 'AutomationRunsModal',

	components: { NcLoadingIcon, NcModal, NcNoteCard, NcSelect },

	props: {
		/** The automation whose runs to show. */
		automation: {
			type: Object,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			runs: [],
			loading: false,
			errorMessage: '',
			openRun: null,
			outcomeFilter: null,
		}
	},

	computed: {
		/**
		 * The outcome filter's options; "All" sends no filter.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-filtering-on-failures
		 */
		outcomeOptions() {
			return [
				{ id: '', label: t('buildiq', 'All') },
				...['succeeded', 'failed', 'skipped', 'waiting'].map((id) => ({ id, label: outcomeLabel(id) })),
			]
		},

		/**
		 * Whether no action of the automation runs in buildiq, so there is no log to show.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-notification-only-automation-does-not-fake-a-log
		 */
		entirelyInOpenRegister() {
			return runsEntirelyInOpenRegister(this.automation)
		},
	},

	mounted() {
		this.outcomeFilter = this.outcomeOptions[0]
		if (!this.entirelyInOpenRegister) {
			this.load()
		}
	},

	methods: {
		t,
		runTime,
		outcomeLabel,

		/**
		 * Read the runs, newest first, with the chosen outcome filter.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003
		 */
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const params = {}
				if (this.outcomeFilter?.id) {
					params.outcome = this.outcomeFilter.id
				}
				const url = generateUrl(`/apps/buildiq/api/automations/${this.automation.id}/runs`)
				const { data } = await axios.get(url, { params })
				this.runs = Array.isArray(data?.results) ? data.results : []
			} catch {
				this.runs = []
				this.errorMessage = t('buildiq', 'Could not load the runs.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open or close one run's detail.
		 *
		 * @param {string} id The run id.
		 * @return {void}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-the-run-detail-reads-like-a-test-result
		 */
		toggle(id) {
			this.openRun = this.openRun === id ? null : id
		},

		/**
		 * A run's recorded steps, then the steps OpenRegister executed, labelled.
		 *
		 * @param {object} run The run.
		 * @return {Array<{action: string, outcome: string, message: string}>}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#scenario-a-mixed-automation-labels-the-openregister-step
		 */
		stepsOf(run) {
			const recorded = Array.isArray(run?.steps) ? run.steps : []
			const named = new Set(recorded.map((step) => step.action))
			return [...recorded, ...openRegisterSteps(this.automation).filter((step) => !named.has(step.action))]
		},

		/**
		 * The link to the run's object in OpenRegister, or '' without one.
		 *
		 * @param {object} run The run.
		 * @return {string}
		 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003
		 */
		objectLink(run) {
			const subject = run?.subject
			if (!subject?.uuid) {
				return ''
			}
			return generateUrl(`/apps/openregister/objects/${encodeURIComponent(subject.uuid)}`)
		},
	},
}
</script>

<style scoped>
.automation-runs {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.automation-runs__filter {
	max-width: 240px;
}

.automation-runs__table,
.automation-runs__steps {
	width: 100%;
	border-collapse: collapse;
}

.automation-runs__table th,
.automation-runs__table td,
.automation-runs__steps th,
.automation-runs__steps td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.automation-runs__run {
	cursor: pointer;
}

.automation-runs__run:hover,
.automation-runs__run--open,
.automation-runs__run:focus-visible {
	background-color: var(--color-background-hover);
}

.automation-runs__outcome--failed {
	color: var(--color-error-text);
}

.automation-runs__outcome--succeeded {
	color: var(--color-success-text);
}

.automation-runs__outcome--in-openregister,
.automation-runs__empty,
.automation-runs__duration,
.automation-runs__reason {
	color: var(--color-text-maxcontrast);
}
</style>
