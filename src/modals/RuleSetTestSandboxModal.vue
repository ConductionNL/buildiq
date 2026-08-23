<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - RuleSetTestSandbox — the test sandbox view (spec business-rules-engine
  - REQ-BRE-004). Lists a RuleSet's TestCases with their last result, runs the
  - full suite through the buildiq RulesController test-all endpoint, and lets
  - the analyst add a TestCase (sample payload + expected result) persisted via
  - OpenRegister REST. Rendered as a NcModal so it overlays the dashboard.
  -->
<template>
	<NcModal size="large" @close="$emit('close')">
		<div class="rule-set-test-sandbox">
			<h2>{{ t('buildiq', 'Test sandbox') }} — {{ ruleSet.name }}</h2>

			<div class="rule-set-test-sandbox__toolbar">
				<NcButton variant="primary" :disabled="running" @click="runAll">
					{{
						running
							? t('buildiq', 'Running…')
							: t('buildiq', 'Run all tests')
					}}
				</NcButton>
				<NcButton variant="secondary" @click="showAdd = !showAdd">
					{{ t('buildiq', 'Add test case') }}
				</NcButton>
			</div>

			<div
				v-if="summary"
				class="rule-set-test-sandbox__summary"
				data-testid="test-summary">
				{{ t('buildiq', 'Passed') }}: {{ summary.passed }} /
				{{ summary.total }}
				<span
					v-if="summary.failed > 0"
					class="rule-set-test-sandbox__failed">
					({{ summary.failed }} {{ t('buildiq', 'failed') }})
				</span>
			</div>

			<NcLoadingIcon v-if="loading" :size="32" />

			<table v-else class="rule-set-test-sandbox__table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('buildiq', 'Name') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Description') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Last result') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="tc in testCases"
						:key="tc.slug || tc.name"
						data-testid="test-case-row">
						<td>{{ tc.name }}</td>
						<td>{{ tc.description }}</td>
						<td>
							<span
								class="rule-set-test-sandbox__result"
								:class="resultClass(tc)">
								{{ resultLabel(tc) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div v-if="showAdd" class="rule-set-test-sandbox__add">
				<NcTextField
					v-model="draft.name"
					:label="t('buildiq', 'Test case name')" />
				<NcTextArea
					v-model="draft.inputPayloadText"
					:label="t('buildiq', 'Input payload (JSON)')" />
				<NcTextArea
					v-model="draft.expectedText"
					:label="t('buildiq', 'Expected result (JSON)')" />
				<NcButton variant="primary" :disabled="saving" @click="addTestCase">
					{{ t('buildiq', 'Save test case') }}
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
import {
	NcButton,
	NcLoadingIcon,
	NcModal,
	NcNoteCard,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

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
				name: '',
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
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/rule-test-case',
				)
				const { data } = await axios.get(url)
				const all = Array.isArray(data) ? data : data.results || []
				this.testCases = all.filter(
					(tc) => tc.ruleSetId === this.ruleSet.slug,
				)
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load test cases.')
			} finally {
				this.loading = false
			}
		},

		async runAll() {
			this.running = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					`/apps/buildiq/api/rules/${this.ruleSet.slug}/test-all`,
				)
				const { data } = await axios.post(url, {})
				this.summary = data
				this.failedNames = data.failures || []
			} catch (error) {
				this.errorMessage = t('buildiq', 'Test run failed.')
			} finally {
				this.running = false
			}
		},

		/**
		 * CSS modifier for a test case's last-run outcome.
		 *
		 * @param {object} tc - the test case.
		 * @return {string}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-004-test-case-driven-sandbox-validation
		 */
		resultClass(tc) {
			if (this.failedNames.includes(tc.name)) {
				return 'rule-set-test-sandbox__result--fail'
			}
			if (this.summary) {
				return 'rule-set-test-sandbox__result--pass'
			}
			return 'rule-set-test-sandbox__result--unknown'
		},

		/**
		 * Human-readable label for a test case's last-run outcome.
		 *
		 * @param {object} tc - the test case.
		 * @return {string}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-004-test-case-driven-sandbox-validation
		 */
		resultLabel(tc) {
			if (this.failedNames.includes(tc.name)) {
				return t('buildiq', 'Failed')
			}
			if (this.summary) {
				return t('buildiq', 'Passed')
			}
			return t('buildiq', 'Not run')
		},

		/**
		 * Create a new TestCase against the current RuleSet.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-004-test-case-driven-sandbox-validation
		 */
		async addTestCase() {
			this.saving = true
			this.errorMessage = ''
			try {
				const payload = JSON.parse(this.draft.inputPayloadText || '{}')
				const expected = JSON.parse(this.draft.expectedText || '{}')
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/rule-test-case',
				)
				await axios.post(url, {
					ruleSetId: this.ruleSet.slug,
					name: this.draft.name,
					inputPayload: payload,
					expectedResult: expected,
					lastTestResult: 'not-run',
				})
				this.showAdd = false
				this.draft = { name: '', inputPayloadText: '{}', expectedText: '{}' }
				this.fetchTestCases()
			} catch (error) {
				this.errorMessage = t(
					'buildiq',
					'Could not save the test case — check the JSON is valid.',
				)
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
