<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - RuleSetsPage — the business-rules engine dashboard (spec
  - business-rules-engine REQ-BRE-001 / REQ-BRE-004). Lists every RuleSet in
  - the shared `buildiq` register, with status, version, owner app and a
  - per-row test indicator. Row actions open the matching editor dialog
  - (DecisionTableEditor / ConditionActionRuleEditor) or the test sandbox, run
  - the test suite, transition the lifecycle, or export the RuleSet as JSON.
  -
  - RuleSet CRUD goes through OpenRegister's REST surface; rule evaluation and
  - test-all go through the buildiq RulesController API. No direct lifecycle
  - mutation here — the OR lifecycle (x-openregister-lifecycle) owns transitions.
  -->
<template>
	<div class="rule-sets-page">
		<div class="rule-sets-page__header">
			<h2>{{ t('buildiq', 'Business rules') }}</h2>
			<NcButton variant="primary" @click="openCreate">
				{{ t('buildiq', 'New rule set') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="shownRuleSets.length === 0"
			:name="t('buildiq', 'No rule sets yet')"
			:description="
				t(
					'buildiq',
					'Create a decision table or a condition-action rule set to get started.',
				)
			" />

		<table v-else class="rule-sets-page__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('buildiq', 'Name') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Type') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Status') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Version') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Owner app') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'May be called by') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Tests') }}
					</th>
					<th scope="col">
						{{ t('buildiq', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="rs in shownRuleSets" :key="rs.slug" data-testid="rule-set-row">
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
					<td data-testid="rule-set-callers">
						{{ callersLabel(rs) }}
					</td>
					<td>
						<span
							class="rule-sets-page__test"
							:class="testBadgeClass(rs.slug)">
							{{ testBadgeLabel(rs.slug) }}
						</span>
					</td>
					<td class="rule-sets-page__actions">
						<NcButton variant="tertiary" @click="edit(rs)">
							{{ t('buildiq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="openCallers(rs)">
							{{ t('buildiq', 'Callers') }}
						</NcButton>
						<NcButton variant="tertiary" @click="openSandbox(rs)">
							{{ t('buildiq', 'Test') }}
						</NcButton>
						<NcButton variant="tertiary" @click="runTests(rs)">
							{{ t('buildiq', 'Run tests') }}
						</NcButton>
						<NcButton variant="tertiary" @click="exportRuleSet(rs)">
							{{ t('buildiq', 'Export') }}
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

		<RuleSetCallersDialog
			v-model:open="showCallers"
			:ruleSet="activeRuleSet"
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
import RuleSetCallersDialog from '../dialogs/RuleSetCallersDialog.vue'
import RuleSetTestSandbox from '../modals/RuleSetTestSandboxModal.vue'
import { listResults } from '../utils/listResults.js'

export default {
	name: 'RuleSetsPage',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		DecisionTableEditor,
		ConditionActionRuleEditor,
		RuleSetCallersDialog,
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
			showCallers: false,
			testResults: {},
		}
	},

	computed: {
		/**
		 * The app the page was opened for (`?app=`), or '' for every rule set.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-the-app-leads-to-its-business-rules-req-bqar-008
		 */
		appFilter() {
			const app = this.$route && this.$route.query ? this.$route.query.app : ''
			return typeof app === 'string' ? app.trim() : ''
		},

		/**
		 * The rule sets listed: for an app, the ones it owns and the global
		 * ones (no owner app); otherwise all of them.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-the-app-leads-to-its-business-rules-req-bqar-008
		 */
		shownRuleSets() {
			if (this.appFilter === '') {
				return this.ruleSets
			}
			return this.ruleSets.filter((rs) => {
				const owner = rs && typeof rs.ownerApp === 'string' ? rs.ownerApp.trim() : ''
				return owner === '' || owner === this.appFilter
			})
		},
	},

	mounted() {
		this.fetchRuleSets()
	},

	methods: {
		/**
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async fetchRuleSets() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/rule-set',
				)
				const { data } = await axios.get(url)
				this.ruleSets = listResults(data)
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load rule sets.')
			} finally {
				this.loading = false
			}
		},

		edit(ruleSet) {
			this.activeRuleSet = ruleSet
			if (ruleSet.ruleType === 'condition-action') {
				this.showConditionEditor = true
			} else {
				this.showDecisionEditor = true
			}
		},

		/**
		 * Open "May be called by" for a rule set (REQ-BQRX-001).
		 *
		 * @param {object} ruleSet - the rule set.
		 * @return {void}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		openCallers(ruleSet) {
			this.activeRuleSet = ruleSet
			this.showCallers = true
		},

		/**
		 * The apps that may call a rule set, as the table shows them.
		 *
		 * @param {object} ruleSet - the rule set.
		 * @return {string}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		callersLabel(ruleSet) {
			const apps = Array.isArray(ruleSet.callableBy) ? ruleSet.callableBy : []
			return apps.length > 0 ? apps.join(', ') : '—'
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

		/**
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		async runTests(ruleSet) {
			try {
				const url = generateUrl(
					`/apps/buildiq/api/rules/${ruleSet.slug}/test-all`,
				)
				const { data } = await axios.post(url, {})
				this.testResults[ruleSet.slug] = data
			} catch (error) {
				this.errorMessage = t('buildiq', 'Test run failed.')
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

		/**
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		testBadgeLabel(slug) {
			const result = this.testResults[slug]
			if (!result) {
				return t('buildiq', 'Not run')
			}
			return `${result.passed}/${result.total}`
		},

		/**
		 * Download a rule set as one JSON file named after its slug.
		 *
		 * The server assembles the file: the rule set's metadata with its
		 * decision tables, condition-action rules and test cases, and no
		 * execution logs, so it can be imported elsewhere.
		 *
		 * @param {object} ruleSet - the rule set row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/business-rules-engine/spec.md#requirement-a-rule-set-can-be-exported-as-a-file-req-bqcv-017
		 */
		async exportRuleSet(ruleSet) {
			this.errorMessage = ''
			let exported
			try {
				const url = generateUrl('/apps/buildiq/api/rules/{slug}/export', { slug: ruleSet.slug })
				const { data } = await axios.get(url)
				exported = data
			} catch {
				this.errorMessage = t('buildiq', 'Could not export the rule set.')
				return
			}
			const blob = new Blob([JSON.stringify(exported, null, 2)], {
				type: 'application/json',
			})
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `${ruleSet.slug}.json`
			link.click()
			URL.revokeObjectURL(link.href)
		},

		/**
		 * Close the open editor or callers dialog and reload the rule sets after a save.
		 *
		 * @return {void}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		onSaved() {
			this.showDecisionEditor = false
			this.showConditionEditor = false
			this.showCallers = false
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
