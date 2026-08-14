<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - RuleSetsPage — the business-rules engine dashboard (spec
  - business-rules-engine REQ-BRE-001 / REQ-BRE-004). Lists every RuleSet in
  - the shared `openbuild` register, with status, version, owner app and a
  - per-row test indicator. Row actions open the matching editor dialog
  - (DecisionTableEditor / ConditionActionRuleEditor) or the test sandbox, run
  - the test suite, transition the lifecycle, or export the RuleSet as JSON.
  -
  - RuleSet CRUD goes through OpenRegister's REST surface; rule evaluation and
  - test-all go through the openbuild RulesController API. No direct lifecycle
  - mutation here — the OR lifecycle (x-openregister-lifecycle) owns transitions.
  -->
<template>
	<div class="rule-sets-page">
		<div class="rule-sets-page__header">
			<h2>{{ t('openbuild', 'Business rules') }}</h2>
			<NcButton type="primary" @click="openCreate">
				{{ t('openbuild', 'New rule set') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="ruleSets.length === 0"
			:name="t('openbuild', 'No rule sets yet')"
			:description="
				t(
					'openbuild',
					'Create a decision table or a condition-action rule set to get started.',
				)
			" />

		<table v-else class="rule-sets-page__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openbuild', 'Name') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Type') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Status') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Version') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Owner app') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Tests') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="rs in ruleSets" :key="rs.slug" data-testid="rule-set-row">
					<td>{{ rs.name }}</td>
					<td>{{ rs.ruleType }}</td>
					<td>
						<span
							class="rule-sets-page__status"
							:class="'rule-sets-page__status--' + rs.status">
							{{ rs.status }}
						</span>
					</td>
					<td>{{ rs.version }}</td>
					<td>{{ rs.ownerApp }}</td>
					<td>
						<span
							class="rule-sets-page__test"
							:class="testBadgeClass(rs.slug)">
							{{ testBadgeLabel(rs.slug) }}
						</span>
					</td>
					<td class="rule-sets-page__actions">
						<NcButton type="tertiary" @click="edit(rs)">
							{{ t('openbuild', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary" @click="openSandbox(rs)">
							{{ t('openbuild', 'Test') }}
						</NcButton>
						<NcButton type="tertiary" @click="runTests(rs)">
							{{ t('openbuild', 'Run tests') }}
						</NcButton>
						<NcButton type="tertiary" @click="exportRuleSet(rs)">
							{{ t('openbuild', 'Export') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<DecisionTableEditor
			v-if="showDecisionEditor"
			:ruleSet="activeRuleSet"
			@close="showDecisionEditor = false"
			@saved="onSaved" />

		<ConditionActionRuleEditor
			v-if="showConditionEditor"
			:ruleSet="activeRuleSet"
			@close="showConditionEditor = false"
			@saved="onSaved" />

		<RuleSetTestSandbox
			v-if="showSandbox"
			:ruleSet="activeRuleSet"
			@close="showSandbox = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ConditionActionRuleEditor from '../dialogs/ConditionActionRuleEditor.vue'
import DecisionTableEditor from '../dialogs/DecisionTableEditor.vue'
import RuleSetTestSandbox from '../modals/RuleSetTestSandboxModal.vue'

export default {
	name: 'RuleSetsPage',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		DecisionTableEditor,
		ConditionActionRuleEditor,
		RuleSetTestSandbox,
	},

	data() {
		return {
			loading: true,
			ruleSets: [],
			errorMessage: '',
			activeRuleSet: null,
			showDecisionEditor: false,
			showConditionEditor: false,
			showSandbox: false,
			testResults: {},
		}
	},

	mounted() {
		this.fetchRuleSets()
	},

	methods: {
		async fetchRuleSets() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openbuild/rule-set',
				)
				const { data } = await axios.get(url)
				this.ruleSets = this.extractResults(data)
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not load rule sets.')
			} finally {
				this.loading = false
			}
		},

		extractResults(data) {
			if (Array.isArray(data)) {
				return data
			}
			if (data && Array.isArray(data.results)) {
				return data.results
			}
			return []
		},

		edit(ruleSet) {
			this.activeRuleSet = ruleSet
			if (ruleSet.ruleType === 'condition-action') {
				this.showConditionEditor = true
			} else {
				this.showDecisionEditor = true
			}
		},

		openSandbox(ruleSet) {
			this.activeRuleSet = ruleSet
			this.showSandbox = true
		},

		/**
		 * Open the decision-table editor on a blank draft RuleSet.
		 *
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-001-ruleset-schema-declaration-with-lifecycle
		 */
		openCreate() {
			this.activeRuleSet = {
				slug: '',
				name: '',
				ruleType: 'decision-table',
				status: 'draft',
				version: '1.0.0',
			}
			this.showDecisionEditor = true
		},

		async runTests(ruleSet) {
			try {
				const url = generateUrl(
					`/apps/openbuild/api/rules/${ruleSet.slug}/test-all`,
				)
				const { data } = await axios.post(url, {})
				this.testResults[ruleSet.slug] = data
			} catch (error) {
				this.errorMessage = t('openbuild', 'Test run failed.')
			}
		},

		testBadgeClass(slug) {
			const result = this.testResults[slug]
			if (!result) {
				return 'rule-sets-page__test--unknown'
			}
			return result.failed === 0
				? 'rule-sets-page__test--pass'
				: 'rule-sets-page__test--fail'
		},

		testBadgeLabel(slug) {
			const result = this.testResults[slug]
			if (!result) {
				return t('openbuild', 'Not run')
			}
			return `${result.passed}/${result.total}`
		},

		exportRuleSet(ruleSet) {
			const blob = new Blob([JSON.stringify(ruleSet, null, 2)], {
				type: 'application/json',
			})
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `${ruleSet.slug || 'rule-set'}.json`
			link.click()
			URL.revokeObjectURL(link.href)
		},

		onSaved() {
			this.showDecisionEditor = false
			this.showConditionEditor = false
			this.fetchRuleSets()
		},
	},
}
</script>

<style scoped>
.rule-sets-page {
	padding: 16px;
}

.rule-sets-page__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.rule-sets-page__table {
	width: 100%;
	border-collapse: collapse;
}

.rule-sets-page__table th,
.rule-sets-page__table td {
	text-align: left;
	padding: 8px;
	border-bottom: 1px solid var(--color-border);
}

.rule-sets-page__actions {
	display: flex;
	gap: 4px;
}

.rule-sets-page__status,
.rule-sets-page__test {
	border-radius: var(--border-radius-pill);
	padding: 2px 8px;
	font-size: 0.85em;
}

.rule-sets-page__status--active {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.rule-sets-page__test--pass {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.rule-sets-page__test--fail {
	background-color: var(--color-error);
	color: var(--color-primary-element-text);
}
</style>
