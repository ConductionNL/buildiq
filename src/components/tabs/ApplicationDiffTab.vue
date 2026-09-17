<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationDiffTab: the "Diff" sidebar tab on the VirtualAppDetail page.
  - Compares two versions of the app. By default that is the version selected
  - in the header pills (or the version that promotes into production) against
  - production, which is the comparison a user makes before promoting. Both
  - sides can be changed.
  -
  - It used to diff `draft` against `obApp.currentVersion`, a field no app
  - record has, so it always showed "Nothing to diff" and never a diff.
  -->
<template>
	<div class="ob-diff-tab">
		<p v-if="obAppError" class="ob-diff-tab__error">
			{{ obAppError }}
		</p>
		<p v-else-if="loading" class="ob-diff-tab__note">
			{{ t('buildiq', 'Loading versions…') }}
		</p>
		<p v-else-if="versions.length < 2" class="ob-diff-tab__note">
			{{
				t(
					'buildiq',
					'This app has one version, so there is nothing to compare yet.',
				)
			}}
		</p>
		<template v-else>
			<div class="ob-diff-tab__pickers">
				<NcSelect
					v-model="fromOption"
					:options="options"
					:clearable="false"
					:inputLabel="t('buildiq', 'Compare')" />
				<NcSelect
					v-model="toOption"
					:options="options"
					:clearable="false"
					:inputLabel="t('buildiq', 'With')" />
			</div>
			<ManifestDiff
				v-if="fromOption && toOption"
				:slug="obApp.slug"
				:from="fromOption.id"
				:to="toOption.id"
				:fromLabelText="fromOption.label"
				:toLabelText="toOption.label" />
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ManifestDiff from '../ManifestDiff.vue'
import applicationContext from '../../mixins/applicationContext.js'

/**
 * The own UUID of a version row.
 *
 * @param {object} row The version row.
 * @return {string}
 */
function rowUuid(row) {
	const self = (row && row['@self']) || {}
	return (row && (row.id || row.uuid)) || self.id || self.uuid || ''
}

export default {
	name: 'ApplicationDiffTab',
	components: { ManifestDiff, NcSelect },
	mixins: [applicationContext],

	data() {
		return {
			versions: [],
			loading: false,
			fromOption: null,
			toOption: null,
		}
	},

	computed: {
		/**
		 * The production version UUID of the app.
		 *
		 * @return {string}
		 * @spec openspec/specs/openbuild-version-snapshots/spec.md
		 */
		productionUuid() {
			const pv = this.obApp && this.obApp.productionVersion
			if (!pv) {
				return ''
			}
			return typeof pv === 'string' ? pv : pv.uuid || pv.id || ''
		},

		/**
		 * The versions as picker options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/openbuild-version-snapshots/spec.md
		 */
		options() {
			return this.versions.map((v) => {
				const name = v.name || v.slug || rowUuid(v)
				const semver = v.semver ? ` (${v.semver})` : ''
				return { id: rowUuid(v), slug: v.slug, label: `${name}${semver}` }
			})
		},
	},

	watch: {
		'obApp.slug': {
			immediate: true,
			/**
			 * Load the versions once the app is known.
			 *
			 * @param {string} slug The app slug.
			 * @return {void}
			 * @spec openspec/specs/openbuild-version-snapshots/spec.md
			 */
			handler(slug) {
				if (slug) {
					this.loadVersions()
				}
			},
		},

		'$route.query._version': function () {
			this.pickDefaults()
		},
	},

	methods: {
		/**
		 * Load the app's versions.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/openbuild-version-snapshots/spec.md
		 */
		async loadVersions() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/buildiq/api/applications/{slug}/versions', {
						slug: this.obApp.slug,
					}),
				)
				const rows = Array.isArray(data) ? data : (data && data.results) || []
				this.versions = rows.filter((v) => (v.status || 'draft') !== 'archived')
			} catch {
				this.versions = []
			} finally {
				this.loading = false
			}
			this.pickDefaults()
		},

		/**
		 * Default pair: the selected (or promoting) version against production.
		 *
		 * @return {void}
		 * @spec openspec/specs/openbuild-version-snapshots/spec.md
		 */
		pickDefaults() {
			const options = this.options
			if (options.length < 2) {
				return
			}
			const production =
				options.find((o) => o.id === this.productionUuid) || options[options.length - 1]
			const selectedSlug =
				(this.$route && this.$route.query && this.$route.query._version) || ''
			const upstream = this.versions.find(
				(v) => v.promotesTo && v.promotesTo === production.id,
			)
			const from =
				options.find((o) => o.slug === selectedSlug && o.id !== production.id)
				|| (upstream && options.find((o) => o.id === rowUuid(upstream)))
				|| options.find((o) => o.id !== production.id)
			this.fromOption = from
			this.toOption = production
		},
	},
}
</script>

<style scoped>
.ob-diff-tab {
	padding: 8px 0;
}

.ob-diff-tab__pickers {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-bottom: 12px;
}

.ob-diff-tab__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.ob-diff-tab__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}
</style>
