<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppSettingsModal — owner-facing app settings. Holds the publish/unpublish
  - toggle (whether the app is live in the Nextcloud app menu), the
  - allow-user-overrides toggle, and the Data registers section (add/remove
  - `Application.dataRegisters` bindings — data-registers-runtime task 5.1),
  - and the Languages section (the version manifest's `i18n.sourceLanguage`
  - and `i18n.languages`, experience-multilingual-apps REQ-BQML-001).
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
				<AppEmbedSettings
					:embed="embed"
					:slug="appSlug"
					:appName="appName"
					:busy="busy"
					@update:embed="$emit('update:embed', $event)" />
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

			<div class="app-settings__row" data-test="app-settings-languages">
				<h4 class="app-settings__subtitle">
					{{ t('buildiq', 'Languages') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{
						t(
							'buildiq',
							'The language the app\'s labels are written in, and the languages they are translated into. Each user sees the app in their own Nextcloud language where a translation exists. Translate the labels from the page designer.',
						)
					}}
				</p>
				<div data-test="app-settings-source-language">
					<NcSelect
						:modelValue="sourceOption"
						:options="sourceOptions"
						:clearable="false"
						:disabled="!canEditLanguages || busy"
						:inputLabel="t('buildiq', 'Labels are written in')"
						@update:modelValue="onSourcePicked" />
				</div>
				<ul class="app-settings__languages">
					<li
						v-for="code in languages"
						:key="code"
						class="app-settings__language"
						data-test="app-settings-language">
						<span>{{ languageLabel(code) }}</span>
						<template v-if="pendingRemoval === code">
							<span class="app-settings__confirm" role="alert">
								{{ t('buildiq', 'Remove {language} and drop its translations?', { language: languageLabel(code) }) }}
							</span>
							<NcButton
								variant="error"
								:disabled="busy"
								data-test="app-settings-language-confirm-remove"
								@click="confirmRemoveLanguage(code)">
								{{ t('buildiq', 'Remove language') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								@click="pendingRemoval = ''">
								{{ t('buildiq', 'Cancel') }}
							</NcButton>
						</template>
						<NcButton
							v-else
							variant="tertiary"
							:disabled="!canEditLanguages || busy"
							:aria-label="t('buildiq', 'Remove {language}', { language: languageLabel(code) })"
							data-test="app-settings-language-remove"
							@click="pendingRemoval = code">
							{{ t('buildiq', 'Remove') }}
						</NcButton>
					</li>
				</ul>
				<div data-test="app-settings-add-language">
					<NcSelect
						:modelValue="null"
						:options="addOptions"
						:disabled="!canEditLanguages || busy || !sourceLanguage"
						:inputLabel="t('buildiq', 'Add a language')"
						@update:modelValue="onLanguageAdded" />
				</div>
				<p v-if="!canEditLanguages" class="app-settings__hint app-settings__hint--inline">
					{{ t('buildiq', 'Only the app\'s owners and editors can change its languages.') }}
				</p>
			</div>
			<div class="app-settings__row" data-test="app-settings-installable">
				<h4 class="app-settings__subtitle">
					{{ t('buildiq', 'Installable') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{
						t(
							'buildiq',
							'Users can add this app to their phone or desktop from its run address. These set how it looks once installed.',
						)
					}}
				</p>
				<NcTextField
					:modelValue="installShortName"
					:label="t('buildiq', 'Short name')"
					:helperText="t('buildiq', 'Shown under the icon. At most 12 characters; empty uses the app name.')"
					:maxlength="12"
					:disabled="busy"
					data-test="app-settings-short-name"
					@update:modelValue="installShortName = $event"
					@blur="emitInstallable" />
				<label class="app-settings__label" for="app-settings-theme-color">
					{{ t('buildiq', 'Theme colour') }}
				</label>
				<input
					id="app-settings-theme-color"
					class="app-settings__color"
					type="color"
					:value="installThemeColor || '#ffffff'"
					:disabled="busy"
					data-test="app-settings-theme-color"
					@change="onThemeColor($event.target.value)">
				<NcButton
					v-if="installThemeColor"
					variant="tertiary"
					:disabled="busy"
					@click="onThemeColor('')">
					{{ t('buildiq', 'Use the browser colour') }}
				</NcButton>
			</div>
			<div
				v-if="offlineAllowed"
				class="app-settings__row"
				data-test="app-settings-offline">
				<h4 class="app-settings__subtitle">
					{{ t('buildiq', 'Offline') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{
						t(
							'buildiq',
							'Records this app takes along to the device, for use without a connection. Form pages that work offline are switched on in the page designer.',
						)
					}}
				</p>
				<div
					v-for="(row, index) in takeAlongRows"
					:key="index"
					class="app-settings__take-along"
					data-test="app-settings-take-along-row">
					<NcTextField
						:modelValue="row.schema"
						:label="t('buildiq', 'Schema')"
						:disabled="busy"
						@update:modelValue="row.schema = $event"
						@blur="emitOffline" />
					<NcTextField
						:modelValue="row.filter"
						:label="t('buildiq', 'Filter')"
						:helperText="filterHelp"
						:error="row.filterInvalid"
						:disabled="busy"
						@update:modelValue="row.filter = $event"
						@blur="emitOffline" />
					<NcTextField
						type="number"
						:modelValue="String(row.limit)"
						:label="t('buildiq', 'At most this many records (up to 500)')"
						:disabled="busy"
						@update:modelValue="row.limit = $event"
						@blur="emitOffline" />
					<NcTextField
						type="number"
						:modelValue="String(row.ttlHours)"
						:label="t('buildiq', 'Usable for this many hours (up to 72)')"
						:disabled="busy"
						@update:modelValue="row.ttlHours = $event"
						@blur="emitOffline" />
					<NcButton
						variant="tertiary"
						:disabled="busy"
						:aria-label="t('buildiq', 'Remove')"
						@click="removeTakeAlong(index)">
						{{ t('buildiq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton
					:disabled="busy"
					data-test="app-settings-add-take-along"
					@click="addTakeAlong">
					{{ t('buildiq', 'Add records to take along') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { getLanguage } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AppEmbedSettings from '../components/AppEmbedSettings.vue'
import { fetchConnectedDatabases } from '../composables/useConnectedDatabases.js'
import { languageName, NEXTCLOUD_LANGUAGES } from '../services/appLanguages.js'
import { normaliseOffline, OFFLINE_LIMIT_DEFAULT, OFFLINE_TTL_DEFAULT } from '../services/offlineManifest.js'
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
		AppEmbedSettings,
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
		appSlug: { type: String, default: '' },
		embed: { type: Object, default: null },
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
		/** The installed app's short name (`Application.shortName`), '' for the app name. */
		shortName: { type: String, default: '' },
		/** The installed app's theme colour (`Application.themeColor`), '' for none. */
		themeColor: { type: String, default: '' },
		/** Whether the instance lets apps work offline; hides the Offline section when not. */
		offlineAllowed: { type: Boolean, default: false },
		/** The manifest's `offline` block (`{takeAlong, forms}`), or null. */
		offline: { type: Object, default: null },
		/** Whether an action is in flight (disables the switches). */
		busy: { type: Boolean, default: false },
		/** The language the app's labels are written in (`i18n.sourceLanguage`). */
		sourceLanguage: { type: String, default: '' },
		/** The languages the labels are translated into (`i18n.languages`). */
		languages: { type: Array, default: () => [] },
		/** Whether the viewer may change the languages (owner or editor). */
		canEditLanguages: { type: Boolean, default: false },
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
		'update:embed',
		'update:data-registers',
		'update:flows',
		'update:languages',
		'loadLanguages',
		'update:installable',
		'update:offline',
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
			// The language a maker asked to remove, awaiting confirmation.
			pendingRemoval: '',
			// The registers OpenRegister serves from a database source, read
			// again every time the modal opens (REQ-BQDB-001).
			databases: [],
			loadingDatabases: false,
			// The Installable section's staged values (REQ-BQOM-001).
			installShortName: this.shortName,
			installThemeColor: this.themeColor,
			// The Offline section's staged take-along rows (REQ-BQOM-003).
			takeAlongRows: this.toTakeAlongRows(this.offline),
		}
	},

	computed: {
		/**
		 * Every Nextcloud language as a picker option.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
		 */
		sourceOptions() {
			return NEXTCLOUD_LANGUAGES.map((code) => ({
				label: this.languageLabel(code),
				value: code,
			}))
		},

		/**
		 * The written language as a picker option, null when unset.
		 *
		 * @return {?{label: string, value: string}} The option.
		 */
		sourceOption() {
			return this.sourceLanguage
				? { label: this.languageLabel(this.sourceLanguage), value: this.sourceLanguage }
				: null
		},

		/**
		 * The languages that can still be added: not the written one and not
		 * one already offered.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
		 */
		addOptions() {
			const taken = new Set([this.sourceLanguage, ...this.languages])
			return this.sourceOptions.filter((option) => !taken.has(option.value))
		},

		/**
		 * The take-along filter's helper text.
		 *
		 * @return {string}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
		 */
		filterHelp() {
			return t('buildiq', 'As JSON, for example {example}. Empty takes along every record up to the limit.', { example: '{"assignee": "@me"}' })
		},

		/**
		 * The connected databases not bound yet, as picker options.
		 *
		 * @return {Array<{label: string, value: string}>} Options.
		 *
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
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
			 * Each time the modal opens: ask the parent for the version's
			 * languages (they live in the manifest, which the Application record
			 * the other settings come from does not carry), and read the connected
			 * databases again.
			 *
			 * @param {boolean} open Whether the modal is now open.
			 * @return {void}
			 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
			 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
			 */
			handler(open) {
				if (open) {
					this.$emit('loadLanguages')
					this.loadDatabases()
				}
			},
		},

		/**
		 * Re-stage the install short name when the saved one changes.
		 *
		 * @param {string} next The new short name.
		 * @return {void}
		 *
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
		 */
		shortName(next) {
			this.installShortName = next
		},

		/**
		 * Re-stage the install theme colour when the saved one changes.
		 *
		 * @param {string} next The new theme colour.
		 * @return {void}
		 *
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
		 */
		themeColor(next) {
			this.installThemeColor = next
		},

		offline: {
			deep: true,
			/**
			 * Re-stage the take-along rows when the saved block changes.
			 *
			 * @param {object|null} next The new offline block.
			 * @return {void}
			 *
			 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
			 */
			handler(next) {
				this.takeAlongRows = this.toTakeAlongRows(next)
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
		 * A language's name in the viewer's language, with its code.
		 *
		 * @param {string} code A Nextcloud language code.
		 * @return {string} The label.
		 */
		languageLabel(code) {
			const name = languageName(code, getLanguage())
			return name === code ? code : `${name} (${code})`
		},

		/**
		 * Emit the languages after the written language changes. A language
		 * that becomes the written one stops being translated into.
		 *
		 * @param {?{value: string}} option The picked option.
		 * @return {void}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
		 */
		onSourcePicked(option) {
			if (!option || !option.value || option.value === this.sourceLanguage) {
				return
			}
			this.$emit('update:languages', {
				sourceLanguage: option.value,
				languages: this.languages.filter((code) => code !== option.value),
			})
		},

		/**
		 * Emit the languages with one added.
		 *
		 * @param {?{value: string}} option The picked option.
		 * @return {void}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
		 */
		onLanguageAdded(option) {
			if (!option || !option.value || this.languages.includes(option.value)) {
				return
			}
			this.$emit('update:languages', {
				sourceLanguage: this.sourceLanguage,
				languages: [...this.languages, option.value],
			})
		},

		/**
		 * Emit the languages with one removed, after the maker confirmed it.
		 * The parent drops that language's translations with it.
		 *
		 * @param {string} code The language to remove.
		 * @return {void}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
		 */
		confirmRemoveLanguage(code) {
			this.pendingRemoval = ''
			this.$emit('update:languages', {
				sourceLanguage: this.sourceLanguage,
				languages: this.languages.filter((language) => language !== code),
			})
		},

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
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
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
		 * @spec openspec/changes/archive/2026-11-20-data-external-database-sources/specs/external-database-sources/spec.md#requirement-an-app-binds-a-connected-database-req-bqdb-001
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
		 *
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
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
		 *
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
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
		 *
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
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
		 *
		 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
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
		/**
		 * Take a picked theme colour ('' clears it) and save the section.
		 *
		 * @param {string} value A `#rrggbb` colour, or ''.
		 * @return {void}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
		 */
		onThemeColor(value) {
			this.installThemeColor = value
			this.emitInstallable()
		},

		/**
		 * Emit the Installable section as the Application patch it stands for:
		 * a trimmed short name of at most 12 characters, and a theme colour
		 * only when it is a six-digit hex colour.
		 *
		 * @return {void}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
		 */
		emitInstallable() {
			const shortName = String(this.installShortName || '').trim().slice(0, 12)
			const color = String(this.installThemeColor || '').trim()
			this.$emit('update:installable', {
				shortName,
				themeColor: /^#[0-9a-fA-F]{6}$/.test(color) ? color.toLowerCase() : '',
			})
		},

		/**
		 * The offline block's take-along entries as editable rows, the filter as JSON text.
		 *
		 * @param {object|null} offline The offline block.
		 * @return {Array<object>} Rows.
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
		 */
		toTakeAlongRows(offline) {
			const entries = (offline && Array.isArray(offline.takeAlong)) ? offline.takeAlong : []
			return entries.map((entry) => ({
				schema: String(entry.schema || ''),
				filter: entry.filter && Object.keys(entry.filter).length ? JSON.stringify(entry.filter) : '',
				filterInvalid: false,
				limit: entry.limit ?? OFFLINE_LIMIT_DEFAULT,
				ttlHours: entry.ttlHours ?? OFFLINE_TTL_DEFAULT,
			}))
		},

		/**
		 * Add an empty take-along row with the default limit and lifetime.
		 *
		 * @return {void}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
		 */
		addTakeAlong() {
			this.takeAlongRows.push({ schema: '', filter: '', filterInvalid: false, limit: OFFLINE_LIMIT_DEFAULT, ttlHours: OFFLINE_TTL_DEFAULT })
		},

		/**
		 * Remove one take-along row and save the section.
		 *
		 * @param {number} index The row.
		 * @return {void}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
		 */
		removeTakeAlong(index) {
			this.takeAlongRows.splice(index, 1)
			this.emitOffline()
		},

		/**
		 * Emit the Offline section as the manifest's offline block, capped.
		 *
		 * A filter that is not a JSON object marks its row and stops the save,
		 * so a typo never takes along every record unasked. The form page ids
		 * are kept from the saved block: they are switched in the page designer.
		 *
		 * @return {void}
		 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T04
		 */
		emitOffline() {
			let invalid = false
			const takeAlong = this.takeAlongRows.map((row) => {
				let filter = {}
				const text = String(row.filter || '').trim()
				row.filterInvalid = false
				if (text !== '') {
					try {
						filter = JSON.parse(text)
					} catch {
						filter = null
					}
					if (!filter || typeof filter !== 'object' || Array.isArray(filter)) {
						row.filterInvalid = true
						invalid = true
					}
				}
				return { schema: row.schema, filter, limit: row.limit, ttlHours: row.ttlHours }
			})
			if (invalid) {
				return
			}
			const forms = (this.offline && Array.isArray(this.offline.forms)) ? this.offline.forms : []
			this.$emit('update:offline', normaliseOffline({ takeAlong, forms }))
		},

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

.app-settings__label {
	font-weight: 600;
}

.app-settings__take-along {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 8px;
	align-items: end;
	margin-bottom: 8px;
}

.app-settings__color {
	width: 64px;
	min-height: 44px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
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

.app-settings__languages {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.app-settings__language {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
}

.app-settings__confirm {
	color: var(--color-error-text, var(--color-error));
}

.app-settings__data-register-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
</style>
