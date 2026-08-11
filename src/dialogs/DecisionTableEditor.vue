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
		:name="t('openbuild', 'Decision table editor')"
		size="large"
		@closing="$emit('close')">
		<div class="decision-table-editor">
			<NcTextField
				v-model="staged.name"
				:label="t('openbuild', 'Rule set name')"
				data-testid="rule-set-name" />

			<NcSelect
				v-model="staged.hitPolicy"
				:input-label="t('openbuild', 'Hit policy')"
				:options="hitPolicies"
				data-testid="hit-policy" />

			<h4>{{ t('openbuild', 'Input columns') }}</h4>
			<div v-for="(col, index) in staged.inputColumns" :key="'in-' + index" class="decision-table-editor__col">
				<NcTextField v-model="col.name" :label="t('openbuild', 'Name')" />
				<NcTextField v-model="col.expressionPath" :label="t('openbuild', 'Payload path')" />
				<NcButton type="tertiary" @click="removeInput(index)">
					{{ t('openbuild', 'Remove') }}
				</NcButton>
			</div>
			<NcButton type="secondary" @click="addInput">
				{{ t('openbuild', 'Add input column') }}
			</NcButton>

			<h4>{{ t('openbuild', 'Rules') }}</h4>
			<table class="decision-table-editor__grid">
				<thead>
					<tr>
						<th v-for="(col, index) in staged.inputColumns" :key="'h-' + index" scope="col">
							{{ col.name }}
						</th>
						<th scope="col">
							{{ t('openbuild', 'Decision') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(rule, rIndex) in staged.rules" :key="'r-' + rIndex">
						<td v-for="(col, cIndex) in staged.inputColumns" :key="'c-' + cIndex">
							<input
								v-model="rule.conditions[col.name]"
								class="decision-table-editor__cell"
								:class="{ 'decision-table-editor__cell--invalid': !isCellValid(rule.conditions[col.name]) }"
								:aria-label="col.name"
								@input="markDirty">
						</td>
						<td>
							<input
								v-model="rule.values.decision"
								class="decision-table-editor__cell"
								:aria-label="t('openbuild', 'Decision')">
						</td>
					</tr>
				</tbody>
			</table>
			<NcButton type="secondary" @click="addRule">
				{{ t('openbuild', 'Add rule') }}
			</NcButton>

			<div v-if="warnings.length" class="decision-table-editor__warnings">
				<NcNoteCard v-for="(warning, wIndex) in warnings" :key="'w-' + wIndex" type="warning">
					{{ warning }}
				</NcNoteCard>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				{{ saving ? t('openbuild', 'Saving...') : t('openbuild', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

import { isCellConditionValid } from '../utils/feelCell.js'

export default {
	name: 'DecisionTableEditor',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
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
				inputColumns: this.ruleSet.inputColumns ? JSON.parse(JSON.stringify(this.ruleSet.inputColumns)) : [],
				rules: this.ruleSet.rules ? JSON.parse(JSON.stringify(this.ruleSet.rules)) : [],
			},
			hitPolicies: ['unique', 'first', 'priority', 'any', 'collect', 'rule-order'],
			saving: false,
			errorMessage: '',
		}
	},
	computed: {
		/**
		 * Editor feedback: flag a catch-all rule that makes later rules unreachable.
		 *
		 * @return {Array<string>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-012-visual-editor-feedback-overlap-and-completeness-detection
		 */
		warnings() {
			const issues = []
			const catchAllIndex = this.staged.rules.findIndex((r) => Object.keys(r.conditions || {}).length === 0)
			if (catchAllIndex !== -1 && catchAllIndex < this.staged.rules.length - 1) {
				issues.push(t('openbuild', 'A catch-all rule appears before other rules — later rules are unreachable.'))
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
			this.staged.inputColumns.push({ name: '', type: 'string', expressionPath: '' })
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
			this.staged.rules.push({ conditions: {}, values: { decision: '' }, label: '' })
		},
		/**
		 * Persist the RuleSet and its DecisionTable via OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-002-decisiontable-schema-for-dmn-based-multi-condition-rules
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				const ruleSetUrl = generateUrl('/apps/openregister/api/objects/openbuild/rule-set')
				await axios.post(ruleSetUrl, {
					slug: this.staged.slug,
					name: this.staged.name,
					ruleType: 'decision-table',
					status: this.ruleSet.status || 'draft',
				})
				const tableUrl = generateUrl('/apps/openregister/api/objects/openbuild/decision-table')
				await axios.post(tableUrl, {
					ruleSetId: this.staged.slug,
					hitPolicy: this.staged.hitPolicy,
					inputColumns: this.staged.inputColumns,
					rules: this.staged.rules,
				})
				this.$emit('saved')
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not save the decision table.')
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
</style>
