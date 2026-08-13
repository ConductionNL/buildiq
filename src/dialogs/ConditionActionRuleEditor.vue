<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - ConditionActionRuleEditor — form editor for a condition-action rule (spec
  - business-rules-engine REQ-BRE-003). Per ADR-004 modal isolation it is a
  - standalone NcDialog under src/dialogs/, imported by RuleSetsPage. Owns a
  - staged rule with name, priority, salience, a FEEL condition, an ordered
  - action list (set-field / send-notification / start-workflow / call-rule-set)
  - and an active toggle. Persists via OpenRegister REST and emits `saved`.
  -->
<template>
	<NcDialog
		:name="t('openbuild', 'Condition-action rule editor')"
		size="large"
		@closing="$emit('close')">
		<div class="condition-action-editor">
			<NcTextField
				v-model="staged.name"
				:label="t('openbuild', 'Rule name')"
				data-testid="rule-name" />
			<NcTextField
				v-model="staged.description"
				:label="t('openbuild', 'Description')" />

			<div class="condition-action-editor__row">
				<NcTextField
					v-model.number="staged.priority"
					type="number"
					:label="t('openbuild', 'Priority')" />
				<NcTextField
					v-model.number="staged.salience"
					type="number"
					:label="t('openbuild', 'Salience')" />
			</div>

			<NcTextArea
				v-model="staged.condition"
				:label="t('openbuild', 'Condition (FEEL)')"
				data-testid="rule-condition" />

			<h4>{{ t('openbuild', 'Actions') }}</h4>
			<div
				v-for="(action, index) in staged.actions"
				:key="'a-' + index"
				class="condition-action-editor__action">
				<NcSelect
					v-model="action.type"
					:input-label="t('openbuild', 'Action type')"
					:options="actionTypes" />
				<NcButton type="tertiary" @click="removeAction(index)">
					{{ t('openbuild', 'Remove') }}
				</NcButton>
			</div>
			<NcButton type="secondary" @click="addAction">
				{{ t('openbuild', 'Add action') }}
			</NcButton>

			<NcCheckboxRadioSwitch
				v-model="staged.active"
				:aria-label="t('openbuild', 'Active')">
				{{ t('openbuild', 'Active') }}
			</NcCheckboxRadioSwitch>

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
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'ConditionActionRuleEditor',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextArea,
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
				description: this.ruleSet.description || '',
				priority: this.ruleSet.priority || 0,
				salience: this.ruleSet.salience || 0,
				condition: this.ruleSet.condition || '',
				actions: this.ruleSet.actions
					? JSON.parse(JSON.stringify(this.ruleSet.actions))
					: [],
				active: this.ruleSet.active !== false,
			},
			actionTypes: [
				'set-field',
				'send-notification',
				'start-workflow',
				'call-rule-set',
			],
			saving: false,
			errorMessage: '',
		}
	},
	methods: {
		/**
		 * Append an empty action row to the staged rule.
		 *
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-003-conditionactionrule-schema-for-sequential-workflow-decisions
		 */
		addAction() {
			this.staged.actions.push({ type: 'set-field', parameters: {} })
		},
		/**
		 * Remove one action row from the staged rule.
		 *
		 * @param {number} index - position of the action to drop.
		 * @return {void}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-003-conditionactionrule-schema-for-sequential-workflow-decisions
		 */
		removeAction(index) {
			this.staged.actions.splice(index, 1)
		},
		/**
		 * Persist the RuleSet and its ConditionActionRule via OpenRegister.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-003-conditionactionrule-schema-for-sequential-workflow-decisions
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				const ruleSetUrl = generateUrl(
					'/apps/openregister/api/objects/openbuild/rule-set',
				)
				await axios.post(ruleSetUrl, {
					slug: this.staged.slug,
					name: this.staged.name,
					ruleType: 'condition-action',
					status: this.ruleSet.status || 'draft',
				})
				const ruleUrl = generateUrl(
					'/apps/openregister/api/objects/openbuild/condition-action-rule',
				)
				await axios.post(ruleUrl, {
					ruleSetId: this.staged.slug,
					name: this.staged.name,
					description: this.staged.description,
					priority: this.staged.priority,
					salience: this.staged.salience,
					condition: this.staged.condition,
					actions: this.staged.actions,
					active: this.staged.active,
				})
				this.$emit('saved')
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not save the rule.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.condition-action-editor__row {
	display: flex;
	gap: 8px;
}

.condition-action-editor__action {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}
</style>
