<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ConnectorSourcePicker — builder UI that lists OpenConnector endpoints and
  - binds one as the data source of an index page or dashboard widget.
  -
  - Each row shows the endpoint path + its Source display name ONLY — never any
  - credential material (REQ-OCAS-004). Selection writes `endpointPath` to the
  - in-flight binding and emits `sample-fetch` so the mapping editor can pull a
  - sample payload.
  -
  - When OpenConnector is absent (soft check via useAppStatus), the picker
  - disables the live list and offers a manual endpoint-path escape hatch that
  - marks the binding "unverified" (REQ-OCAS-005).
  -->
<template>
	<div class="connector-source-picker">
		<div v-if="appAvailable" class="connector-source-picker__live">
			<NcSelect
				class="connector-source-picker__source"
				:modelValue="selectedSourceOption"
				:options="sourceOptions"
				:loading="loading"
				:inputLabel="t('buildiq', 'Source')"
				:placeholder="t('buildiq', 'Select a source')"
				label="label"
				@update:modelValue="onSelectSource" />
			<NcSelect
				class="connector-source-picker__endpoint"
				:modelValue="selectedOption"
				:options="endpointOptions"
				:loading="loading"
				:inputLabel="t('buildiq', 'Endpoint')"
				:placeholder="t('buildiq', 'Select an endpoint')"
				label="label"
				@update:modelValue="onSelect" />
			<p v-if="error" class="connector-source-picker__error">
				{{ t('buildiq', 'Could not load the connector sources and endpoints.') }}
			</p>
			<p
				v-else-if="!loading && endpoints.length === 0"
				class="connector-source-picker__hint">
				{{ t('buildiq', 'No OpenConnector endpoints are configured yet.') }}
			</p>
			<p
				v-else-if="!loading && selectedSourceId && endpointOptions.length === 0"
				class="connector-source-picker__hint">
				{{ t('buildiq', 'This source has no endpoints yet.') }}
			</p>
		</div>

		<div v-else class="connector-source-picker__manual">
			<p
				class="connector-source-picker__hint connector-source-picker__hint--warning">
				{{
					t(
						'buildiq',
						'OpenConnector is not installed or enabled on this instance. You can still author an endpoint path manually, but it cannot be verified here.',
					)
				}}
			</p>
			<label class="connector-source-picker__manual-label">
				{{ t('buildiq', 'Endpoint path') }}
				<input
					type="text"
					:value="manualPath"
					:placeholder="t('buildiq', 'e.g. kvk/companies')"
					@input="onManualInput($event.target.value)" />
			</label>
			<p v-if="manualPath" class="connector-source-picker__unverified">
				{{
					t('buildiq', 'This binding cannot be verified on this instance.')
				}}
			</p>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import { useAppStatus } from '../../composables/useAppStatus.js'
import { resolveFleetAppId } from '../../services/fleetAppId.js'

/**
 * The key an OpenRegister object is known by: its uuid, or its id.
 *
 * @param {object} row - an OpenRegister object.
 * @return {string} the key, or ''.
 */
function objectKey(row) {
	const self = (row && row['@self']) || {}
	return String((row && (row.uuid || row.id)) || self.id || '')
}

/**
 * Normalise an endpoint path the way the runtime calls it: no leading slash.
 *
 * @param {string} path - the stored endpoint path.
 * @return {string} the path without leading slashes.
 */
function trimPath(path) {
	return String(path || '').replace(/^\/+/, '')
}

