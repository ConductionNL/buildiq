<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<section class="export-jobs">
		<header class="export-jobs__header">
			<h2>{{ t('openbuild', 'Export application') }}</h2>
			<NcButton v-if="applicationSlug" type="primary" @click="openDialog">
				{{ t('openbuild', 'Start export') }}
			</NcButton>
		</header>

		<table v-if="jobs.length" class="export-jobs__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('openbuild', 'Version') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Target') }}
					</th>
					<th scope="col">
						{{ t('openbuild', 'Status') }}
					</th>
					<!-- Actions column: no visible caption, but it is still a column
					     header, so it keeps `scope="col"` and an sr-only name. -->
					<th scope="col">
						<span class="hidden-visually">{{ t('openbuild', 'Actions') }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<!--
				  Key on the OR object id. `job.uuid` is NOT a property of the
				  `export-job` schema, so it is undefined on EVERY row — which
				  makes every key identical and lets Vue reuse the wrong <tr>
				  as statuses change under polling.
				-->
				<tr v-for="(job, i) in jobs" :key="(job['@self'] && job['@self'].id) || i">
					<td>{{ job.applicationVersion }}</td>
					<td>{{ job.target }}</td>
					<td>{{ statusLabel(job.status) }}</td>
					<td>
						<NcButton
							v-if="job.status === 'succeeded' && job.target === 'zip' && job.downloadUrl"
							:href="job.downloadUrl">
							{{ t('openbuild', 'Download ZIP') }}
						</NcButton>
						<NcButton
							v-else-if="job.status === 'succeeded' && job.target === 'github' && job.githubPullRequestUrl"
							:href="job.githubPullRequestUrl">
							{{ t('openbuild', 'View pull request') }}
						</NcButton>
						<span v-else-if="job.status === 'failed'" class="export-jobs__error">
							{{ job.errorMessage }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else>
			{{ t('openbuild', 'No exports yet.') }}
		</p>

		<ExportDialog
			v-if="showDialog"
			:application-slug="applicationSlug"
			@close="showDialog = false"
			@queued="onQueued" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ExportDialog from '../dialogs/ExportDialog.vue'

export default {
	name: 'ExportJobsList',
	components: {
		NcButton,
		ExportDialog,
	},
	props: {
		/** Slug — the key the export SUBMIT endpoint takes. */
		applicationSlug: {
			type: String,
			required: true,
		},
		/** Object id — the key stored jobs are actually filterable by. */
		applicationUuid: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			jobs: [],
			showDialog: false,
			poller: null,
		}
	},
	/**
	 * Observed behaviour of `mounted` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
	 */
	mounted() {
		this.fetchJobs()
		this.poller = setInterval(this.fetchJobs, 2000)
	},
	/**
	 * Observed behaviour of `beforeDestroy` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
	 */
	beforeUnmount() {
		if (this.poller) {
			clearInterval(this.poller)
		}
	},
	methods: {
		/**
		 * Observed behaviour of `openDialog` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
		 */
		openDialog() {
			this.showDialog = true
		},
		/**
		 * Observed behaviour of `onQueued` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
		 */
		onQueued() {
			this.fetchJobs()
		},
		/**
		 * Observed behaviour of `fetchJobs` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
		 */
		async fetchJobs() {
			// Polls OR REST per ADR-022; the controller deliberately does not
			// expose CRUD on ExportJob. The schema's JSON key in
			// openbuild_register.json is `exportJob`, but its declared `slug`
			// (which OR's REST API addresses schemas by) is kebab-cased to
			// `export-job` — fixes #104's schema-slug 404.
			try {
				// Schema slug is `export-job` (OpenRegister derives it from the
				// "Export Job" title); the camelCase `exportJob` 404s.
				//
				// Filter on applicationUuid as a PLAIN query param. The previous
				// `?filter[applicationSlug]=` was wrong twice over, and the Exports
				// tab was therefore empty for every application, always:
				//
				//   1. `export-job` declares 18 properties and `applicationSlug` is
				//      NOT one of them — it is `applicationUuid`. Nothing ever wrote
				//      a slug onto these objects, so no row could ever carry one.
				//   2. The `filter[...]` bracket syntax is not what this endpoint
				//      reads. Measured against the same 5 stored jobs:
				//        ?applicationUuid=<uuid>        -> 1   (correct)
				//        ?filter[applicationUuid]=<u>   -> 0
				//        ?_filter[applicationUuid]=<u>  -> 5   (ignored entirely)
				//
				// `applicationSlug` is still the right key for the SUBMIT endpoint
				// (/api/applications/{slug}/exports), so both props are kept.
				const url = generateUrl('/apps/openregister/api/objects/openbuild/export-job') + '?applicationUuid=' + encodeURIComponent(this.applicationUuid)
				const response = await fetch(url)
				if (!response.ok) {
					return
				}
				const data = await response.json()
				this.jobs = Array.isArray(data?.results) ? data.results : []
			} catch (e) {
				// Silent fail; polling will retry.
			}
		},
		/**
		 * Observed behaviour of `statusLabel` (retrofit annotation).
		 *
		 * @param {'queued'|'running'|'succeeded'|'failed'|string} status - The export
		 *   job's raw `status` from the API.
		 * @return {string} The translated label, or the raw status verbatim for a
		 *   state this build does not know about (so a new backend state degrades to
		 *   untranslated rather than to a blank cell).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-exporter-ui/tasks.md#task-2
		 */
		statusLabel(status) {
			const map = {
				queued: this.t('openbuild', 'Queued'),
				running: this.t('openbuild', 'Running'),
				succeeded: this.t('openbuild', 'Succeeded'),
				failed: this.t('openbuild', 'Failed'),
			}
			return map[status] || status
		},
	},
}
</script>

<style scoped>
.export-jobs {
	padding: var(--default-grid-baseline, 8px);
}

.export-jobs__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: var(--default-grid-baseline, 8px);
}

.export-jobs__table {
	width: 100%;
	border-collapse: collapse;
}

.export-jobs__table th,
.export-jobs__table td {
	padding: var(--default-grid-baseline, 8px);
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.export-jobs__error {
	color: var(--color-error);
}
</style>
