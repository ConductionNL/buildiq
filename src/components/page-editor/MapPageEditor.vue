<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - MapPageEditor — structured editor for `type: "map"` pages (REQ-PEC-003).
  -
  - Manifest contract verified against `CnMapPage.vue` / `CnMapWidget.vue`:
  -   - `center` ([lat, lng], required) + `zoom` (number) + `height` (string);
  -   - `layers[]` — `{ type: 'tile'|'wms'|'wfs'|'geojson', url, options }`,
  -     edited as a small inline row-list (raw `options` left to Raw JSON);
  -   - `markers` — one-of radio between "Source URL"
  -     (`markers.dataSource.url`, the renderer's working fetch path) and
  -     "Register + schema" (`markers.dataSource.{register, schema}`, the
  -     canonical-but-reserved shape per design.md Decision 3 — a persistent
  -     hint tells the author renderer support is pending), plus
  -     `latField` / `lngField` / `popupField` (schema-property dropdowns
  -     once a register + schema are bound, free-text otherwise) and a
  -     `clustering` checkbox.
  -
  - Lossless round-trip: `update(key, value)` clones `config` and only
  - touches the one key (markers is replaced wholesale but its own
  - unsurfaced sibling keys are preserved by cloning `config.markers` first),
  - so externally-authored keys this editor doesn't surface survive every
  - edit.
  -->
<template>
	<div class="map-page-editor">
		<h3 class="map-page-editor__title">
			{{ t('openbuild', 'Map page') }}
		</h3>

		<fieldset class="map-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Viewport') }}</legend>
			<div class="map-page-editor__group">
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Centre latitude') }}
					<input
						type="number"
						step="any"
						:value="centerLat"
						:aria-invalid="isInvalid('center')"
						@input="updateCenterPart(0, $event.target.value)" />
				</label>
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Centre longitude') }}
					<input
						type="number"
						step="any"
						:value="centerLng"
						:aria-invalid="isInvalid('center')"
						@input="updateCenterPart(1, $event.target.value)" />
				</label>
				<InlineFieldMark :error="markFor('center')" />
			</div>
			<label class="map-page-editor__group-row">
				{{ t('openbuild', 'Zoom') }}
				<input
					type="number"
					:value="config.zoom"
					:aria-invalid="isInvalid('zoom')"
					@input="updateZoom($event.target.value)" />
				<InlineFieldMark :error="markFor('zoom')" />
			</label>
			<label class="map-page-editor__group-row">
				{{ t('openbuild', 'Height (optional)') }}
				<input
					type="text"
					:value="config.height || ''"
					:placeholder="t('openbuild', 'e.g. 500px')"
					:aria-invalid="isInvalid('height')"
					@input="update('height', $event.target.value)" />
				<InlineFieldMark :error="markFor('height')" />
			</label>
		</fieldset>

		<fieldset class="map-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Layers') }}</legend>
			<div
				v-for="(layer, index) in layers"
				:key="index"
				class="map-page-editor__row">
				<select
					:value="layer.type || 'tile'"
					@change="updateLayerField(index, 'type', $event.target.value)">
					<option value="tile">tile</option>
					<option value="wms">wms</option>
					<option value="wfs">wfs</option>
					<option value="geojson">geojson</option>
				</select>
				<input
					type="text"
					class="map-page-editor__row-url"
					:value="layer.url || ''"
					:placeholder="t('openbuild', 'Layer URL')"
					:aria-label="t('openbuild', 'Layer URL')"
					@input="updateLayerField(index, 'url', $event.target.value)" />
				<button
					type="button"
					class="map-page-editor__row-remove"
					:title="t('openbuild', 'Remove layer')"
					@click="removeLayer(index)">
					✕
				</button>
			</div>
			<button type="button" class="map-page-editor__row-add" @click="addLayer">
				+ {{ t('openbuild', 'Add layer') }}
			</button>
			<InlineFieldMark :error="markFor('layers')" />
		</fieldset>

		<fieldset class="map-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Markers') }}</legend>
			<div class="map-page-editor__shape">
				<label class="map-page-editor__inline">
					<input
						type="radio"
						:checked="markerSourceShape === 'url'"
						value="url"
						@change="setMarkerSourceShape('url')" />
					{{ t('openbuild', 'Source URL') }}
				</label>
				<label class="map-page-editor__inline">
					<input
						type="radio"
						:checked="markerSourceShape === 'register'"
						value="register"
						@change="setMarkerSourceShape('register')" />
					{{ t('openbuild', 'Register + schema') }}
				</label>
			</div>

			<div v-if="markerSourceShape === 'url'" class="map-page-editor__group">
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Marker source URL') }}
					<input
						type="text"
						:value="
							(config.markers
								&& config.markers.dataSource
								&& config.markers.dataSource.url)
							|| ''
						"
						:placeholder="t('openbuild', 'https://.../markers.json')"
						@input="
							updateMarkerDataSourceField('url', $event.target.value)
						" />
				</label>
			</div>
			<div v-else class="map-page-editor__group">
				<p class="map-page-editor__hint" role="note">
					{{
						t(
							'openbuild',
							'Renderer support for register-bound markers is pending in the library — the built page will show an empty marker layer until it ships.',
						)
					}}
				</p>
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Register') }}
					<select
						:value="
							(config.markers
								&& config.markers.dataSource
								&& config.markers.dataSource.register)
							|| ''
						"
						@change="
							updateMarkerDataSourceRegister($event.target.value)
						">
						<option value="">
							{{ t('openbuild', '— select register —') }}
						</option>
						<option
							v-for="r in registers"
							:key="r.slug || r.id"
							:value="r.slug">
							{{ r.title || r.slug }}
						</option>
					</select>
				</label>
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Schema') }}
					<select
						:value="
							(config.markers
								&& config.markers.dataSource
								&& config.markers.dataSource.schema)
							|| ''
						"
						:disabled="
							!(
								config.markers
								&& config.markers.dataSource
								&& config.markers.dataSource.register
							)
						"
						@change="
							updateMarkerDataSourceField(
								'schema',
								$event.target.value,
							)
						">
						<option value="">
							{{ t('openbuild', '— select schema —') }}
						</option>
						<option
							v-for="s in schemas"
							:key="s.slug || s.id"
							:value="s.slug">
							{{ s.title || s.slug }}
						</option>
					</select>
				</label>
			</div>

			<div class="map-page-editor__group">
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Latitude field') }}
					<select
						v-if="hasBoundSchema"
						:value="(config.markers && config.markers.latField) || ''"
						@change="updateMarkerField('latField', $event.target.value)">
						<option value="">
							{{ t('openbuild', '— select property —') }}
						</option>
						<option
							v-for="key in schemaPropertyKeys"
							:key="key"
							:value="key">
							{{ key }}
						</option>
					</select>
					<input
						v-else
						type="text"
						:value="(config.markers && config.markers.latField) || ''"
						@input="
							updateMarkerField('latField', $event.target.value)
						" />
				</label>
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Longitude field') }}
					<select
						v-if="hasBoundSchema"
						:value="(config.markers && config.markers.lngField) || ''"
						@change="updateMarkerField('lngField', $event.target.value)">
						<option value="">
							{{ t('openbuild', '— select property —') }}
						</option>
						<option
							v-for="key in schemaPropertyKeys"
							:key="key"
							:value="key">
							{{ key }}
						</option>
					</select>
					<input
						v-else
						type="text"
						:value="(config.markers && config.markers.lngField) || ''"
						@input="
							updateMarkerField('lngField', $event.target.value)
						" />
				</label>
				<label class="map-page-editor__group-row">
					{{ t('openbuild', 'Popup field') }}
					<select
						v-if="hasBoundSchema"
						:value="(config.markers && config.markers.popupField) || ''"
						@change="
							updateMarkerField('popupField', $event.target.value)
						">
						<option value="">
							{{ t('openbuild', '— select property —') }}
						</option>
						<option
							v-for="key in schemaPropertyKeys"
							:key="key"
							:value="key">
							{{ key }}
						</option>
					</select>
					<input
						v-else
						type="text"
						:value="(config.markers && config.markers.popupField) || ''"
						@input="
							updateMarkerField('popupField', $event.target.value)
						" />
				</label>
				<label class="map-page-editor__inline">
					<input
						type="checkbox"
						:checked="!!(config.markers && config.markers.clustering)"
						@change="
							updateMarkerField('clustering', $event.target.checked)
						" />
					{{ t('openbuild', 'Clustering') }}
				</label>
			</div>
			<InlineFieldMark :error="markFor('markers')" />
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'MapPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'map',
		},
		appSlug: {
			type: String,
			default: '',
		},
		dataRegisters: {
			type: Array,
			default: () => [],
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	setup(props) {
		const picker = useRegisterPicker({
			appSlug: props.appSlug,
			dataRegisters: props.dataRegisters,
		})
		return { picker }
	},
	data() {
		return {
			registers: [],
			schemas: [],
			schemaProperties: {},
		}
	},
	computed: {
		validatedConfigKeys() {
			return ['center', 'zoom', 'height', 'layers', 'markers']
		},
		centerLat() {
			return Array.isArray(this.config.center) ? this.config.center[0] : ''
		},
		centerLng() {
			return Array.isArray(this.config.center) ? this.config.center[1] : ''
		},
		layers() {
			return Array.isArray(this.config.layers) ? this.config.layers : []
		},
		markerDataSource() {
			return (this.config.markers && this.config.markers.dataSource) || {}
		},
		markerSourceShape() {
			// Register wins only when explicitly bound and no URL is set, so a
			// half-edited config never silently flips branches.
			if (this.markerDataSource.register && !this.markerDataSource.url) {
				return 'register'
			}
			return 'url'
		},
		hasBoundSchema() {
			return (
				this.markerSourceShape === 'register'
				&& !!this.markerDataSource.register
				&& !!this.markerDataSource.schema
			)
		},
		schemaPropertyKeys() {
			return Object.keys(this.schemaProperties || {})
		},
	},
	watch: {
		'config.markers': {
			deep: true,
			immediate: true,
			handler(val) {
				const ds = (val && val.dataSource) || {}
				if (ds.register) {
					this.fetchSchemas(ds.register)
				} else {
					this.schemas = []
				}
				if (ds.register && ds.schema) {
					this.fetchSchemaProperties(ds.register, ds.schema)
				} else {
					this.schemaProperties = {}
				}
			},
		},
	},
	async mounted() {
		await this.fetchRegisters()
	},
	methods: {
		/**
		 * Lossless top-level `config` key update: clone, delete-on-empty,
		 * touch only the edited key.
		 *
		 * @param {string} key - top-level config key.
		 * @param {*} value - new value.
		 */
		update(key, value) {
			const next = { ...this.config }
			if (
				value === ''
				|| value === null
				|| (Array.isArray(value) && value.length === 0)
			) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Write one element of the `center` [lat, lng] pair.
		 *
		 * @param {number} index - 0 for latitude, 1 for longitude.
		 * @param {string} rawValue - raw input value.
		 */
		updateCenterPart(index, rawValue) {
			const current = Array.isArray(this.config.center)
				? this.config.center.slice()
				: [0, 0]
			const num = parseFloat(rawValue)
			current[index] = Number.isFinite(num) ? num : current[index]
			this.update('center', current)
		},
		/**
		 * Write the `zoom` number field; invalid input is a no-op.
		 *
		 * @param {string} rawValue - raw input value.
		 */
		updateZoom(rawValue) {
			const num = parseInt(rawValue, 10)
			if (!Number.isFinite(num)) {
				return
			}
			this.update('zoom', num)
		},
		/**
		 * Append a blank `layers[]` row.
		 */
		addLayer() {
			this.update('layers', this.layers.concat([{ type: 'tile', url: '' }]))
		},
		/**
		 * Remove a `layers[]` row by index.
		 *
		 * @param {number} index - row index.
		 */
		removeLayer(index) {
			const next = this.layers.slice()
			next.splice(index, 1)
			this.update('layers', next)
		},
		/**
		 * Update one field of one `layers[]` row.
		 *
		 * @param {number} index - row index.
		 * @param {string} key - row field key.
		 * @param {string} value - new value.
		 */
		updateLayerField(index, key, value) {
			const next = this.layers.slice()
			next[index] = { ...next[index], [key]: value }
			this.update('layers', next)
		},
		/**
		 * Update a flat `markers` field (latField/lngField/popupField/clustering),
		 * preserving `dataSource` and any other unsurfaced markers keys.
		 *
		 * @param {string} key - markers field key.
		 * @param {*} value - new value.
		 */
		updateMarkerField(key, value) {
			const next = { ...(this.config.markers || {}) }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.update('markers', next)
		},
		/**
		 * Update one field of `markers.dataSource`, preserving sibling
		 * `markers` keys.
		 *
		 * @param {string} key - dataSource field key.
		 * @param {string} value - new value.
		 */
		updateMarkerDataSourceField(key, value) {
			const nextMarkers = { ...(this.config.markers || {}) }
			const ds = { ...(nextMarkers.dataSource || {}) }
			if (value === '' || value === null) {
				delete ds[key]
			} else {
				ds[key] = value
			}
			if (Object.keys(ds).length === 0) {
				delete nextMarkers.dataSource
			} else {
				nextMarkers.dataSource = ds
			}
			this.update('markers', nextMarkers)
		},
		/**
		 * Register-picker change handler: writes `dataSource.register` and
		 * resets `dataSource.schema` (the dependent dropdown).
		 *
		 * @param {string} value - register slug.
		 */
		updateMarkerDataSourceRegister(value) {
			const nextMarkers = { ...(this.config.markers || {}) }
			const ds = { ...(nextMarkers.dataSource || {}) }
			delete ds.schema
			if (value === '' || value === null) {
				delete ds.register
			} else {
				ds.register = value
			}
			if (Object.keys(ds).length === 0) {
				delete nextMarkers.dataSource
			} else {
				nextMarkers.dataSource = ds
			}
			this.update('markers', nextMarkers)
		},
		/**
		 * Switch the marker-source one-of radio, mutually clearing the
		 * other branch's `dataSource` keys only.
		 *
		 * @param {string} shape - 'url' or 'register'.
		 */
		setMarkerSourceShape(shape) {
			const nextMarkers = { ...(this.config.markers || {}) }
			const ds = { ...(nextMarkers.dataSource || {}) }
			if (shape === 'register') {
				delete ds.url
			} else {
				delete ds.register
				delete ds.schema
			}
			if (Object.keys(ds).length === 0) {
				delete nextMarkers.dataSource
			} else {
				nextMarkers.dataSource = ds
			}
			this.update('markers', nextMarkers)
		},
		/**
		 * Fetch the registers list for the picker dropdown.
		 */
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},
		/**
		 * Fetch the schemas for a given register.
		 *
		 * @param {string} register - register slug.
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},
		/**
		 * Fetch schema properties for the field-mapping dropdowns.
		 *
		 * @param {string} register - register slug.
		 * @param {string} schema - schema slug.
		 */
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(
				register,
				schema,
			)
		},
	},
}
</script>

<style scoped>
.map-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.map-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.map-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.map-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.map-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.map-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.map-page-editor__group-row input,
.map-page-editor__group-row select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.map-page-editor__shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.map-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	font-size: 13px;
}

.map-page-editor__row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.map-page-editor__row select,
.map-page-editor__row-url {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.map-page-editor__row-url {
	flex: 1 1 auto;
}

.map-page-editor__row-remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.map-page-editor__row-add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.map-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
