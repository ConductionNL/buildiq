<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  ApplicationApiTab — the "API" tab of the app detail page: for the selected
  version, the OpenRegister calls and fields of every schema the app's pages
  use, cut from OpenRegister's own documents and shown with nextcloud-vue's
  CnApiReference, which also downloads that same document. Everything is read from this instance; nothing outside is loaded.

  @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
-->
<template>
	<div class="ob-api-tab">
		<p v-if="obAppError" class="ob-api-tab__error">
			{{ obAppError }}
		</p>
		<p v-else-if="loading" class="ob-api-tab__note">
			{{ t('buildiq', 'Loading the API reference…') }}
		</p>
		<p v-else-if="!version" class="ob-api-tab__note">
			{{ t('buildiq', 'This app has no version to document yet.') }}
		</p>
		<template v-else>
			<h3 class="ob-api-tab__heading">
				{{ t('buildiq', 'API') }}: {{ versionLabel }}
			</h3>
			<p v-if="error" class="ob-api-tab__error">
				{{ error }}
			</p>
			<p v-if="built.schemas.length === 0" class="ob-api-tab__note">
				{{ t('buildiq', 'The pages of this version use no OpenRegister schema.') }}
			</p>

			<CnApiReference
				:document="built.document"
				:downloadName="downloadName">
				<template #intro>
					<p>
						{{ t('buildiq', 'Base address') }}:
						<code class="ob-api-tab__base">{{ apiBase }}</code>
					</p>
					<p class="ob-api-tab__note">
						{{ t('buildiq', 'Sign in with your user name and an app password, which you create under Personal settings, Security. Never use your own password in an integration.') }}
					</p>
					<p
						v-for="binding in undocumented"
						:key="binding.register + '/' + binding.schema"
						class="ob-api-tab__note">
						{{ binding.schema }}: {{ t('buildiq', 'Its register did not describe this schema.') }}
					</p>
				</template>
			</CnApiReference>

			<section v-if="built.notDocumented.length" class="ob-api-tab__connectors">
				<h4>{{ t('buildiq', 'Not documented here') }}</h4>
				<p class="ob-api-tab__note">
					{{ t('buildiq', 'These pages read from a connector, not from an OpenRegister schema.') }}
				</p>
				<ul>
					<li v-for="entry in built.notDocumented" :key="entry.page + entry.endpointPath">
						{{ entry.title }}: <code>{{ entry.endpointPath }}</code>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { CnApiReference } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import applicationContext from '../../mixins/applicationContext.js'
import { boundSchemas, buildAppOpenApi, openApiFileName } from '../../services/appOpenApi.js'

/**
 * The own UUID of a version row.
 *
 * @param {object} row The version row.
 * @return {string}
 */
function rowUuid(row) {
	if (!row) {
		return ''
	}
	const self = row['@self'] || {}
	return [row.id, row.uuid, self.id, self.uuid].find(Boolean) || ''
}

export default {
	name: 'ApplicationApiTab',
	components: { CnApiReference },
	mixins: [applicationContext],

	data() {
		return {
			versions: [],
			documents: {},
			loading: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The production version UUID of the app.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
		 */
		productionUuid() {
			const pv = this.obApp && this.obApp.productionVersion
			if (!pv) {
				return ''
			}
			return typeof pv === 'string' ? pv : [pv.uuid, pv.id].find(Boolean) || ''
		},

		/**
		 * The version the page has selected (`?_version=`), else production, else the first.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
		 */
		version() {
			const slug = (this.$route && this.$route.query && this.$route.query._version) || ''
			return this.versions.find((v) => slug && v.slug === slug)
				|| this.versions.find((v) => rowUuid(v) === this.productionUuid)
				|| this.versions[0]
				|| null
		},

		/** @return {string} The version's name and semver. */
		versionLabel() {
			const v = this.version || {}
			const name = v.name || v.slug || ''
			return v.semver ? `${name} (${v.semver})` : name
		},

		/**
		 * This instance's OpenRegister API base address.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
		 */
		apiBase() {
			const origin = (typeof window !== 'undefined' && window.location && window.location.origin) || ''
			return origin + generateUrl('/apps/openregister/api')
		},

		/**
		 * The cut document and what it says about each bound schema.
		 *
		 * @return {{document: object, schemas: Array<object>, notDocumented: Array<object>}}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-document-holds-only-what-the-app-uses-req-bqad-002
		 */
		built() {
			return buildAppOpenApi({
				manifest: (this.version && this.version.manifest) || {},
				documents: this.documents,
				appName: (this.obApp && (this.obApp.name || this.obApp.slug)) || '',
				version: (this.version && this.version.semver) || '',
				apiBase: this.apiBase,
			})
		},

		/**
		 * The bound schemas whose register did not describe them.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-document-holds-only-what-the-app-uses-req-bqad-002
		 */
		undocumented() {
			return this.built.schemas.filter((binding) => !binding.documented)
		},

		/**
		 * The download file name stem the reference saves under (`<app>-<version>`).
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003
		 */
		downloadName() {
			return openApiFileName(this.built.document).replace(/\.openapi\.json$/, '')
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
			 */
			handler(slug) {
				if (slug) {
					this.load()
				}
			},
		},

		'$route.query._version': function () {
			this.loadDocuments()
		},
	},

	methods: {
		/**
		 * Load the versions, then the documents of the selected version's registers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/buildiq/api/applications/{slug}/versions', { slug: this.obApp.slug }),
				)
				const rows = Array.isArray(data) ? data : (data && data.results) || []
				this.versions = rows.filter((v) => (v.status || 'draft') !== 'archived')
			} catch {
				this.versions = []
			}
			await this.loadDocuments()
			this.loading = false
		},

		/**
		 * Fetch OpenRegister's document for each register the selected version binds, from this instance.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003
		 */
		async loadDocuments() {
			const manifest = (this.version && this.version.manifest) || {}
			const registers = [...new Set(boundSchemas(manifest).map((binding) => binding.register))]
			const documents = {}
			this.error = ''
			await Promise.all(registers.map(async (register) => {
				try {
					const { data } = await axios.get(
						generateUrl('/apps/openregister/api/registers/{id}/oas', { id: register }),
					)
					documents[register] = data
				} catch {
					this.error = t('buildiq', 'The description of one or more registers could not be read.')
				}
			}))
			this.documents = documents
		},
	},
}
</script>

<style scoped>
.ob-api-tab {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.ob-api-tab__note {
	color: var(--color-text-maxcontrast);
}

.ob-api-tab__error {
	color: var(--color-error-text);
}
</style>
