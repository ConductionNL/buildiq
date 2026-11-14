<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	RuleSetCallersDialog — standalone dialog (gate-modal-isolation) for the
	rule set setting "May be called by" (rules-evaluation-for-other-apps
	REQ-BQRX-001): the apps whose server may evaluate the rule set through
	RuleEvaluationRequestedEvent. Opened from RuleSetsPage, never inlined.
	The enabled apps are offered when the instance lists them to the user;
	an app id can always be typed.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Who may call {name}', { name: ruleSet?.name || ruleSet?.slug || '' })"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="rule-set-callers">
			<NcSelect
				v-model="selected"
				:inputLabel="t('buildiq', 'May be called by')"
				:options="appOptions"
				:multiple="true"
				:taggable="true"
				:placeholder="t('buildiq', 'App id')" />
			<p class="rule-set-callers__hint">
				{{ t('buildiq', 'These apps may evaluate the rule set from their own server, for example a portal form step. They get the outcome, never the table. Leave it empty to keep the rule set to buildiq.') }}
			</p>
			<p v-if="saveError" class="rule-set-callers__error" role="alert">
				{{ saveError }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { saveRuleSetCallers } from './ruleSetRecords.js'

export default {
	name: 'RuleSetCallersDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		open: { type: Boolean, default: false },
		ruleSet: { type: Object, default: null },
	},

	emits: ['update:open', 'saved'],
	data() {
		return {
			selected: [],
			installedApps: [],
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * The enabled apps and the ones already chosen, without buildiq itself.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		appOptions() {
			return [...new Set([...this.installedApps, ...this.selected])]
				.filter((id) => id !== 'buildiq')
				.sort()
		},
	},

	watch: {
		/**
		 * @param {boolean} value - the dialog's new `open` state; opening prefills it.
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		open(value) {
			if (value) {
				this.reset()
			}
		},
	},

	mounted() {
		if (this.open) {
			this.reset()
		}
	},

	methods: {
		/**
		 * Prefill from the rule set and fetch the enabled apps.
		 *
		 * @return {void}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		reset() {
			const current = this.ruleSet?.callableBy
			this.selected = Array.isArray(current) ? [...current] : []
			this.saving = false
			this.saveError = ''
			this.loadApps()
		},

		/**
		 * The enabled apps, when the instance lists them to this user; typing an id always works.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		async loadApps() {
			try {
				const { data } = await axios.get(generateOcsUrl('cloud/apps?filter=enabled'))
				const apps = data?.ocs?.data?.apps
				this.installedApps = Array.isArray(apps) ? apps.map(String) : []
			} catch {
				this.installedApps = []
			}
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		onClose() {
			if (this.saving) {
				return
			}
			this.$emit('update:open', false)
		},

		/**
		 * Store the chosen apps on the rule set.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
		 */
		async save() {
			if (this.saving || !this.ruleSet) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				await saveRuleSetCallers(this.ruleSet, this.selected)
				this.$emit('saved', { slug: this.ruleSet.slug })
				this.$emit('update:open', false)
			} catch {
				this.saveError = t('buildiq', 'Could not save who may call this rule set.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.rule-set-callers {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-bottom: 8px;
}

.rule-set-callers__hint {
	color: var(--color-text-maxcontrast);
}

.rule-set-callers__error {
	color: var(--color-text-error, var(--color-error));
}
</style>
