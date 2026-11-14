<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ReleaseTestReportDialog — shown when a release stopped on a failing test
  - suite (lifecycle-release-test-gate REQ-BQRT-004). It lists the failed
  - checks and, for an owner, offers "Release anyway" with a required reason,
  - which is kept on the test run.
  -
  - The parent releases; this dialog only emits `override` with the reason.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('buildiq', 'The release was stopped')"
		:noClose="busy"
		@closing="$emit('update:open', false)">
		<div class="release-test-report">
			<p>
				{{ n('buildiq', '{count} check failed, so production is unchanged.', '{count} checks failed, so production is unchanged.', failedChecks.length, { count: failedChecks.length }) }}
			</p>
			<ul class="release-test-report__checks">
				<li v-for="(check, index) in failedChecks" :key="index">
					<strong>{{ check.name }}</strong>
					<span v-if="check.message"> — {{ check.message }}</span>
				</li>
			</ul>
			<template v-if="canOverride">
				<NcTextField
					v-model="reason"
					:label="t('buildiq', 'Reason to release anyway')"
					:helperText="t('buildiq', 'The reason, your name and the failures are kept with the test run.')" />
			</template>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('buildiq', 'Close') }}
			</NcButton>
			<NcButton
				v-if="canOverride"
				variant="error"
				:disabled="busy || !reason.trim()"
				@click="$emit('override', reason.trim())">
				{{ t('buildiq', 'Release anyway') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'ReleaseTestReportDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** The test run the release answered with. */
		run: { type: Object, default: null },
		/** Whether the caller may release anyway (owner). */
		canOverride: { type: Boolean, default: false },
		/** Whether the release is in flight. */
		busy: { type: Boolean, default: false },
	},

	emits: ['update:open', 'override'],

	data() {
		return { reason: '' }
	},

	computed: {
		/**
		 * The failed checks of the run.
		 *
		 * @return {Array<object>}
		 */
		failedChecks() {
			const report = (this.run && this.run.report) || []
			return report.filter((check) => check.outcome === 'failed')
		},
	},

	watch: {
		open(isOpen) {
			if (isOpen) {
				this.reason = ''
			}
		},
	},
}
</script>

<style scoped>
.release-test-report {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.release-test-report__checks {
	padding-left: 20px;
	list-style: disc;
}
</style>
