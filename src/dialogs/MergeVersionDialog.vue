<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - MergeVersionDialog — merge one version into a draft version
  - (lifecycle-git-branches, REQ-BQGB-003 / REQ-BQGB-004).
  -
  - The maker picks the target and previews the merge. Each conflict shows
  - both sides with ManifestDiff and takes "Keep target" or "Take branch";
  - a breaking schema change must be confirmed. "Merge" stays off until every
  - conflict has a side. When a colleague saved the target after the preview,
  - the server answers `target_changed` and the dialog offers a new preview.
  -
  - @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-maker-merges-a-branch-and-picks-a-side-per-conflict-req-bqgb-003
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('buildiq', 'Merge “{name}” into another version', { name: sourceName })"
		size="large"
		@closing="close">
		<div class="merge-version">
			<NcSelect
				v-model="target"
				:options="targetOptions"
				:inputLabel="t('buildiq', 'Merge into')"
				:clearable="false"
				data-test="merge-target"
				@update:modelValue="reset" />

			<p v-if="error" class="merge-version__error" role="alert">
				{{ error }}
			</p>

			<template v-if="preview">
				<p v-if="!preview.conflicts.length" class="merge-version__clean" data-test="merge-clean">
					{{ t('buildiq', 'No conflicts. Everything changed on one side is taken.') }}
				</p>
				<ul v-else class="merge-version__conflicts">
					<li
						v-for="conflict in preview.conflicts"
						:key="conflict.unit"
						class="merge-version__conflict"
						data-test="merge-conflict">
						<h4>{{ conflictLabel(conflict) }}</h4>
						<ManifestDiff
							:fromManifest="side(conflict, 'target')"
							:toManifest="side(conflict, 'source')"
							:fromLabelText="targetName"
							:toLabelText="sourceName" />
						<fieldset class="merge-version__choice">
							<legend>{{ t('buildiq', 'Which side to keep') }}</legend>
							<NcCheckboxRadioSwitch
								:modelValue="choices[conflict.unit] || ''"
								value="target"
								:name="`merge-${conflict.unit}`"
								type="radio"
								@update:modelValue="choose(conflict.unit, 'target')">
								{{ t('buildiq', 'Keep target') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch
								:modelValue="choices[conflict.unit] || ''"
								value="source"
								:name="`merge-${conflict.unit}`"
								type="radio"
								@update:modelValue="choose(conflict.unit, 'source')">
								{{ t('buildiq', 'Take branch') }}
							</NcCheckboxRadioSwitch>
						</fieldset>
					</li>
				</ul>

				<div v-if="preview.breaking.length" class="merge-version__breaking">
					<p>{{ t('buildiq', 'This merge changes schemas in a way that can break records already stored:') }}</p>
					<ul>
						<li v-for="change in preview.breaking" :key="change">
							{{ change }}
						</li>
					</ul>
					<NcCheckboxRadioSwitch v-model="acknowledged" data-test="merge-acknowledge">
						{{ t('buildiq', 'I understand, merge anyway') }}
					</NcCheckboxRadioSwitch>
				</div>
			</template>
		</div>
		<template #actions>
			<NcButton @click="close">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton :disabled="!target || busy" data-test="merge-preview" @click="loadPreview">
				{{ preview ? t('buildiq', 'Preview again') : t('buildiq', 'Preview') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canMerge"
				data-test="merge-apply"
				@click="apply">
				{{ t('buildiq', 'Merge') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ManifestDiff from '../components/ManifestDiff.vue'

/**
 * The message a refused merge shows: the server's detail, else a generic line.
 *
 * @param {Error & {response?: {data?: {detail?: string}}}} e The axios error.
 * @return {string}
 */
function refusalMessage(e) {
	const detail = e && e.response && e.response.data && e.response.data.detail
	return detail || t('buildiq', 'The merge could not be completed.')
}

export default {
	name: 'MergeVersionDialog',
	components: { ManifestDiff, NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect },
	props: {
		/** Whether the dialog is shown. */
		open: { type: Boolean, default: false },
		/** The Application slug. */
		appSlug: { type: String, default: '' },
		/** The version merged in. */
		source: { type: Object, default: null },
		/** The versions it may merge into: the other drafts. */
		targets: { type: Array, default: () => [] },
	},

	emits: ['update:open', 'merged'],
	data() {
		return { target: null, preview: null, choices: {}, acknowledged: false, busy: false, error: '' }
	},

	computed: {
		/** @return {string} */
		sourceName() {
			return (this.source && (this.source.name || this.source.slug)) || ''
		},

		/** @return {string} */
		targetName() {
			return (this.target && this.target.label) || ''
		},

		/** @return {Array<{id: string, label: string}>} */
		targetOptions() {
			return this.targets.map((row) => ({ id: row.slug, label: row.name || row.slug }))
		},

		/**
		 * Merge is on once a preview exists, every conflict has a side and any
		 * breaking change is confirmed.
		 *
		 * @return {boolean}
		 */
		canMerge() {
			if (!this.preview || this.busy) {
				return false
			}
			const open = this.preview.conflicts.some((c) => !this.choices[c.unit])
			return !open && (this.preview.breaking.length === 0 || this.acknowledged)
		},
	},

	methods: {
		/** @return {void} */
		reset() {
			this.preview = null
			this.choices = {}
			this.acknowledged = false
			this.error = ''
		},

		/** @return {void} */
		close() {
			this.reset()
			this.$emit('update:open', false)
		},

		/**
		 * Take a side for one conflict.
		 *
		 * @param {string} unit The conflict unit.
		 * @param {'source'|'target'} side The side.
		 * @return {void}
		 */
		choose(unit, side) {
			this.choices = { ...this.choices, [unit]: side }
		},

		/**
		 * The heading of a conflict.
		 *
		 * @param {{kind: string, key: string}} conflict The conflict.
		 * @return {string}
		 */
		conflictLabel(conflict) {
			const labels = {
				page: t('buildiq', 'Page {name}', { name: conflict.key }),
				menu: t('buildiq', 'Menu item {name}', { name: conflict.key }),
				schema: t('buildiq', 'Schema {name}', { name: conflict.key }),
				property: t('buildiq', 'Field {name}', { name: conflict.key }),
			}
			return labels[conflict.kind] || t('buildiq', 'Setting {name}', { name: conflict.key })
		},

		/**
		 * One side of a conflict as an object ManifestDiff can draw.
		 *
		 * @param {object} conflict The conflict.
		 * @param {'source'|'target'} side The side.
		 * @return {object}
		 */
		side(conflict, side) {
			const value = conflict[side]
			return value === null || value === undefined ? {} : { [conflict.key]: value }
		},

		/**
		 * The merge endpoint of the chosen target.
		 *
		 * @return {string}
		 */
		url() {
			return generateUrl('/apps/buildiq/api/applications/{app}/versions/{source}/merge/{target}', {
				app: this.appSlug,
				source: this.source.slug,
				target: this.target.id,
			})
		},

		/**
		 * Ask the server for the merge without writing anything.
		 *
		 * @return {Promise<void>}
		 */
		async loadPreview() {
			this.reset()
			this.busy = true
			try {
				const { data } = await axios.post(this.url(), { preview: true })
				this.preview = { conflicts: data.conflicts || [], breaking: data.breaking || [], targetHash: data.targetHash || '' }
			} catch (e) {
				this.error = refusalMessage(e)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Apply the merge with the chosen sides and the previewed hash.
		 *
		 * @return {Promise<void>}
		 */
		async apply() {
			this.busy = true
			try {
				const { data } = await axios.post(this.url(), {
					choices: this.choices,
					targetHash: this.preview.targetHash,
					acknowledgeBreaking: this.acknowledged,
				})
				this.$emit('merged', data)
				this.close()
			} catch (e) {
				const code = e && e.response && e.response.data && e.response.data.error
				this.error = code === 'target_changed'
					? t('buildiq', 'Someone saved “{name}” after your preview. Preview the merge again.', { name: this.targetName })
					: refusalMessage(e)
				if (code === 'target_changed') {
					this.preview = null
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.merge-version {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.merge-version__conflicts {
	display: flex;
	flex-direction: column;
	gap: 16px;
	list-style: none;
	padding: 0;
}

.merge-version__conflict {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.merge-version__choice {
	display: flex;
	gap: 16px;
	border: 0;
	padding: 8px 0 0;
}

.merge-version__error {
	color: var(--color-error-text);
}

.merge-version__breaking {
	border-inline-start: 3px solid var(--color-warning);
	padding-inline-start: 12px;
}
</style>
