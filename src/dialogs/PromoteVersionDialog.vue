<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<NcDialog
		:name="t('openbuild', 'Promote version')"
		:can-close="true"
		size="normal"
		@closing="onCancel">
		<!-- No-target state -->
		<div v-if="!targetVersion" class="promote-dialog promote-dialog--no-target">
			<p>
				{{
					t(
						'openbuild',
						'This version has no downstream target. Set a "Promotes to" relation to enable promotion.',
					)
				}}
			</p>
		</div>

		<!-- Form state -->
		<form v-else class="promote-dialog" @submit.prevent="onConfirm">
			<header class="promote-dialog__header">
				<h3>{{ summaryText }}</h3>
				<p class="promote-dialog__registers">
					<span
						>{{ t('openbuild', 'Source register:') }}
						<code>{{ sourceVersion.register }}</code></span
					>
					<span
						>{{ t('openbuild', 'Target register:') }}
						<code>{{ targetVersion.register }}</code></span
					>
				</p>
			</header>

			<fieldset class="promote-dialog__strategies">
				<legend class="promote-dialog__strategies-legend">
					{{ t('openbuild', 'Data strategy') }}
				</legend>

				<NcCheckboxRadioSwitch
					v-model="selectedStrategy"
					value="start-with-source-data"
					name="promote-strategy"
					type="radio">
					<strong>{{
						t('openbuild', 'Start target with source data')
					}}</strong>
					<span class="promote-dialog__strategy-description">
						{{
							t(
								'openbuild',
								"Replace the target version's rows with copies of the source's rows. Useful when the test data is the new shape of production data.",
							)
						}}
					</span>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					v-model="selectedStrategy"
					value="migrate-existing-data"
					name="promote-strategy"
					type="radio">
					<strong>{{
						t('openbuild', "Migrate target's existing data")
					}}</strong>
					<span class="promote-dialog__strategy-description">
						{{
							t(
								'openbuild',
								"Keep the target version's existing rows and apply the source's schema set. OpenRegister handles column-level migration for breaking changes.",
							)
						}}
					</span>
				</NcCheckboxRadioSwitch>

				<NcCheckboxRadioSwitch
					v-model="selectedStrategy"
					value="empty-start"
					name="promote-strategy"
					type="radio">
					<strong>{{
						t('openbuild', 'Empty start (destructive)')
					}}</strong>
					<span
						class="promote-dialog__strategy-description promote-dialog__strategy-description--destructive">
						{{
							t(
								'openbuild',
								"Drop every row in the target's register and install the source's schema set without copying data. This cannot be undone.",
							)
						}}
					</span>
				</NcCheckboxRadioSwitch>
			</fieldset>

			<div
				v-if="selectedStrategy === 'empty-start'"
				class="promote-dialog__confirm-gate">
				<NcTextField
					v-model="typedSlug"
					:label="confirmInputLabel"
					:placeholder="application ? application.slug : ''"
					autocomplete="off"
					:helper-text="confirmHelperText" />
			</div>
		</form>

		<!-- Actions slot — always at NcDialog level -->
		<template #actions>
			<NcButton type="tertiary" @click="onCancel">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="targetVersion"
				type="primary"
				:disabled="!isDestructiveGateMet"
				@click="onConfirm">
				{{ t('openbuild', 'Promote') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import { defaultStrategyFor } from './promoteVersionDefaults.js'

/**
 * PromoteVersionDialog
 *
 * Standalone modal for the ApplicationVersion promotion flow
 * (spec REQ-OBVP-010, ADR-004 modal-isolation rule). The dialog is a
 * pure presentation component — it does NOT call the backend itself.
 * The parent surface (delivered by sibling spec
 * `openbuild-app-detail-overview`) listens to the emitted events and
 * performs the network call.
 *
 * Props:
 *   - sourceVersion: ApplicationVersion (required)
 *   - targetVersion: ApplicationVersion | null — version pointed at by
 *     sourceVersion.promotesTo; when null the dialog renders a no-target
 *     body with a Cancel-only footer.
 *   - application: Application (required) — supplies `slug` (for the
 *     destructive-confirmation gate) and `productionVersion` (for the
 *     default-strategy rule).
 *
 * Emits:
 *   - confirm: { strategy: 'start-with-source-data' |
 *               'migrate-existing-data' | 'empty-start' }
 *   - cancel
 */
export default {
	name: 'PromoteVersionDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},
	props: {
		sourceVersion: {
			type: Object,
			required: true,
		},
		targetVersion: {
			type: Object,
			default: null,
		},
		application: {
			type: Object,
			required: true,
		},
	},
	data() {
		return {
			selectedStrategy: this.computeDefaultStrategy(),
			typedSlug: '',
		}
	},
	computed: {
		/**
		 * Summary heading rendered above the strategy radio group.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		summaryText() {
			const sourceName =
				this.sourceVersion?.name || this.sourceVersion?.slug || '?'
			const targetName =
				this.targetVersion?.name || this.targetVersion?.slug || '?'
			return t('openbuild', 'Promote {source} to {target}', {
				source: sourceName,
				target: targetName,
			})
		},

		/**
		 * Destructive-confirmation gate (spec REQ-OBVP-010).
		 *
		 * For any strategy other than `empty-start`, the gate is
		 * always met. For `empty-start`, the gate requires the typed
		 * value to exactly match the parent Application's `slug`
		 * (case-sensitive byte-equal).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		isDestructiveGateMet() {
			if (this.selectedStrategy !== 'empty-start') {
				return true
			}

			const expected = this.application?.slug || ''
			return expected !== '' && this.typedSlug === expected
		},

		/**
		 * i18n'd label for the destructive-confirmation input.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		confirmInputLabel() {
			return t('openbuild', 'Type the application slug to confirm')
		},

		/**
		 * Hint string under the destructive-confirmation input.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		confirmHelperText() {
			return t(
				'openbuild',
				'Empty start will permanently delete every row in the target\'s register. Type "{slug}" to confirm.',
				{ slug: this.application?.slug || '' },
			)
		},
	},
	watch: {
		/**
		 * Observed behaviour of `targetVersion` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		targetVersion() {
			this.selectedStrategy = this.computeDefaultStrategy()
			this.typedSlug = ''
		},
		/**
		 * Observed behaviour of `application` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		application() {
			this.selectedStrategy = this.computeDefaultStrategy()
		},
	},
	methods: {
		/**
		 * Compute the default strategy via the pure-function rule.
		 *
		 * Mirrors `VersionPromotionService::defaultStrategyFor()` (PHP).
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		computeDefaultStrategy() {
			if (!this.targetVersion || !this.application) {
				return 'start-with-source-data'
			}

			return defaultStrategyFor(this.application, this.targetVersion)
		},

		/**
		 * Emit `confirm` with the chosen strategy when the gate is met.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		onConfirm() {
			if (!this.isDestructiveGateMet) {
				return
			}

			this.$emit('confirm', { strategy: this.selectedStrategy })
		},

		/**
		 * Emit `cancel` and let the parent close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-version-routing-ui/tasks.md#task-2
		 */
		onCancel() {
			this.$emit('cancel')
		},
	},
}
</script>

<style scoped>
.promote-dialog {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: calc(var(--default-grid-baseline, 8px) * 2);
}

.promote-dialog__header h3 {
	margin: 0 0 var(--default-grid-baseline, 8px);
}

.promote-dialog__registers {
	display: flex;
	flex-direction: column;
	gap: 4px;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast, #6b6b6b);
}

.promote-dialog__strategies {
	border: 1px solid var(--color-border, #d8d8d8);
	border-radius: var(--border-radius-large, 12px);
	padding: var(--default-grid-baseline, 8px);
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
}

.promote-dialog__strategies-legend {
	padding: 0 4px;
	font-weight: 600;
}

.promote-dialog__strategy-description {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #6b6b6b);
	margin-top: 2px;
}

.promote-dialog__strategy-description--destructive {
	color: var(--color-error, #b00020);
}

.promote-dialog__confirm-gate {
	margin-top: var(--default-grid-baseline, 8px);
}
</style>
