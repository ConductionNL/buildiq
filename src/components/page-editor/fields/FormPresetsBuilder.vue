<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormPresetsBuilder: the values a form sets on the object it creates.
  -
  - A hidden preset is the point of the whole row. The citizen's form for a
  - building permit records that it came in through the portal, and the citizen
  - is never asked a question whose answer was already decided. The form does
  - not write it: buildiq never writes the consumer's object. The preset travels
  - beside the served form and the consumer applies it on submit.
  -
  - A visible preset is a pre-filled answer the filer can still change.
  -
  - The property picker is the target schema's own list where buildiq could read
  - it. A typed property name that nothing answers to is written and ignored,
  - and the only thing that would say so is the warning the save returns.
  -
  - @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
  -->
<template>
	<fieldset class="form-presets">
		<legend>{{ t('buildiq', 'Preset values') }}</legend>

		<p class="form-presets__note">
			{{
				t(
					'buildiq',
					'Set an answer in advance. Hide it and the filer never sees the question.',
				)
			}}
		</p>

		<div
			v-for="(preset, index) in presets"
			:key="index"
			class="form-presets__row">
			<label class="form-presets__grow">
				<span class="form-presets__label">{{
					t('buildiq', 'Property')
				}}</span>
				<select
					v-if="properties && properties.length"
					:value="preset.field || ''"
					@change="write(index, 'field', $event.target.value)">
					<option value="">
						{{ t('buildiq', 'Pick a property') }}
					</option>
					<option v-for="p in properties" :key="p" :value="p">
						{{ p }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="preset.field || ''"
					:placeholder="t('buildiq', 'intakeChannel')"
					@input="write(index, 'field', $event.target.value)" />
			</label>

			<label class="form-presets__grow">
				<span class="form-presets__label">{{ t('buildiq', 'Value') }}</span>
				<input
					type="text"
					:value="preset.value || ''"
					:placeholder="t('buildiq', 'portal')"
					@input="write(index, 'value', $event.target.value)" />
			</label>

			<label class="form-presets__inline">
				<input
					type="checkbox"
					:checked="!!preset.hidden"
					@change="write(index, 'hidden', $event.target.checked)" />
				{{ t('buildiq', 'The filer never sees it') }}
			</label>

			<button
				type="button"
				:aria-label="t('buildiq', 'Remove preset')"
				:title="t('buildiq', 'Remove preset')"
				@click="remove(index)">
				✕
			</button>
		</div>

		<button type="button" @click="add">
			{{ t('buildiq', 'Add a preset') }}
		</button>
	</fieldset>
</template>

<script>
export default {
	name: 'FormPresetsBuilder',

	props: {
		presets: {
			type: Array,
			default: () => [],
		},

		// The target schema's property names, or null when buildiq could not
		// read it. Null means a text input rather than an empty picker.
		properties: {
			type: Array,
			default: null,
		},
	},

	emits: ['update:presets'],

	methods: {
		/**
		 * Write one key of one preset.
		 *
		 * @param {number} index - which preset.
		 * @param {'field'|'value'|'hidden'} key - the key.
		 * @param {string|boolean} value - the new value.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005)
		 */
		write(index, key, value) {
			const next = this.presets.slice()
			next[index] = { ...(next[index] || {}), [key]: value }
			this.$emit('update:presets', next)
		},

		/**
		 * Add an empty preset.
		 *
		 * @return {void}
		 */
		add() {
			this.$emit('update:presets', [
				...this.presets,
				{ field: '', value: '', hidden: false },
			])
		},

		/**
		 * Remove one preset.
		 *
		 * @param {number} index - which preset.
		 * @return {void}
		 */
		remove(index) {
			const next = this.presets.slice()
			next.splice(index, 1)
			this.$emit('update:presets', next)
		},
	},
}
</script>

<style scoped>
.form-presets__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	padding-block: 4px;
}

.form-presets__grow {
	flex: 1 1 160px;
}

.form-presets__label {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.form-presets__inline {
	display: flex;
	gap: 4px;
	align-items: center;
}

.form-presets__note {
	color: var(--color-text-maxcontrast);
}
</style>
