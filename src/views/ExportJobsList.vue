<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<section class="export-jobs">
		<header class="export-jobs__header">
			<h2>{{ t('openbuilt', 'Export application') }}</h2>
			<NcButton v-if="applicationSlug" type="primary" @click="openDialog">
				{{ t('openbuilt', 'Start export') }}
			</NcButton>
		</header>

		<table v-if="jobs.length" class="export-jobs__table">
			<thead>
				<tr>
					<th>{{ t('openbuilt', 'Version') }}</th>
					<th>{{ t('openbuilt', 'Target') }}</th>
					<th>{{ t('openbuilt', 'Status') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="job in jobs" :key="job.uuid">
					<td>{{ job.applicationVersion }}</td>
					<td>{{ job.target }}</td>
					<td>{{ statusLabel(job.status) }}</td>
					<td>
						<NcButton
							v-if="job.status === 'succeeded' && job.target === 'zip' && job.downloadUrl"
							:href="job.downloadUrl">
							{{ t('openbuilt', 'Download ZIP') }}
						</NcButton>
						<NcButton
							v-else-if="job.status === 'succeeded' && job.target === 'github' && job.githubPullRequestUrl"
							:href="job.githubPullRequestUrl">
							{{ t('openbuilt', 'View pull request') }}
						</NcButton>
						<span v-else-if="job.status === 'failed'" class="export-jobs__error">
							{{ job.errorMessage }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else>{{ t('openbuilt', 'No exports yet.') }}</p>

		<ExportDialog
			v-if="showDialog"
			:application-slug="applicationSlug"
			@close="showDialog = false"
			@queued="onQueued" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ExportDialog from '../dialogs/ExportDialog.vue'

export default {
	name: 'ExportJobsList',
	components: {
		NcButton,
		ExportDialog,
	},
	props: {
		applicationSlug: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			jobs: [],
			showDialog: false,
			poller: null,
		}
	},
	mounted() {
		this.fetchJobs()
		this.poller = setInterval(this.fetchJobs, 2000)
	},
	beforeDestroy() {
		if (this.poller) {
			clearInterval(this.poller)
		}
	},
	methods: {
		openDialog() {
			this.showDialog = true
		},
		onQueued() {
			this.fetchJobs()
		},
		async fetchJobs() {
			// Placeholder: real impl polls OR REST per ADR-022; the controller
			// deliberately does not expose CRUD on ExportJob.
			try {
				const response = await fetch('/index.php/apps/openregister/api/objects/openbuilt/exportJob?filter[applicationSlug]=' + encodeURIComponent(this.applicationSlug))
				if (!response.ok) {
					return
				}
				const data = await response.json()
				this.jobs = Array.isArray(data?.results) ? data.results : []
			} catch (e) {
				// Silent fail; polling will retry.
			}
		},
		statusLabel(status) {
			const map = {
				queued: this.t('openbuilt', 'Queued'),
				running: this.t('openbuilt', 'Running'),
				succeeded: this.t('openbuilt', 'Succeeded'),
				failed: this.t('openbuilt', 'Failed'),
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
