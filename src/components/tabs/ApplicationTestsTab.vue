<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationTestsTab: the "Tests" sidebar tab on the VirtualAppDetail page
  - (lifecycle-release-test-gate D4). For the chosen version it shows the
  - latest test run with its checks by kind (structure, rule sets, record
  - tests), and "Run tests" runs the suite now. Below it the app's record tests
  - (added, changed and removed by owners and editors) and the rule sets the
  - app owns, each opening its test sandbox.
  -->
<template>
	<div class="ob-tests-tab">
		<p v-if="obAppError || loadError" class="ob-tests-tab__error" role="alert">
			{{ obAppError || loadError }}
		</p>

		<section class="ob-tests-tab__section">
			<div class="ob-tests-tab__run">
				<NcSelect
					v-model="versionOption"
					:options="versionOptions"
					:clearable="false"
					:inputLabel="t('buildiq', 'Version')" />
				<NcButton
					v-if="canEdit"
					variant="primary"
					:disabled="!versionOption || running"
					@click="runTests">
					{{ running ? t('buildiq', 'Running…') : t('buildiq', 'Run tests') }}
				</NcButton>
			</div>

			<p v-if="!latestRun" class="ob-tests-tab__note">
				{{ t('buildiq', 'This version has not been tested yet.') }}
			</p>
			<template v-else>
				<p class="ob-tests-tab__summary">
					{{ t('buildiq', 'Last run {when} ({trigger}): {passed} passed, {failed} failed, {skipped} skipped', {
						when: formatTime(latestRun.ranAt),
						trigger: triggerLabel(latestRun.trigger),
						passed: latestRun.passed || 0,
						failed: latestRun.failed || 0,
						skipped: latestRun.skipped || 0,
					}) }}
				</p>
				<p v-if="latestRun.override" class="ob-tests-tab__note">
					{{ t('buildiq', 'Released anyway by {user}: {reason}', latestRun.override) }}
				</p>
				<div v-for="group in checkGroups" :key="group.kind" class="ob-tests-tab__group">
					<h4>{{ group.label }}</h4>
					<ul>
						<li
							v-for="(check, index) in group.checks"
							:key="index"
							:class="'ob-tests-tab__check--' + check.outcome">
							<span class="ob-tests-tab__outcome">{{ outcomeLabel(check.outcome) }}</span>
							{{ check.name }}
							<span v-if="check.message" class="ob-tests-tab__message">— {{ check.message }}</span>
						</li>
					</ul>
				</div>
			</template>
		</section>

		<section class="ob-tests-tab__section">
			<h3>{{ t('buildiq', 'Record tests') }}</h3>
			<p class="ob-tests-tab__note">
				{{ t('buildiq', 'A record test is skipped until OpenRegister can check a record without saving it.') }}
			</p>
			<p v-if="recordTests.length === 0" class="ob-tests-tab__note">
				{{ t('buildiq', 'No record tests yet.') }}
			</p>
			<ul v-else>
				<li v-for="test in recordTests" :key="idOf(test)" class="ob-tests-tab__row">
					<span>
						<strong>{{ test.name }}</strong>
						({{ test.schema }}, {{ test.expect === 'refuse' ? t('buildiq', 'refused') : t('buildiq', 'accepted') }})
					</span>
					<span v-if="canEdit">
						<NcButton variant="tertiary" @click="editTest(test)">
							{{ t('buildiq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="removeTest(test)">
							{{ t('buildiq', 'Remove') }}
						</NcButton>
					</span>
				</li>
			</ul>
			<NcButton v-if="canEdit" @click="editTest(null)">
				{{ t('buildiq', 'Add record test') }}
			</NcButton>
		</section>

		<section class="ob-tests-tab__section">
			<h3>{{ t('buildiq', 'Rule sets') }}</h3>
			<p v-if="ruleSets.length === 0" class="ob-tests-tab__note">
				{{ t('buildiq', 'This app owns no rule sets.') }}
			</p>
			<ul v-else>
				<li v-for="ruleSet in ruleSets" :key="ruleSet.slug" class="ob-tests-tab__row">
					<span>{{ ruleSet.name || ruleSet.slug }}</span>
					<NcButton variant="tertiary" @click="sandboxRuleSet = ruleSet">
						{{ t('buildiq', 'Open tests') }}
					</NcButton>
				</li>
			</ul>
		</section>

		<RecordTestDialog
			v-model:open="dialogOpen"
			:test="editing"
			:schemas="schemaSlugs"
			:busy="saving"
			@save="saveTest" />
		<RuleSetTestSandboxModal
			v-if="sandboxRuleSet"
			:ruleSet="sandboxRuleSet"
			@close="sandboxRuleSet = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import RecordTestDialog from '../../dialogs/RecordTestDialog.vue'
import RuleSetTestSandboxModal from '../../modals/RuleSetTestSandboxModal.vue'
import applicationContext from '../../mixins/applicationContext.js'

/**
 * The own uuid of a row.
 *
 * @param {object} row The row.
 * @return {string}
 */
export function idOf(row) {
	if (!row) {
		return ''
	}
	const self = row['@self'] || {}
	return [row.id, row.uuid, self.id, self.uuid].find(Boolean) || ''
}

/**
 * The checks of a report grouped by kind, in suite order, empty kinds left out.
 *
 * @param {Array<object>} report The run's checks.
 * @return {Array<{kind: string, label: string, checks: Array<object>}>}
 */
export function groupChecks(report) {
	const kinds = [
		{ kind: 'structure', label: t('buildiq', 'Structure') },
		{ kind: 'rule-set', label: t('buildiq', 'Rule sets') },
		{ kind: 'record', label: t('buildiq', 'Record tests') },
	]
	return kinds
		.map((k) => ({ ...k, checks: (report || []).filter((c) => c.kind === k.kind) }))
		.filter((k) => k.checks.length > 0)
}

export default {
	name: 'ApplicationTestsTab',
	components: { NcButton, NcSelect, RecordTestDialog, RuleSetTestSandboxModal },
	mixins: [applicationContext],

	data() {
		return {
			versions: [],
			versionOption: null,
			recordTests: [],
			ruleSets: [],
			latestRuns: {},
			loadError: '',
			running: false,
			saving: false,
			dialogOpen: false,
			editing: null,
			sandboxRuleSet: null,
		}
	},

	computed: {
		/**
		 * Whether the caller may run the suite and write record tests (owner / editor).
		 *
		 * @return {boolean}
		 */
		canEdit() {
			return this.obAppRole === 'owner' || this.obAppRole === 'editor'
		},

		/**
		 * The app's versions as picker options, archived ones left out.
		 *
		 * @return {Array<{id: string, slug: string, label: string}>}
		 */
		versionOptions() {
			return this.versions
				.filter((v) => v.status !== 'archived')
				.map((v) => ({ id: idOf(v), slug: v.slug, label: v.name || v.slug || idOf(v) }))
		},

		/**
		 * The latest run of the chosen version.
		 *
		 * @return {object|null}
		 */
		latestRun() {
			return (this.versionOption && this.latestRuns[this.versionOption.id]) || null
		},

		/**
		 * The latest run's checks by kind.
		 *
		 * @return {Array<object>}
		 */
		checkGroups() {
			return groupChecks(this.latestRun && this.latestRun.report)
		},

		/**
		 * The schemas the chosen version's pages use, for the record test picker.
		 *
		 * @return {Array<string>}
		 */
		schemaSlugs() {
			const version = this.versions.find((v) => this.versionOption && idOf(v) === this.versionOption.id)
			const pages = (version && version.manifest && version.manifest.pages) || []
			return [...new Set(pages.map((p) => (p.config || {}).schema).filter(Boolean))]
		},
	},

	watch: {
		'obApp.slug': {
			immediate: true,
			/**
			 * Load the tab once the app is known.
			 *
			 * @param {string} slug The app slug.
			 * @return {void}
			 */
			handler(slug) {
				if (slug) {
					this.load()
				}
			},
		},
	},

	methods: {
		idOf,

		/**
		 * The URL of one of the app's routes.
		 *
		 * @param {string} path The path after the app.
		 * @param {object} params Its parameters.
		 * @return {string}
		 */
		url(path, params = {}) {
			return generateUrl('/apps/buildiq/api/applications/{slug}' + path, { slug: this.obApp.slug, ...params })
		},

		/**
		 * Load the versions and the tab's content.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
		 */
		async load() {
			this.loadError = ''
			try {
				const [versions, overview] = await Promise.all([
					axios.get(this.url('/versions')),
					axios.get(this.url('/tests')),
				])
				this.versions = Array.isArray(versions.data) ? versions.data : (versions.data.results || [])
				this.applyOverview(overview.data || {})
				if (!this.versionOption && this.versionOptions.length > 0) {
					this.versionOption = this.versionOptions[0]
				}
			} catch {
				this.loadError = t('buildiq', 'The tests could not be loaded.')
			}
		},

		/**
		 * Take over the record tests, rule sets and latest runs.
		 *
		 * @param {object} overview The tab's content.
		 * @return {void}
		 */
		applyOverview(overview) {
			this.recordTests = overview.recordTests || []
			this.ruleSets = overview.ruleSets || []
			this.latestRuns = overview.latestRuns || {}
		},

		/**
		 * Run the chosen version's suite now and show the run.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-every-run-is-kept-req-bqrt-005
		 */
		async runTests() {
			if (!this.versionOption) {
				return
			}
			this.running = true
			try {
				const { data } = await axios.post(this.url('/versions/{versionSlug}/tests', { versionSlug: this.versionOption.slug }), {})
				this.latestRuns = { ...this.latestRuns, [this.versionOption.id]: data }
			} catch {
				showError(t('buildiq', 'The tests could not be run.'))
			} finally {
				this.running = false
			}
		},

		/**
		 * Open the record test dialog for a test, or for a new one.
		 *
		 * @param {object|null} test The test.
		 * @return {void}
		 */
		editTest(test) {
			this.editing = test
			this.dialogOpen = true
		},

		/**
		 * Store the dialog's test and reload the list.
		 *
		 * @param {object} test The test.
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
		 */
		async saveTest(test) {
			this.saving = true
			try {
				const id = idOf(this.editing)
				if (id) {
					await axios.put(this.url('/record-tests/{id}', { id }), test)
				} else {
					await axios.post(this.url('/record-tests'), test)
				}
				this.dialogOpen = false
				await this.load()
			} catch (e) {
				const detail = e && e.response && e.response.data && e.response.data.detail
				showError(detail || t('buildiq', 'The record test could not be saved.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Remove a record test and reload the list.
		 *
		 * @param {object} test The test.
		 * @return {Promise<void>}
		 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md#requirement-a-maker-writes-record-tests-req-bqrt-003
		 */
		async removeTest(test) {
			try {
				await axios.delete(this.url('/record-tests/{id}', { id: idOf(test) }))
				await this.load()
			} catch {
				showError(t('buildiq', 'The record test could not be removed.'))
			}
		},

		/**
		 * The label of an outcome.
		 *
		 * @param {string} outcome passed, failed or skipped.
		 * @return {string}
		 */
		outcomeLabel(outcome) {
			return {
				passed: t('buildiq', 'Passed'),
				failed: t('buildiq', 'Failed'),
				skipped: t('buildiq', 'Skipped'),
			}[outcome] || outcome
		},

		/**
		 * The label of what started a run.
		 *
		 * @param {string} trigger manual, release or promotion.
		 * @return {string}
		 */
		triggerLabel(trigger) {
			return {
				manual: t('buildiq', 'run by hand'),
				release: t('buildiq', 'release'),
				promotion: t('buildiq', 'promotion'),
			}[trigger] || trigger
		},

		/**
		 * A run's time for display.
		 *
		 * @param {string} iso The ISO time.
		 * @return {string}
		 */
		formatTime(iso) {
			const date = new Date(iso)
			return Number.isNaN(date.getTime()) ? (iso || '') : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.ob-tests-tab {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.ob-tests-tab__run {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.ob-tests-tab__row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.ob-tests-tab__note,
.ob-tests-tab__message {
	color: var(--color-text-maxcontrast);
}

.ob-tests-tab__error {
	color: var(--color-error-text);
}

.ob-tests-tab__outcome {
	font-weight: bold;
}

.ob-tests-tab__check--failed .ob-tests-tab__outcome {
	color: var(--color-error-text);
}

.ob-tests-tab__check--passed .ob-tests-tab__outcome {
	color: var(--color-success-text);
}

.ob-tests-tab__check--skipped .ob-tests-tab__outcome {
	color: var(--color-warning-text);
}
</style>