export default {
	name: 'ConnectorSourcePicker',
	components: { NcSelect },
	props: {
		// The current `dataSource.connector` block (may be partial / empty).
		binding: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:endpointPath', 'sample-fetch'],
	/**
	 * Soft capability check for OpenConnector (REQ-OCAS-005).
	 *
	 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.2
	 */
	setup() {
		const status = useAppStatus('integriq')
		return { status }
	},

	data() {
		return {
			endpoints: [],
			sources: [],
			// The source the endpoint list is filtered by. Not stored in the
			// manifest: it follows from the chosen endpoint.
			pickedSourceId: '',
			loading: false,
			error: false,
			manualPath: '',
		}
	},

	computed: {
		/**
		 * Whether OpenConnector is available; assume available until the
		 * async soft-check resolves so the live UI does not flash.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
		 */
		appAvailable() {
			return !this.status.checked.value || this.status.available.value
		},

		/**
		 * Endpoint rows projected to NcSelect options — path + Source name
		 * ONLY (REQ-OCAS-004: never a credential).
		 *
		 * @return {Array<{label: string, path: string}>}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-004
		 */
		endpointOptions() {
			const sourceId = this.selectedSourceId
			return this.endpoints
				.filter((e) => !sourceId || e.sourceId === sourceId)
				.map((e) => ({
					label: e.sourceName ? `${e.path} (${e.sourceName})` : e.path,
					path: e.path,
				}))
		},

		/**
		 * Source rows projected to NcSelect options, name only.
		 *
		 * @return {Array<{label: string, id: string}>}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-004
		 */
		sourceOptions() {
			return this.sources.map((source) => ({
				label: source.name,
				id: source.id,
			}))
		},

		/**
		 * The source the endpoint list is filtered by: the one picked, or the
		 * source of the endpoint already bound.
		 *
		 * @return {string} source id, or ''.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		selectedSourceId() {
			if (this.pickedSourceId) {
				return this.pickedSourceId
			}
			const current = trimPath(this.binding && this.binding.endpointPath)
			const bound = this.endpoints.find((e) => current && e.path === current)
			return (bound && bound.sourceId) || ''
		},

		/**
		 * The source option matching `selectedSourceId`, for NcSelect's value.
		 *
		 * @return {?object}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		selectedSourceOption() {
			return (
				this.sourceOptions.find((o) => o.id === this.selectedSourceId)
				|| null
			)
		},

		/**
		 * The option matching the current binding, for NcSelect's value.
		 *
		 * @return {?object}
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		selectedOption() {
			const current = trimPath(this.binding && this.binding.endpointPath)
			return this.endpointOptions.find((o) => o.path === current) || null
		},
	},

	/** @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002 */
	async mounted() {
		await this.status.check()
		if (this.status.available.value) {
			await this.fetchEndpoints()
		}
		this.manualPath = (this.binding && this.binding.endpointPath) || ''
	},

	methods: {
		/**
		 * Fetch the configured OpenConnector endpoints. The list is mapped to
		 * a credential-free `{ path, sourceName }` shape; any credential-shaped
		 * field in the upstream payload is dropped here and never reaches the
		 * DOM or component state (REQ-OCAS-004).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.1
		 */
		async fetchEndpoints() {
			this.loading = true
			this.error = false
			try {
				// The connector app keeps its sources and endpoints as
				// OpenRegister objects in its own register; its old
				// `/api/endpoints` route no longer exists.
				const register = resolveFleetAppId('integriq')
				const base = `/apps/openregister/api/objects/${register}`
				const params = { params: { _limit: 500 } }
				const [sourcesResponse, endpointsResponse] = await Promise.all([
					axios.get(generateUrl(`${base}/source`), params),
					axios.get(generateUrl(`${base}/endpoint`), params),
				])
				const rows = (response) => {
					const data = response && response.data
					const list = (data && (data.results || data)) || []
					return Array.isArray(list) ? list : []
				}
				// Name and id only: a source row also carries credentials, and
				// none of that may reach the page (REQ-OCAS-004).
				this.sources = rows(sourcesResponse)
					.map((row) => ({
						id: objectKey(row),
						name: row.name || row.slug || objectKey(row),
					}))
					.filter((source) => source.id)
				const nameById = new Map(
					this.sources.map((source) => [source.id, source.name]),
				)
				this.endpoints = rows(endpointsResponse)
					.map((row) => {
						const sourceId = String(row.targetId || '')
						return {
							path: trimPath(row.endpoint || row.path || row.slug || ''),
							sourceId,
							sourceName: nameById.get(sourceId) || '',
						}
					})
					.filter((e) => e.path)
			} catch {
				this.error = true
				this.endpoints = []
				this.sources = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Pick the source whose endpoints are offered. An endpoint from another
		 * source is unbound, since it no longer matches the choice.
		 *
		 * @param {?object} option - selected source option.
		 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-002
		 */
		onSelectSource(option) {
			const id = option && option.id ? option.id : ''
			this.pickedSourceId = id
			const current = trimPath(this.binding && this.binding.endpointPath)
			const bound = this.endpoints.find((e) => current && e.path === current)
			if (bound && id && bound.sourceId !== id) {
				this.$emit('update:endpointPath', '')
			}
		},

		/**
		 * Handle endpoint selection from the live list.
		 *
		 * @param {?object} option - selected NcSelect option.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.1
		 */
		onSelect(option) {
			const path = option && option.path ? option.path : ''
			this.$emit('update:endpointPath', path)
			if (path) {
				this.$emit('sample-fetch', path)
			}
		},

		/**
		 * Handle manual endpoint-path entry (escape hatch when OpenConnector
		 * is absent). Strips any scheme/host so the runtime call stays
		 * same-origin per REQ-OCAS-004.
		 *
		 * @param {string} value - raw input.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-2.2
		 */
		onManualInput(value) {
			const cleaned = String(value || '')
				.replace(/^https?:\/\/[^/]+/, '')
				.replace(/^\/+/, '')
			this.manualPath = cleaned
			this.$emit('update:endpointPath', cleaned)
			if (cleaned) {
				this.$emit('sample-fetch', cleaned)
			}
		},
	},
}
</script>

<style scoped>
.connector-source-picker__error {
	color: var(--color-error);
	margin: 4px 0 0;
}

.connector-source-picker__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.connector-source-picker__hint--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.connector-source-picker__unverified {
	color: var(--color-warning-text, var(--color-warning));
	margin: 4px 0 0;
	font-size: 0.9em;
}

.connector-source-picker__manual-label {
	display: block;
	margin-top: 8px;
}

.connector-source-picker__manual-label input {
	width: 100%;
}
</style>
