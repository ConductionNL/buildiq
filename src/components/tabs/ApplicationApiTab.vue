<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V.
  SPDX-License-Identifier: EUPL-1.2

  ApplicationApiTab — the "API" tab of the app detail page: for the selected
  version, the OpenRegister calls and fields of every schema the app's pages
  use, cut from OpenRegister's own documents, with a download of that same
  document. Everything is read from this instance; nothing outside is loaded.

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
			<section class="ob-api-tab__intro">
				<h3>{{ t('buildiq', 'API') }}: {{ versionLabel }}</h3>
				<p>
					{{ t('buildiq', 'Base address') }}:
					<code class="ob-api-tab__base">{{ apiBase }}</code>
				</p>
				<p class="ob-api-tab__note">
					{{ t('buildiq', 'Sign in with your user name and an app password, which you create under Personal settings, Security. Never use your own password in an integration.') }}
				</p>
				<NcButton variant="primary" class="ob-api-tab__download" @click="download">
					{{ t('buildiq', 'Download OpenAPI file') }}
				</NcButton>
			</section>

			<p v-if="error" class="ob-api-tab__error">
				{{ error }}
			</p>
			<p v-if="schemaCards.length === 0" class="ob-api-tab__note">
				{{ t('buildiq', 'The pages of this version use no OpenRegister schema.') }}
			</p>

			<section
				v-for="card in schemaCards"
				:key="card.key"
				class="ob-api-tab__schema">
				<h4>{{ card.schema }} <span class="ob-api-tab__register">{{ card.register }}</span></h4>
				<p v-if="!card.documented" class="ob-api-tab__note">
					{{ t('buildiq', 'Its register did not describe this schema.') }}
				</p>
				<template v-else>
					<ul class="ob-api-tab__calls">
						<li v-for="call in card.calls" :key="call.method + call.path">
							<code>{{ call.method }} {{ call.path }}</code>
							<span v-if="call.summary"> — {{ call.summary }}</span>
						</li>
					</ul>
					<table v-if="card.fields.length" class="ob-api-tab__fields">
						<thead>
							<tr>
								<th>{{ t('buildiq', 'Field') }}</th>
								<th>{{ t('buildiq', 'Type') }}</th>
								<th>{{ t('buildiq', 'Rules') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="field in card.fields" :key="field.name">
								<td><code>{{ field.name }}</code></td>
								<td>{{ field.type }}</td>
								<td>{{ field.rules }}</td>
							</tr>
						</tbody>
					</table>
				</template>
			</section>

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
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import applicationContext from '../../mixins/applicationContext.js'
import { boundSchemas, buildAppOpenApi, openApiFileName } from '../../services/appOpenApi.js'

const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete']

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

/**
 * A field's rules, as the reference shows them.
 *
 * @param {object} definition The property definition.
 * @return {string}
 */
function rulesOf(definition) {
	const rules = []
	for (const key of ['format', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum']) {
		if (definition[key] !== undefined) {
			rules.push(`${key}: ${definition[key]}`)
		}
	}
	if (Array.isArray(definition.enum)) {
		rules.push(`enum: ${definition.enum.join(', ')}`)
	}
	return rules.join('; ')
}

export default {
	name: 'ApplicationApiTab',
	components: { NcButton },
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
		 * One card per bound schema: its calls and its fields.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-an-app-has-an-api-tab-per-version-req-bqad-001
		 */
		schemaCards() {
			const document = this.built.document
			return this.built.schemas.map((binding) => {
				const prefix = `/objects/${binding.register}/${binding.schema}`
				const calls = []
				let component = null
				for (const [path, item] of Object.entries(document.paths)) {
					if (path !== prefix && !path.startsWith(`${prefix}/`)) {
						continue
					}
					for (const method of HTTP_METHODS) {
						if (item[method]) {
							calls.push({ method: method.toUpperCase(), path, summary: item[method].summary || '' })
							const ref = JSON.stringify(item[method]).match(/#\/components\/schemas\/([^"]+)/)
							if (component === null && ref) {
								component = document.components.schemas[ref[1]] || null
							}
						}
					}
				}
				const properties = (component && component.properties) || {}
				return {
					key: `${binding.register}/${binding.schema}`,
					register: binding.register,
					schema: binding.schema,
					documented: binding.documented,
					calls,
					fields: Object.entries(properties).map(([name, definition]) => ({
						name,
						type: definition.type || (definition.$ref ? definition.$ref.split('/').pop() : ''),
						rules: rulesOf(definition),
					})),
				}
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

		/**
		 * Save the cut document as an OpenAPI 3 JSON file.
		 *
		 * @return {void}
		 * @spec openspec/changes/operate-api-docs/specs/app-api-docs/spec.md#requirement-the-reference-stays-inside-the-instance-and-downloads-as-a-file-req-bqad-003
		 */
		download() {
			const document = this.built.document
			const blob = new Blob([JSON.stringify(document, null, 2)], { type: 'application/json' })
			const url = URL.createObjectURL(blob)
			const anchor = window.document.createElement('a')
			anchor.href = url
			anchor.download = openApiFileName(document)
			anchor.click()
			URL.revokeObjectURL(url)
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

.ob-api-tab__register {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
	font-size: 0.9em;
}

.ob-api-tab__calls {
	list-style: none;
	padding: 0;
	margin: 4px 0 8px;
}

.ob-api-tab__fields {
	width: 100%;
	border-collapse: collapse;
}

.ob-api-tab__fields th,
.ob-api-tab__fields td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>
