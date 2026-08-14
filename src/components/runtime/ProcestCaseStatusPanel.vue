<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ProcestCaseStatusPanel — detail-page panel/sidebar-tab widget rendering the
  - linked Procest case for the current object (REQ-PWA-004). Registered as the
  - runtime widget `procest-case-status`, referencable from a detail page's
  - `sidebarProps.tabs`.
  -
  - Renders the case identification, current status, status-history timeline and
  - an "Open case in Procest" deep link (built by the shared helper). When the
  - object has no linked case it offers a "Start case" action (reconcile-then-
  - start). A 403 renders a no-access state (NOT an error). The open-tasks block
  - is hidden until Procest's per-case tasks API exists.
  -->
<template>
	<div class="procest-case-status-panel">
		<div v-if="loadingDetail" class="procest-case-status-panel__state">
			{{ t('openbuild', 'Loading case…') }}
		</div>
		<div v-else-if="noAccess" class="procest-case-status-panel__state">
			{{ t('openbuild', 'You do not have access to this case.') }}
		</div>
		<div
			v-else-if="detailError"
			class="procest-case-status-panel__state procest-case-status-panel__state--error">
			<p>{{ t('openbuild', 'Could not load the linked case.') }}</p>
			<NcButton type="secondary" @click="reload">
				{{ t('openbuild', 'Retry') }}
			</NcButton>
		</div>

		<div v-else-if="!hasLinkedCase" class="procest-case-status-panel__unlinked">
			<p>
				{{ t('openbuild', 'No Procest case is linked to this object yet.') }}
			</p>
			<NcButton type="primary" :disabled="starting" @click="startNow">
				{{
					starting
						? t('openbuild', 'Starting…')
						: t('openbuild', 'Start case')
				}}
			</NcButton>
			<p v-if="startError" class="procest-case-status-panel__warn">
				{{
					t(
						'openbuild',
						'Starting the case failed. The object is unchanged — you can try again.',
					)
				}}
			</p>
		</div>

		<div v-else class="procest-case-status-panel__case">
			<h3 class="procest-case-status-panel__title">
				{{ caseIdentification }}
			</h3>
			<p class="procest-case-status-panel__current">
				{{
					t('openbuild', 'Current status: {status}', {
						status: currentStatus,
					})
				}}
			</p>
			<ol
				v-if="statusHistory.length"
				class="procest-case-status-panel__timeline">
				<li v-for="(s, idx) in statusHistory" :key="idx">
					<span class="procest-case-status-panel__status-name">{{
						statusLabel(s)
					}}</span>
					<span class="procest-case-status-panel__status-date">{{
						s.datumStatusGezet || s.created || ''
					}}</span>
				</li>
			</ol>
			<a
				v-if="deepLink"
				class="procest-case-status-panel__link"
				:href="deepLink"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('openbuild', 'Open case in Procest') }}
			</a>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useProcestCase } from '../../composables/useProcestCase.js'
import {
	buildProcestCaseUrl,
	caseUuidFromReference,
} from '../../services/procestLinks.js'

export default {
	name: 'ProcestCaseStatusPanel',
	components: { NcButton },
	props: {
		// The current OR object being viewed.
		object: {
			type: Object,
			default: () => ({}),
		},

		// The workflow attachment for this object's schema.
		attachment: {
			type: Object,
			default: () => ({}),
		},
	},

	/**
	 * Bind the Procest case integration to this attachment.
	 *
	 * @param {object} props - component props.
	 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004
	 */
	setup(props) {
		const procest = useProcestCase({ attachment: props.attachment })
		return { procest }
	},

	computed: {
		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		loadingDetail() {
			return this.procest.loadingDetail.value
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		detailError() {
			return this.procest.detailError.value
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		noAccess() {
			return this.procest.noAccess.value
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003 */
		starting() {
			return this.procest.starting.value
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003 */
		startError() {
			return this.procest.startError.value
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		linkReference() {
			const prop = this.attachment && this.attachment.linkProperty
			return (prop && this.object && this.object[prop]) || ''
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		hasLinkedCase() {
			return !!this.procest.caseDetail.value || !!this.linkReference
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		statusHistory() {
			return this.procest.statusHistory.value || []
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		caseIdentification() {
			const c = this.procest.caseDetail.value || {}
			return (
				c.identificatie || c.identification || t('openbuild', 'Linked case')
			)
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		currentStatus() {
			const c = this.procest.caseDetail.value || {}
			return (
				c.statusName
				|| (c.status && (c.status.statustypeOmschrijving || c.status.naam))
				|| t('openbuild', 'Unknown')
			)
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-005 */
		deepLink() {
			const c = this.procest.caseDetail.value || {}
			const uuid =
				c.uuid
				|| (c['@self'] && c['@self'].id)
				|| caseUuidFromReference(this.linkReference)
			return buildProcestCaseUrl(uuid)
		},
	},

	/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
	mounted() {
		if (this.linkReference) {
			this.procest.loadDetail(this.linkReference)
		}
	},

	methods: {
		/**
		 * @param {object} s - a status-history entry.
		 * @return {string}
		 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004
		 */
		statusLabel(s) {
			return (
				s.statustypeOmschrijving
				|| s.statustype
				|| s.naam
				|| s.status
				|| t('openbuild', 'Status')
			)
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004 */
		reload() {
			this.procest.loadDetail(this.linkReference)
		},

		/** @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-003 */
		async startNow() {
			await this.procest.reconcileOrStart(this.object)
		},
	},
}
</script>

<style scoped>
.procest-case-status-panel__state {
	padding: 12px;
	color: var(--color-text-maxcontrast);
}

.procest-case-status-panel__state--error {
	color: var(--color-error);
}

.procest-case-status-panel__warn {
	color: var(--color-warning-text, var(--color-warning));
	margin-top: 8px;
}

.procest-case-status-panel__timeline {
	list-style: none;
	padding: 0;
	margin: 8px 0;
}

.procest-case-status-panel__timeline li {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
	border-bottom: 1px solid var(--color-border);
}

.procest-case-status-panel__status-date {
	color: var(--color-text-maxcontrast);
}

.procest-case-status-panel__link {
	display: inline-block;
	margin-top: 8px;
	color: var(--color-primary-element);
}
</style>
