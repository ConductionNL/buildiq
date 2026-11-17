<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FileFieldSettings — the settings of a `type: "file"` form field: allowed
  - types (`accept`), maximum size (typed in MB, stored in bytes as
  - `maxSize`, the unit nextcloud-vue CnFileField reads), several files
  - (`multiple`) and the camera (`capture`: back `environment`, front
  - `user`; off removes the key).
  -
  - Controlled: `:field` is the whole field entry, `update` emits the next
  - entry. Unknown keys on the field survive every edit.
  -
  - @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-file-field-with-its-limits-req-bqfk-001
  - @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-file-field-can-open-the-camera-req-bqfk-002
  -->
<template>
	<div class="file-field-settings">
		<label class="file-field-settings__row">
			<span class="file-field-settings__label">{{ t('buildiq', 'Allowed file types') }}</span>
			<input
				type="text"
				class="file-field-settings__input"
				data-testid="file-accept"
				:value="field.accept || ''"
				:placeholder="t('buildiq', 'For example .pdf, image/*')"
				@input="write('accept', $event.target.value.trim())" />
		</label>
		<label class="file-field-settings__row">
			<span class="file-field-settings__label">{{ t('buildiq', 'Maximum size (MB)') }}</span>
			<input
				type="number"
				min="0"
				step="any"
				class="file-field-settings__input file-field-settings__input--narrow"
				data-testid="file-max-size"
				:value="megabytes"
				@input="write('maxSize', toBytes($event.target.value))" />
		</label>
		<label class="file-field-settings__inline">
			<input
				type="checkbox"
				data-testid="file-multiple"
				:checked="field.multiple === true"
				@change="write('multiple', $event.target.checked ? true : null)" />
			{{ t('buildiq', 'Several files') }}
		</label>
		<label class="file-field-settings__row">
			<span class="file-field-settings__label">{{ t('buildiq', 'Camera') }}</span>
			<select
				class="file-field-settings__input file-field-settings__input--narrow"
				data-testid="file-capture"
				:value="field.capture || ''"
				@change="write('capture', $event.target.value || null)">
				<option v-for="value in CAPTURE_VALUES" :key="value" :value="value">
					{{ captureLabel(value) }}
				</option>
			</select>
		</label>
	</div>
</template>

<script>
import { bytesToMegabytes, CAPTURE_VALUES, megabytesToBytes } from './fieldTypes.js'

export default {
	name: 'FileFieldSettings',

	props: {
		field: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update'],

	data() {
		return { CAPTURE_VALUES }
	},

	computed: {
		/**
		 * The stored `maxSize` shown in megabytes.
		 *
		 * @return {string}
		 */
		megabytes() {
			return bytesToMegabytes(this.field.maxSize)
		},
	},

	methods: {
		/**
		 * Typed megabytes as bytes, or null to remove the key.
		 *
		 * @param {string} value - the typed value.
		 * @return {number|null}
		 */
		toBytes(value) {
			return megabytesToBytes(value)
		},

		/**
		 * The label of a camera setting.
		 *
		 * @param {string} value - '' (off), 'environment' or 'user'.
		 * @return {string}
		 */
		captureLabel(value) {
			if (value === 'environment') {
				return t('buildiq', 'Back camera')
			}
			if (value === 'user') {
				return t('buildiq', 'Front camera')
			}
			return t('buildiq', 'Off')
		},

		/**
		 * Emit the field with one key set, or removed on null / ''.
		 *
		 * @param {'accept'|'maxSize'|'multiple'|'capture'} key - the key.
		 * @param {unknown} value - the next value; null or '' removes the key.
		 * @return {void}
		 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-file-field-can-open-the-camera-req-bqfk-002
		 */
		write(key, value) {
			const next = { ...this.field }
			if (value === null || value === undefined || value === '') {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.file-field-settings {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: flex-end;
}

.file-field-settings__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.file-field-settings__label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.file-field-settings__input {
	@include c.control;
}

.file-field-settings__input--narrow {
	max-width: 160px;
}

.file-field-settings__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
</style>
