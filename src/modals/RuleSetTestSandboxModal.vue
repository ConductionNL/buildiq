<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - RuleSetTestSandbox — the test sandbox view (spec business-rules-engine
  - REQ-BRE-004). Lists a RuleSet's TestCases with their last result, runs the
  - full suite through the openbuild RulesController test-all endpoint, and lets
  - the analyst add a TestCase (sample payload + expected result) persisted via
  - OpenRegister REST. Rendered as a NcModal so it overlays the dashboard.
  -->
<template>
	<NcModal size="large" @close="$emit('close')">
		<div class="rule-set-test-sandbox">
			<h2>{{ t('openbuild', 'Test sandbox') }} — {{ ruleSet.naam }}</h2>

			<div class="rule-set-test-sandbox__toolbar">
				<NcButton type="primary" :disabled="running" @click="runAll">
					{{ running ? t('openbuild', 'Running...') : t('openbuild', 'Run all tests') }}
				</NcButton>
				<NcButton type="secondary" @click="showAdd = !showAdd">
					{{ t('openbuild', 'Add test case') }}
				</NcButton>
			</div>

			<div v-if="summary" class="rule-set-test-sandbox__summary" data-testid="test-summary">
				{{ t('openbuild', 'Passed') }}: {{ summary.passed }} / {{ summary.total }}
				<span v-if="summary.failed > 0" class="rule-set-test-sandbox__failed">
					({{ summary.failed }} {{ t('openbuild', 'failed') }})
				</span>
			</div>

			<NcLoadingIcon v-if="loading" :size="32" />

			<table v-else class="rule-set-test-sandbox__table">
				<thead>
					<tr>
						<th>{{ t('openbuild', 'Name') }}</th>
						<th>{{ t('openbuild', 'Description') }}</th>
						<th>{{ t('openbuild', 'Last result') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="tc in testCases" :key="tc.slug || tc.naam" data-testid="test-case-row">
						<td>{{ tc.naam }}</td>
						<td>{{ tc.beschrijving }}</td>
						<td>
							<span class="rule-set-test-sandbox__result" :class="resultClass(tc)">
								{{ resultLabel(tc) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="showAdd" class="rule-set-test-sandbox__add">
				<NcTextField v-model="draft.naam" :label="t('openbuild', 'Test case name')" />
				<NcTextArea v-model="draft.inputPayloadText" :label="t('openbuild', 'Input payload (JSON)')" />
				<NcTextArea v-model="draft.expectedText" :label="t('openbuild', 'Expected result (JSON)')" />
				<NcButton type="primary" :disabled="saving" @click="addTestCase">
					{{ t('openbuild', 'Save test case') }}
				</NcButton>
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
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'RuleSetTestSandboxModal',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},
	props: {
		ruleSet: {
			type: Object,
			required: true,
		},
	},
	emits: ['close'],
	data() {
		return {
			loading: true,
			running: false,
			saving: false,
			testCases: [],
			summary: null,
			showAdd: false,
			errorMessage: '',
			failedNames: [],
			draft: {
				naam: '',
				inputPayloadText: '{}',
				expectedText: '{}',
			},
		}
	},
	mounted() {
		this.fetchTestCases()
	},
	methods: {
		async fetchTestCases() {
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuild/rule-test-case')
				const { data } = await axios.get(url)
				const all = Array.isArray(data) ? data : (data.results || [])
				this.testCases = all.filter((tc) => tc.ruleSetId === this.ruleSet.slug)
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not load test cases.')
			} finally {
				this.loading = false
			}
		},
		async runAll() {
			this.running = true
			this.errorMessage = ''
			try {
				const url = generateUrl(`/apps/openbuild/api/rules/${this.ruleSet.slug}/test-all`)
				const { data } = await axios.post(url, {})
				this.summary = data
				this.failedNames = data.failures || []
			} catch (error) {
				this.errorMessage = t('openbuild', 'Test run failed.')
			} finally {
				this.running = false
			}
		},
		resultClass(tc) {
			if (this.failedNames.includes(tc.naam)) {
				return 'rule-set-test-sandbox__result--fail'
			}
			if (this.summary) {
				return 'rule-set-test-sandbox__result--pass'
			}
			return 'rule-set-test-sandbox__result--unknown'
		},
		resultLabel(tc) {
			if (this.failedNames.includes(tc.naam)) {
				return t('openbuild', 'Failed')
			}
			if (this.summary) {
				return t('openbuild', 'Passed')
			}
			return t('openbuild', 'Not run')
		},
		async addTestCase() {
			this.saving = true
			this.errorMessage = ''
			try {
				const payload = JSON.parse(this.draft.inputPayloadText || '{}')
				const expected = JSON.parse(this.draft.expectedText || '{}')
				const url = generateUrl('/apps/openregister/api/objects/openbuild/rule-test-case')
				await axios.post(url, {
					ruleSetId: this.ruleSet.slug,
					naam: this.draft.naam,
					inputPayload: payload,
					verwachtResultaat: expected,
					laatsteTestResultaat: 'niet-uitgevoerd',
				})
				this.showAdd = false
				this.draft = { naam: '', inputPayloadText: '{}', expectedText: '{}' }
				this.fetchTestCases()
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not save the test case — check the JSON is valid.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.rule-set-test-sandbox {
	padding: 16px;
}

.rule-set-test-sandbox__toolbar {
	display: flex;
	gap: 8px;
	margin-bottom: 12px;
}

.rule-set-test-sandbox__table {
	width: 100%;
	border-collapse: collapse;
}

.rule-set-test-sandbox__table th,
.rule-set-test-sandbox__table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.rule-set-test-sandbox__result--pass {
	color: var(--color-success);
}

.rule-set-test-sandbox__result--fail {
	color: var(--color-error);
}

.rule-set-test-sandbox__add {
	margin-top: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
