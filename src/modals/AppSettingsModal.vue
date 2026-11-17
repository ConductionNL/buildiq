<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppSettingsModal — owner-facing app settings. Holds the publish/unpublish
  - toggle (whether the app is live in the Nextcloud app menu), the
  - allow-user-overrides toggle, and the Data registers section (add/remove
  - `Application.dataRegisters` bindings — data-registers-runtime task 5.1).
  - Emits intent; the parent (ApplicationDetailActions) performs the API
  - calls. Kept in its own file per ADR-004 gate-modal-isolation.
  -->
<template>
	<NcModal
		v-if="open"
		:name="t('buildiq', 'App settings')"
		@close="$emit('update:open', false)">
		<div class="app-settings">
			<h3 class="app-settings__title">
				{{ appName }}
			</h3>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="isPublished"
					:disabled="busy"
					@update:modelValue="$emit('set-published', $event)">
					{{ t('buildiq', 'Published') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{
						t(
							'buildiq',
							'Published apps appear in the Nextcloud app menu. Drafts are hidden.',
						)
					}}
				</p>
			</div>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="allowUserOverrides"
					:disabled="busy"
					@update:modelValue="$emit('update:allow-overrides', $event)">
					{{ t('buildiq', 'Allow per-user customisation') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{
						t(
							'buildiq',
							'Let each user layer their own manifest changes on top of the shared app.',
						)
					}}
				</p>
			</div>

			<div v-if="organisationScopeAvailable && isOwner" class="app-settings__row" data-testid="organisation-scope">
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="organisationScope === 'holder'"
					:disabled="busy || confirmingShare"
					@update:modelValue="onOrganisationScope">
					{{ t('buildiq', 'This organisation only') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{
						t(
							'buildiq',
							'Only members of the organisation that holds this app see it, open it and read its records. Rule sets and data registers stay shared.',
						)
					}}
				</p>
				<div v-if="confirmingShare" class="app-settings__confirm" role="alert" data-testid="organisation-scope-confirm">
					<p>
						{{
							t(
								'buildiq',
								'Every organisation will see this app. Its records stay with this organisation.',
							)
						}}
					</p>
					<NcButton variant="primary" data-testid="organisation-scope-share" @click="shareWithAll">
						{{ t('buildiq', 'Share with all organisations') }}
					</NcButton>
					<NcButton variant="tertiary" data-testid="organisation-scope-keep" @click="confirmingShare = false">
						{{ t('buildiq', 'Keep it to this organisation') }}
					</NcButton>
				</div>
			</div>

			<div class="app-settings__row">
				<h4 class="app-settings__subtitle">
					{{ t('buildiq', 'Data registers') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{
						t(
							'buildiq',
							'Shared, non-versioned OpenRegister registers this app binds to alongside its own per-version register (e.g. a dataset fed by OpenConnector). Not owned by this app — promotion and export treat them as reference-only.',
						)
					}}
				</p>
				<!-- Wrapper carries the test hook; NcSelect does not forward
				     stray attributes to the DOM. -->
				<div data-test="app-settings-database-picker">
					<NcSelect
						:modelValue="null"
						:options="databaseOptions"
						:disabled="busy || loadingDatabases"
						:loading="loadingDatabases"
						:inputLabel="t('buildiq', 'Connect a database')"
						@update:modelValue="onDatabasePicked" />
				</div>
				<p
					v-if="!loadingDatabases && !databaseOptions.length"
					class="app-settings__hint">
					{{
						t(
							'buildiq',
							'No connected database to add. An administrator connects one in OpenRegister under Sources.',
						)
					}}
				</p>
				<div
					v-for="(row, index) in rows"
					:key="index"
					class="app-settings__data-register-row">
					<NcTextField
						:modelValue="row.register"
						:label="t('buildiq', 'Register slug')"
						:disabled="busy"
						@update:modelValue="updateRow(index, 'register', $event)" />
					<NcTextField
						:modelValue="row.label"
						:label="t('buildiq', 'Label (optional)')"
						:disabled="busy"
						@update:modelValue="updateRow(index, 'label', $event)" />
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('buildiq', 'Remove data register')"
						@click="removeRow(index)">
						{{ t('buildiq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton variant="secondary" :disabled="busy" @click="addRow">
					{{ t('buildiq', 'Add data register') }}
				</NcButton>
			</div>

			<div class="app-settings__row">
				<h4 class="app-settings__subtitle">
					{{ t('buildiq', 'Flows') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{
						t(
							'buildiq',
							'The OpenRegister flows this app is made of. Exporting the app carries them, so an installed copy can actually do something. Agents are not listed here: they already belong to an app and are collected automatically.',
						)
					}}
				</p>
				<!-- Wrapper carries the test hook; NcSelect, like
				     NcCheckboxRadioSwitch, does not forward stray attributes
				     to the DOM. -->
				<div data-test="app-settings-flow-picker">
					<NcSelect
						:modelValue="selectedFlows"
						:options="flowOptions"
						:multiple="true"
						:disabled="busy || loadingFlows"
						:loading="loadingFlows"
						:inputLabel="t('buildiq', 'Flows this app is made of')"
						@update:modelValue="onFlowsPicked" />
				</div>
				<p
					v-if="!loadingFlows && !availableFlows.length"
					class="app-settings__hint">
					{{
						t(
							'buildiq',
							'No flows exist on this instance yet. Create one in OpenRegister first.',
						)
					}}
				</p>

				<!-- "Run on" per bound flow (REQ-BQSF-001): where the flow
				     runs, on the app version's schemas only. -->
				<div
					v-for="binding in boundFlows"
					:key="binding.flow"
					class="app-settings__run-on"
					:data-test="'app-settings-run-on-' + binding.flow">
					<h5 class="app-settings__run-on-title">
						{{ t('buildiq', 'Run on: {flow}', { flow: flowLabel(binding) }) }}
					</h5>
					<p v-if="!runOnOf(binding).length" class="app-settings__hint app-settings__hint--inline">
						{{ t('buildiq', 'Not wired yet. The flow only runs when something else starts it.') }}
					</p>
					<div
						v-for="(entry, index) in runOnOf(binding)"
						:key="index"
						class="app-settings__run-on-row"
						data-test="app-settings-run-on-row">
						<NcSelect
							:modelValue="schemaOption(entry.schema)"
							:options="schemas"
							:clearable="false"
							:disabled="busy"
							:inputLabel="t('buildiq', 'Schema')"
							@update:modelValue="(option) => setRunOn(binding, index, option && option.value, runOnWhen(entry))" />
						<NcSelect
							:modelValue="whenOption(runOnWhen(entry))"
							:options="whenOptions"
							:clearable="false"
							:disabled="busy"
							:inputLabel="t('buildiq', 'When')"
							@update:modelValue="(option) => setRunOn(binding, index, entry.schema, option && option.value)" />
						<NcButton
							variant="tertiary"
							:disabled="busy"
							data-test="app-settings-run-on-remove"
							@click="removeRunOn(binding, index)">
							{{ t('buildiq', 'Remove') }}
						</NcButton>
					</div>
					<NcButton
						:disabled="busy || !schemas.length"
						data-test="app-settings-run-on-add"
						@click="addRunOn(binding)">
						{{ t('buildiq', 'Add where it runs') }}
					</NcButton>
					<p v-if="!schemas.length" class="app-settings__hint">
						{{ t('buildiq', 'This app has no schemas yet, so the flow cannot run on its records.') }}
					</p>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { fetchConnectedDatabases } from '../composables/useConnectedDatabases.js'
import {
	FROM_RECORD,
	isWrapperBinding,
	runOnEntry,
	runOnWhen,
	withPickedFlows,
	withRunOn,
	WRAPPER_PREFIX,
} from '../utils/flowBindings.js'

export default {
	name: 'AppSettingsModal',
	components: {
		NcModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcSelect,
	},

	props: {
		/** Whether the modal is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Display name of the app. */
		appName: { type: String, default: '' },
		/** Whether the app is currently published. */
		isPublished: { type: Boolean, default: false },
		/** Whether per-user overrides are enabled. */
		allowUserOverrides: { type: Boolean, default: false },
		/** The Application's declared `dataRegisters` bindings (`{register, label?}`). */
		dataRegisters: { type: Array, default: () => [] },
		/**
		 * The Application's declared `flows` bindings (`{flow, label?}`), where
		 * `flow` is the OpenRegister flow's UUID.
		 *
		 * A PICKER rather than the text fields the data registers use: a
		 * register is bound by a slug somebody can read and type, and a flow is
		 * bound by a UUID, which nobody can. The `Flow` entity has no slug to
		 * offer instead.
		 */
		flows: { type: Array, default: () => [] },
		/** Every flow on the instance, as `{label, value}` picker options. */
		availableFlows: { type: Array, default: () => [] },
		/** Whether the flow list is still being fetched. */
		loadingFlows: { type: Boolean, default: false },
		/**
		 * The app version's schemas, as `{label, value}` options with the
		 * slug as value: the only schemas a "Run on" entry may name.
		 */
		schemas: { type: Array, default: () => [] },
		/** Whether an action is in flight (disables the switches). */
		busy: { type: Boolean, default: false },
		/** The app's organisation scope, `holder` or `all` (absent means `all`). */
		organisationScope: { type: String, default: 'all' },
		/** Whether OpenRegister's multitenancy is on, so the scope can be offered. */
		organisationScopeAvailable: { type: Boolean, default: false },
		/** Whether the caller owns the app; only owners set the scope. */
		isOwner: { type: Boolean, default: false },
	},

	emits: [
		'update:open',
		'set-published',
		'update:allow-overrides',
		'update:data-registers',
		'update:flows',
		'update:organisationScope',
	],

	data() {
		return {
			// Whether the "share with all organisations" confirmation is shown.
			confirmingShare: false,
			// Staged editing rows, kept in sync with the `dataRegisters` prop
			// (see the watcher below). `register`/`label` default to '' so
			// NcTextField always receives a string, never undefined.
			rows: this.toRows(this.dataRegisters),
			// The registers OpenRegister serves from a database source, read
			// again every time the modal opens (REQ-BQDB-001).
			databases: [],
			loadingDatabases: false,
		}
	},

	computed: {
		/**
		 * The connected databases not bound yet, as picker options.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 *
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
		 */
		databaseOptions() {
			const bound = new Set(this.rows.map((row) => (row.register || '').trim()))
			return this.databases
				.filter((database) => !bound.has(database.register))
				.map((database) => ({ label: database.label, value: database.register }))
		},

		/**
		 * The bound flows, as picker options.
		 *
		 * Resolved against `availableFlows` so the picker shows the flow's
		 * NAME. A binding whose UUID no longer resolves is kept and shown as
		 * the raw UUID rather than dropped: a flow that was deleted is an
		 * ordinary situation, and silently removing the binding would hide it
		 * from the person who could fix it.
		 *
		 * @return {Array<{label: string, value: string}>} Selected options.
		 *
		 * @spec openspec/specs/application-detail-ui/spec.md
		 */
		selectedFlows() {
			return this.boundFlows.map((binding) => {
				const known = this.availableFlows.find(
					(option) => option.value === binding.flow,
				)

				return (
					known || {
						label:
							binding.label
							|| this.t('buildiq', '{uuid} (no longer exists)', {
								uuid: binding.flow,
							}),
						value: binding.flow,
					}
				)
			})
		},

		/**
		 * The flows the maker bound, without buildiq's own wrapper flows.
		 *
		 * @return {Array<object>} Bindings.
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		boundFlows() {
			return this.flows.filter((binding) => !isWrapperBinding(binding))
		},

		/**
		 * The flows a maker may pick: every flow but buildiq's wrappers.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		flowOptions() {
			return this.availableFlows.filter(
				(option) => !String(option.label || '').startsWith(WRAPPER_PREFIX),
			)
		},

		/**
		 * The "when" choices of a "Run on" entry.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		whenOptions() {
			return [
				{ label: this.t('buildiq', 'Created'), value: 'object.created' },
				{ label: this.t('buildiq', 'Updated'), value: 'object.updated' },
				{ label: this.t('buildiq', 'Deleted'), value: 'object.deleted' },
				{ label: this.t('buildiq', 'From a record'), value: FROM_RECORD },
			]
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the connected databases again whenever the modal opens.
			 *
			 * @param {boolean} isOpen Whether the modal is shown.
			 * @return {void}
			 *
			 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
			 */
			handler(isOpen) {
				if (isOpen) {
					this.loadDatabases()
				}
			},
		},

		dataRegisters: {
			deep: true,
			/**
			 * Re-sync the staged rows whenever the canonical prop changes —
			 * covers the initial async Application load (empty at mount,
			 * populated once `obLoadApp()` resolves) and the echoed value
			 * `obPatchApp()`'s response carries back after every save.
			 *
			 * @param {Array} next The new dataRegisters value.
			 * @return {void}
			 */
			handler(next) {
				this.rows = this.toRows(next)
			},
		},
	},

	methods: {
		/**
		 * Switch the organisation scope. Keeping the app to the organisation
		 * applies at once; sharing it with every organisation asks first.
		 *
		 * @param {boolean} holderOnly Whether the switch is now on.
		 * @return {void}
		 *
		 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
		 */
		onOrganisationScope(holderOnly) {
			if (holderOnly) {
				this.confirmingShare = false
				this.$emit('update:organisationScope', 'holder')
				return
			}
			this.confirmingShare = true
		},

		/**
		 * Confirmed: share the app with every organisation.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md#requirement-an-app-has-an-organisation-scope-req-bqos-001
		 */
		shareWithAll() {
			this.confirmingShare = false
			this.$emit('update:organisationScope', 'all')
		},

		/**
		 * Fetch the registers OpenRegister serves from a database source.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
		 */
		async loadDatabases() {
			this.loadingDatabases = true
			try {
				this.databases = await fetchConnectedDatabases()
			} finally {
				this.loadingDatabases = false
			}
		},

		/**
		 * Bind a picked database as a new data register row and emit it.
		 *
		 * @param {{label: string, value: string}|null} option The picked database.
		 * @return {void}
		 *
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
		 */
		onDatabasePicked(option) {
			if (!option || !option.value) {
				return
			}
			this.rows = [...this.rows, { register: option.value, label: option.label || '' }]
			this.emitRows()
		},

		/**
		 * Emit the new bindings when the picker changes.
		 *
		 * `label` is stored alongside the UUID so a later reader — and the
		 * export dialog's checkbox list — has something legible even if the
		 * flow is renamed or removed.
		 *
		 * @param {Array<{label: string, value: string}>} picked The chosen options.
		 * @return {void}
		 */
		onFlowsPicked(picked) {
			// A flow that stays picked keeps its binding as it was, `runOn`
			// included, and buildiq's wrappers travel along untouched.
			this.$emit('update:flows', withPickedFlows(this.flows, picked))
		},

		runOnWhen,

		/**
		 * A bound flow's `runOn` entries.
		 *
		 * @param {object} binding The binding.
		 * @return {Array<object>} Entries.
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		runOnOf(binding) {
			return Array.isArray(binding.runOn) ? binding.runOn : []
		},

		/**
		 * The name a bound flow is shown by.
		 *
		 * @param {object} binding The binding.
		 * @return {string} The flow's name.
		 */
		flowLabel(binding) {
			const known = this.availableFlows.find((option) => option.value === binding.flow)
			return (known && known.label) || binding.label || binding.flow
		},

		/**
		 * The picker option of a schema slug; an unknown slug is shown as itself.
		 *
		 * @param {string} slug The schema slug.
		 * @return {?{label: string, value: string}} The option.
		 */
		schemaOption(slug) {
			if (!slug) {
				return null
			}
			return this.schemas.find((option) => option.value === slug) || { label: slug, value: slug }
		},

		/**
		 * The picker option of a "when".
		 *
		 * @param {string} when The event or `from-record`.
		 * @return {?{label: string, value: string}} The option.
		 */
		whenOption(when) {
			return this.whenOptions.find((option) => option.value === when) || null
		},

		/**
		 * Emit the bindings with one bound flow's entries replaced.
		 *
		 * @param {object} binding The binding.
		 * @param {Array<object>} runOn Its new entries.
		 * @return {void}
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		emitRunOn(binding, runOn) {
			this.$emit('update:flows', withRunOn(this.flows, binding.flow, runOn))
		},

		/**
		 * Add an entry on the first schema, on updates.
		 *
		 * @param {object} binding The binding.
		 * @return {void}
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		addRunOn(binding) {
			if (!this.schemas.length) {
				return
			}
			this.emitRunOn(binding, [...this.runOnOf(binding), runOnEntry(this.schemas[0].value, 'object.updated')])
		},

		/**
		 * Change one entry's schema or "when".
		 *
		 * @param {object} binding The binding.
		 * @param {number} index The entry.
		 * @param {string} schema The schema slug.
		 * @param {string} when The event or `from-record`.
		 * @return {void}
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		setRunOn(binding, index, schema, when) {
			if (!schema) {
				return
			}
			const runOn = this.runOnOf(binding).map((entry, at) => (at === index ? runOnEntry(schema, when) : entry))
			this.emitRunOn(binding, runOn)
		},

		/**
		 * Remove one entry.
		 *
		 * @param {object} binding The binding.
		 * @param {number} index The entry.
		 * @return {void}
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
		 */
		removeRunOn(binding, index) {
			this.emitRunOn(binding, this.runOnOf(binding).filter((entry, at) => at !== index))
		},

		/**
		 * Normalise a `dataRegisters` array into editable rows.
		 *
		 * @param {Array} list The dataRegisters prop value.
		 * @return {Array<{register: string, label: string}>}
		 */
		toRows(list) {
			return (Array.isArray(list) ? list : []).map((binding) => ({
				register: (binding && binding.register) || '',
				label: (binding && binding.label) || '',
			}))
		},

		/**
		 * Append a new, empty row. Not emitted until it carries a
		 * non-empty `register` (see emitRows()).
		 *
		 * @return {void}
		 */
		addRow() {
			const next = this.rows.slice()
			next.push({ register: '', label: '' })
			this.rows = next
			this.emitRows()
		},

		/**
		 * Remove a row by index.
		 *
		 * @param {number} index Row index.
		 * @return {void}
		 */
		removeRow(index) {
			const next = this.rows.slice()
			next.splice(index, 1)
			this.rows = next
			this.emitRows()
		},

		/**
		 * Update a single field on a row by index.
		 *
		 * @param {number} index Row index.
		 * @param {string} key `register` or `label`.
		 * @param {string} value New field value.
		 * @return {void}
		 */
		updateRow(index, key, value) {
			const next = this.rows.slice()
			next[index] = { ...next[index], [key]: value }
			this.rows = next
			this.emitRows()
		},

		/**
		 * Emit the full `dataRegisters` array — every change (add/remove/
		 * edit) emits immediately, matching the existing
		 * `update:allow-overrides` pattern on this same modal. Rows with
		 * no `register` slug yet (mid-edit) are dropped from the emitted
		 * payload; a present-but-empty `label` is omitted rather than sent
		 * as `''`.
		 *
		 * @return {void}
		 */
		emitRows() {
			const cleaned = this.rows
				.map((row) => ({
					register: (row.register || '').trim(),
					label: (row.label || '').trim(),
				}))
				.filter((row) => row.register !== '')
				.map((row) => (row.label !== '' ? row : { register: row.register }))
			this.$emit('update:data-registers', cleaned)
		},
	},
}
</script>

<style scoped>
.app-settings__run-on {
	margin-top: 12px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.app-settings__run-on-title {
	margin: 0 0 8px;
	font-weight: bold;
}

.app-settings__run-on-row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}

.app-settings {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.app-settings__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.app-settings__subtitle {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.app-settings__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.app-settings__hint {
	margin: 0 0 0 44px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.app-settings__hint--inline {
	margin-left: 0;
}

.app-settings__data-register-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
</style>
