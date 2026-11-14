<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - DecisionTableEditor — grid editor for a DMN decision table (spec
  - business-rules-engine REQ-BRE-002 / REQ-BRE-012). Per ADR-004 modal
  - isolation it is a standalone NcDialog under src/dialogs/, imported by
  - RuleSetsPage, never inlined. Owns a staged copy of the table; emits `saved`
  - after persisting via OpenRegister REST, and `close` on cancel.
  -
  - The cell editor validates FEEL-subset cell conditions on the fly by calling
  - the same operator grammar the backend uses; a syntax error shows a red badge.
  - A live-preview payload box shows which rule a sample payload would match.
  -->
<template>
	<NcDialog
		:name="t('buildiq', 'Decision table editor')"
		size="large"
		@closing="$emit('close')">
		<div class="decision-table-editor">
			<NcTextField
				v-model="staged.name"
				:label="t('buildiq', 'Rule set name')"
				data-testid="rule-set-name" />

			<NcSelect
				v-model="staged.hitPolicy"
				:inputLabel="t('buildiq', 'Hit policy')"
				:options="hitPolicies"
				data-testid="hit-policy" />

			<h4>{{ t('buildiq', 'Input columns') }}</h4>
			<div
				v-for="(col, index) in staged.inputColumns"
				:key="'in-' + index"
				class="decision-table-editor__col">
				<NcTextField v-model="col.name" :label="t('buildiq', 'Name')" />
				<NcTextField
					v-model="col.expressionPath"
					:label="t('buildiq', 'Payload path')" />
				<NcButton variant="tertiary" @click="removeInput(index)">
					{{ t('buildiq', 'Remove') }}
				</NcButton>
			</div>
			<NcButton variant="secondary" @click="addInput">
				{{ t('buildiq', 'Add input column') }}
			</NcButton>

			<h4>{{ t('buildiq', 'Rules') }}</h4>
			<table class="decision-table-editor__grid">
				<thead>
					<tr>
						<th
							v-for="(col, index) in staged.inputColumns"
							:key="'h-' + index"
							scope="col">
							{{ col.name }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Decision') }}
						</th>
						<th scope="col">
							<span class="hidden-visually">{{ t('buildiq', 'Ask AI') }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(rule, rIndex) in staged.rules" :key="'r-' + rIndex">
						<td
							v-for="(col, cIndex) in staged.inputColumns"
							:key="'c-' + cIndex">
							<input
								v-model="rule.conditions[col.name]"
								class="decision-table-editor__cell"
								:class="{
									'decision-table-editor__cell--invalid':
										!isCellValid(rule.conditions[col.name]),
								}"
								:aria-label="col.name"
								@input="markDirty" />
						</td>
						<td>
							<input
								v-model="rule.values.decision"
								class="decision-table-editor__cell"
								:aria-label="t('buildiq', 'Decision')" />
						</td>
						<td>
							<CopilotAssistButton
								target="decision-rule"
								:appSlug="ruleSet.ownerApp || ''"
								:current="assistRow(rule)"
								:context="assistContext"
								@accept="replaceRule(rIndex, $event)" />
						</td>
					</tr>
				</tbody>
			</table>
			<div class="decision-table-editor__add-rule">
				<NcButton variant="secondary" @click="addRule">
					{{ t('buildiq', 'Add rule') }}
				</NcButton>
				<CopilotAssistButton
					target="decision-rule"
					:appSlug="ruleSet.ownerApp || ''"
					:context="assistContext"
					data-testid="decision-table-propose-rule"
					@accept="appendRule($event)" />
			</div>

			<div v-if="warnings.length" class="decision-table-editor__warnings">
				<NcNoteCard
					v-for="(warning, wIndex) in warnings"
					:key="'w-' + wIndex"
					type="warning">
					{{ warning }}
				</NcNoteCard>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>

		<template #actions>
			<SaveCancelActions
				:saving="saving"
				@cancel="$emit('close')"
				@save="save" />
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import CopilotAssistButton from '../components/copilot/CopilotAssistButton.vue'
import SaveCancelActions from '../components/SaveCancelActions.vue'
import { isCellConditionValid } from '../utils/feelCell.js'
import { saveRuleSetRecord } from './ruleSetRecords.js'

export default {
	name: 'DecisionTableEditor',
	components: {
		CopilotAssistButton,
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
		SaveCancelActions,
	},

	props: {
		ruleSet: {
			type: Object,
			required: true,
		},
	},

	emits: ['close', 'saved'],
	data() {
		return {
			staged: {
				slug: this.ruleSet.slug || '',
				name: this.ruleSet.name || '',
				ruleType: 'decision-table',
				hitPolicy: this.ruleSet.hitPolicy || 'first',
				inputColumns: this.ruleSet.inputColumns
					? JSON.parse(JSON.stringify(this.ruleSet.inputColumns))
					: [],

				rules: this.ruleSet.rules
					? JSON.parse(JSON.stringify(this.ruleSet.rules))
					: [],
			},

			hitPolicies: [
				'unique',
				'first',
				'priority',
				'any',
				'collect',
				'rule-order',
			],

			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * The names the Ask AI help may see: input column names and payload paths, never values.
		 *
		 * @return {{columns: Array<string>, paths: Array<string>}}
		 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-expression-editors-offer-an-ask-ai-button-req-bqch-001
		 */
		assistContext() {
			const columns = this.staged.inputColumns.map((col) => (col.name || '').trim()).filter(Boolean)
			const paths = this.staged.inputColumns.map((col) => (col.expressionPath || '').trim()).filter(Boolean)
			return { columns, paths }
		},

		/**
		 * Editor feedback: flag a catch-all rule that makes later rules unreachable.
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-012-visual-editor-feedback-overlap-and-completeness-detection
		 */
		warnings() {
			const issues = []
			const catchAllIndex = this.staged.rules.findIndex(
				(r) => Object.keys(r.conditions || {}).length === 0,
			)
			if (
				catchAllIndex !== -1
				&& catchAllIndex < this.staged.rules.length - 1
			) {
				issues.push(
					t(
						'buildiq',
						'A catch-all rule appears before other rules — later rules are unreachable.',
					),
				)
			}
			return issues
		},
	},

	methods: {
		isCellValid(value) {
			return isCellConditionValid(value)
		},

		markDirty() {},
		/**
		 * Append an empty input column to the staged table.
		 *
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
		 */
		addInput() {
			this.staged.inputColumns.push({
				name: '',
				type: 'string',
				expressionPath: '',
			})
		},

		/**
		 * Remove one input column from the staged table.
		 *
		 * @param {number} index - position of the column to drop.
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
		 */
		removeInput(index) {
			this.staged.inputColumns.splice(index, 1)
		},

		/**
		 * Append an empty rule row to the staged table.
		 *
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
		 */
		addRule() {
			this.staged.rules.push({
				conditions: {},
				values: { decision: '' },
				label: '',
			})
		},

		/**
		 * One row as the Ask AI help reads it: its cells and its decision.
		 *
		 * @param {object} rule - a staged rule row.
		 * @return {{conditions: object, decision: string}}
		 */
		assistRow(rule) {
			return {
				conditions: { ...(rule.conditions || {}) },
				decision: (rule.values && rule.values.decision) || '',
			}
		},

		/**
		 * A staged row from an accepted proposal `{conditions, decision}`.
		 *
		 * @param {{conditions: object, decision: string}} proposal - the accepted value.
		 * @param {string} [label] - the row's label to keep.
		 * @return {object}
		 */
		ruleFromProposal(proposal, label = '') {
			return {
				conditions: { ...((proposal && proposal.conditions) || {}) },
				values: { decision: (proposal && proposal.decision) || '' },
				label,
			}
		},

		/**
		 * Stage an accepted proposal as a new row; only Save persists it.
		 *
		 * @param {{conditions: object, decision: string}} proposal - the accepted value.
		 * @return {void}
		 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
		 */
		appendRule(proposal) {
			this.staged.rules.push(this.ruleFromProposal(proposal))
		},

		/**
		 * Stage an accepted proposal in place of one row; only Save persists it.
		 *
		 * @param {number} index - the row to replace.
		 * @param {{conditions: object, decision: string}} proposal - the accepted value.
		 * @return {void}
		 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-writes-nothing-and-respects-app-access-req-bqch-004
		 */
		replaceRule(index, proposal) {
			const previous = this.staged.rules[index] || {}
			this.staged.rules.splice(index, 1, this.ruleFromProposal(proposal, previous.label || ''))
		},

		/**
		 * Persist the RuleSet via OpenRegister and its decision table through buildiq,
		 * which stores it in OpenRegister's decision-tables register.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
		 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				await saveRuleSetRecord({
					slug: this.staged.slug,
					name: this.staged.name,
					ruleType: 'decision-table',
					status: this.ruleSet.status || 'draft',
				})
				// The table is stored in OpenRegister's decision-tables
				// register (decision 196). buildiq maps the authoring shape
				// onto the stored one, so the editor sends it to buildiq.
				const tableUrl = generateUrl(
					'/apps/buildiq/api/rules/{slug}/decision-table',
					{ slug: this.staged.slug },
				)
				await axios.put(tableUrl, {
					hitPolicy: this.staged.hitPolicy,
					inputColumns: this.staged.inputColumns,
					rules: this.staged.rules,
				})
				this.$emit('saved')
			} catch (error) {
				this.errorMessage = t(
					'buildiq',
					'Could not save the decision table.',
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.decision-table-editor__col {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}

.decision-table-editor__grid {
	width: 100%;
	border-collapse: collapse;
	margin: 8px 0;
}

.decision-table-editor__grid th,
.decision-table-editor__grid td {
	border: 1px solid var(--color-border);
	padding: 4px;
}

.decision-table-editor__cell {
	width: 100%;
	border: none;
	background: transparent;
}

.decision-table-editor__cell--invalid {
	outline: 2px solid var(--color-error);
}

.decision-table-editor__add-rule {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>
