<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ChildRecordsFieldSettings — the settings of a `type: "child-records"`
  - form field: the child schema (from the form's register), the child
  - property that points back at the form's schema (`parentField`) and the
  - child properties shown as table columns (`columns`). These are the keys
  - nextcloud-vue's child-records table reads.
  -
  - A child schema with no property referencing the form's schema cannot be
  - saved into: it is named as unusable and NOT written as the field's
  - `schema`.
  -
  - @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-table-of-child-records-req-bqfk-003
  -->
<template>
	<div class="child-records-settings">
		<label class="child-records-settings__row">
			<span class="child-records-settings__label">{{ t('buildiq', 'Child schema') }}</span>
			<select
				class="child-records-settings__input"
				data-testid="child-schema"
				:value="pickedSchema"
				@change="pickSchema($event.target.value)">
				<option value="">
					{{ t('buildiq', 'Pick a schema') }}
				</option>
				<option
					v-for="sc in schemas"
					:key="schemaKey(sc)"
					:value="schemaKey(sc)">
					{{ sc.title || sc.slug || schemaKey(sc) }}
				</option>
			</select>
		</label>
		<p
			v-if="unusable"
			class="child-records-settings__unusable"
			role="alert"
			data-testid="child-schema-unusable">
			{{ t('buildiq', 'This schema has no field pointing at {schema}, so its records cannot belong to this form.', { schema: parentName }) }}
		</p>
		<template v-if="childSchema && !unusable">
			<label class="child-records-settings__row">
				<span class="child-records-settings__label">{{ t('buildiq', 'Field pointing at this form') }}</span>
				<select
					class="child-records-settings__input"
					data-testid="child-parent-field"
					:value="field.parentField || ''"
					@change="write({ parentField: $event.target.value || null })">
					<option v-for="name in referenceFields" :key="name" :value="name">
						{{ name }}
					</option>
				</select>
			</label>
			<fieldset class="child-records-settings__columns">
				<legend class="child-records-settings__label">
					{{ t('buildiq', 'Columns') }}
				</legend>
				<label
					v-for="name in columnOptions"
					:key="name"
					class="child-records-settings__inline">
					<input
						type="checkbox"
						:data-testid="'child-column-' + name"
						:checked="columns.includes(name)"
						@change="toggleColumn(name, $event.target.checked)" />
					{{ name }}
				</label>
			</fieldset>
		</template>
	</div>
</template>

<script>
import { parentReferenceFields, schemaPropertyNames } from './fieldTypes.js'

export default {
	name: 'ChildRecordsFieldSettings',

	props: {
		field: {
			type: Object,
			default: () => ({}),
		},

		// The schemas of the form's register, each with its `properties`.
		schemas: {
			type: Array,
			default: () => [],
		},

		// The slug or id of the schema the form saves into.
		parentSchema: {
			type: String,
			default: '',
		},
	},

	emits: ['update'],

	data() {
		return {
			// A picked schema that cannot be used: shown, never written.
			rejected: '',
		}
	},

	computed: {
		/**
		 * The picker's value: the rejected pick while it is shown, else the
		 * stored `schema`.
		 *
		 * @return {string}
		 */
		pickedSchema() {
			return this.rejected || (typeof this.field.schema === 'string' ? this.field.schema : '')
		},

		/**
		 * The picked schema's entry in the register.
		 *
		 * @return {object|null}
		 */
		childSchema() {
			return this.findSchema(this.pickedSchema)
		},

		/**
		 * The form's own schema entry.
		 *
		 * @return {object|null}
		 */
		parent() {
			return this.findSchema(this.parentSchema)
		},

		/**
		 * The name used for the form's schema in messages.
		 *
		 * @return {string}
		 */
		parentName() {
			const p = this.parent
			return (p && (p.title || p.slug)) || this.parentSchema || t('buildiq', 'this form')
		},

		/**
		 * The child properties referencing the form's schema.
		 *
		 * @return {string[]}
		 */
		referenceFields() {
			return parentReferenceFields(this.childSchema, this.parent)
		},

		/**
		 * Whether the picked schema has no property pointing back.
		 *
		 * @return {boolean}
		 */
		unusable() {
			return !!this.childSchema && this.referenceFields.length === 0
		},

		/**
		 * The child properties offered as columns (the parent reference left out).
		 *
		 * @return {string[]}
		 */
		columnOptions() {
			return schemaPropertyNames(this.childSchema).filter((n) => n !== this.field.parentField)
		},

		/**
		 * The stored columns.
		 *
		 * @return {string[]}
		 */
		columns() {
			return Array.isArray(this.field.columns) ? this.field.columns : []
		},
	},

	methods: {
		/**
		 * The key a schema is picked by: slug, else id.
		 *
		 * @param {object} schema - the schema.
		 * @return {string}
		 */
		schemaKey(schema) {
			return String(schema.slug || schema.id)
		},

		/**
		 * The schema matching a slug, id or uuid.
		 *
		 * @param {string} key - the identifier.
		 * @return {object|null}
		 */
		findSchema(key) {
			if (!key) {
				return null
			}
			return this.schemas.find((sc) => [sc.slug, sc.id, sc.uuid].some((v) => v !== undefined && v !== null && String(v) === String(key))) || null
		},

		/**
		 * Pick the child schema. One with no reference back is shown as
		 * unusable and not written; a usable one is written with its parent
		 * field (the first reference) and the columns cleared.
		 *
		 * @param {string} key - the picked schema key, or ''.
		 * @return {void}
		 * @spec openspec/changes/forms-designer-file-camera-children-and-assign/specs/form-field-kinds/spec.md#requirement-a-maker-adds-a-table-of-child-records-req-bqfk-003
		 */
		pickSchema(key) {
			const schema = this.findSchema(key)
			if (schema && parentReferenceFields(schema, this.parent).length === 0) {
				this.rejected = key
				return
			}
			this.rejected = ''
			const refs = schema ? parentReferenceFields(schema, this.parent) : []
			this.write({
				schema: schema ? key : null,
				parentField: refs[0] || null,
				columns: null,
			})
		},

		/**
		 * Add or remove a column, keeping the child schema's property order.
		 *
		 * @param {string} name - the property.
		 * @param {boolean} on - whether it is shown.
		 * @return {void}
		 */
		toggleColumn(name, on) {
			const wanted = new Set(this.columns)
			if (on) {
				wanted.add(name)
			} else {
				wanted.delete(name)
			}
			const ordered = schemaPropertyNames(this.childSchema).filter((n) => wanted.has(n))
			this.write({ columns: ordered.length ? ordered : null })
		},

		/**
		 * Emit the field with the given keys set, or removed on null.
		 *
		 * @param {object} changes - key to next value.
		 * @return {void}
		 */
		write(changes) {
			const next = { ...this.field }
			for (const [key, value] of Object.entries(changes)) {
				if (value === null || value === undefined) {
					delete next[key]
				} else {
					next[key] = value
				}
			}
			this.$emit('update', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.child-records-settings {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.child-records-settings__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.child-records-settings__label {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.child-records-settings__input {
	@include c.control;
}

.child-records-settings__unusable {
	margin: 0;
	color: var(--color-error-text, var(--color-error));
}

.child-records-settings__columns {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	border: none;
	padding: 0;
	margin: 0;
}

.child-records-settings__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}
</style>
