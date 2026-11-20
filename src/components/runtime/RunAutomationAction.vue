<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - RunAutomationAction — registrable Detail-page `actionsComponent`
  - (`config.actionsComponent: "RunAutomationAction"`, ADR-036 kind "actions")
  - that lists the app's enabled manual automations for the record's schema
  - and starts the chosen one on the record (logic-automation-actions-that-run
  - D8, REQ-BQAR-006). Renders nothing when the app has none for this schema,
  - or when the caller may not run them (the listing answers 403 then).
  -->
<template>
	<NcActions
v-if="automations.length > 0"
		class="ob-run-automation-action"
		:menuName="t('buildiq', 'Run automation')"
		:disabled="running !== ''">
		<NcActionButton
v-for="automation in automations"
			:key="automation.uuid"
			:closeAfterClick="true"
			@click="run(automation)">
			{{ automation.name }}
		</NcActionButton>
	</NcActions>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcActionButton, NcActions } from '@nextcloud/vue'
import { objectSchemaKeys } from '../../utils/objectSchemaKeys.js'

export default {
	name: 'RunAutomationAction',
	components: { NcActions, NcActionButton },
	inject: {
		// The app slug, provided by BuilderHost as a function of its route.
		cnAppSlug: { default: null },
		cnObjectContext: { default: null },
	},

	props: {
		object: {
			type: Object,
			default: null,
		},

		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			automations: [],
			running: '',
		}
	},

	computed: {
		/**
		 * The app this record belongs to.
		 *
		 * @return {string}
		 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
		 */
		appSlug() {
			return typeof this.cnAppSlug === 'function' ? (this.cnAppSlug() || '') : (this.cnAppSlug || '')
		},

		/**
		 * The record's schema slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
		 */
		schema() {
			return objectSchemaKeys(this.object, this.cnObjectContext)[0] || ''
		},

		/**
		 * The record's id.
		 *
		 * @return {string}
		 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
		 */
		recordId() {
			return this.objectId || (this.object && this.object['@self'] && this.object['@self'].id) || ''
		},
	},

	watch: {
		schema: {
			immediate: true,
			/**
			 * Reload the list when the record's schema changes.
			 *
			 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		/**
		 * Load the manual automations the caller may run on this schema; none on any refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
		 */
		async load() {
			if (this.appSlug === '' || this.schema === '') {
				this.automations = []
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/buildiq/api/automations/manual'), {
					params: { app: this.appSlug, schema: this.schema },
				})
				this.automations = Array.isArray(data && data.results) ? data.results : []
			} catch {
				this.automations = []
			}
		},

		/**
		 * Start one automation on this record and say whether it started.
		 *
		 * @param {{uuid: string, name: string}} automation The automation.
		 * @return {Promise<void>}
		 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-app-users-run-manual-automations-from-a-record-req-bqar-006
		 */
		async run(automation) {
			this.running = automation.uuid
			try {
				await axios.post(
					generateUrl('/apps/buildiq/api/automations/{uuid}/run', { uuid: automation.uuid }),
					{ object: this.recordId },
				)
				showSuccess(t('buildiq', '"{name}" started', { name: automation.name }))
			} catch {
				showError(t('buildiq', '"{name}" could not be started', { name: automation.name }))
			} finally {
				this.running = ''
			}
		},
	},
}
</script>
