<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FormLayoutBuilder: the sections a registration form is arranged into, and
  - the fields inside them, in the order an administrator set.
  -
  - WHY THE FORM OWNS THE ORDER
  - The served form used to fall back to whatever order the target schema
  - happened to list its properties in. That reshuffles the day somebody adds a
  - property, and nothing on the form says it moved. So the form carries an
  - `order` per field and a `section` naming one of its own `sections[]`, and
  - this is where both are written (REQ-OBRF-008).
  -
  - ORDER IS REWRITTEN, NOT NUDGED
  - Every move rewrites `order` as 1..n inside the group it belongs to. Storing
  - the gaps a nudge leaves behind would make two forms with the same visible
  - order disagree in the manifest.
  -
  - A SECTION THAT STILL HOLDS FIELDS CANNOT BE DELETED
  - The save refuses a field naming a section the form does not declare, so
  - deleting a full section would produce a form nobody can save. Moving its
  - fields out silently would be worse: it changes what a citizen is asked
  - without anybody asking for it. So the button says what to do first.
  -
  - @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
  -->
<template>
	<div class="form-layout">
		<fieldset class="form-layout__block">
			<legend>{{ t('buildiq', 'Sections') }}</legend>

			<p v-if="!sections.length" class="form-layout__note">
				{{
					t(
						'buildiq',
						'No sections yet. Everything sits in one list until you add one.',
					)
				}}
			</p>

			<div
				v-for="(section, index) in sections"
				:key="section.name || index"
				class="form-layout__row">
				<label class="form-layout__grow">
					<span class="form-layout__label">{{
						t('buildiq', 'Section heading')
					}}</span>
					<input
						type="text"
						:value="section.label || ''"
						:placeholder="t('buildiq', 'Uw gegevens')"
						@input="renameSection(index, $event.target.value)" />
				</label>
				<label class="form-layout__narrow">
					<span class="form-layout__label">{{
						t('buildiq', 'Reference')
					}}</span>
					<input
						type="text"
						:value="section.name || ''"
						:placeholder="t('buildiq', 'uw-gegevens')"
						@input="setSectionName(index, $event.target.value)" />
				</label>
				<button
					type="button"
					:disabled="index === 0"
					:aria-label="t('buildiq', 'Move section up')"
					:title="t('buildiq', 'Move section up')"
					@click="moveSection(index, -1)">
					▲
				</button>
				<button
					type="button"
					:disabled="index === sections.length - 1"
					:aria-label="t('buildiq', 'Move section down')"
					:title="t('buildiq', 'Move section down')"
					@click="moveSection(index, 1)">
					▼
				</button>
				<button
					type="button"
					:disabled="holds(section.name).length > 0"
					:aria-label="t('buildiq', 'Delete section')"
					:title="deleteTitle(section)"
					@click="removeSection(index)">
					✕
				</button>
			</div>

			<button type="button" @click="addSection">
				{{ t('buildiq', 'Add section') }}
			</button>
		</fieldset>

		<fieldset class="form-layout__block">
			<legend>{{ t('buildiq', 'Fields') }}</legend>

			<p class="form-layout__note">
				{{
					t(
						'buildiq',
						'A form asks only the fields you put on it, in the order you put them.',
					)
				}}
			</p>

			<div
				v-for="group in groups"
				:key="group.name"
				class="form-layout__group">
				<h4 class="form-layout__group-title">
					{{ group.label }}
				</h4>

				<p v-if="!group.fields.length" class="form-layout__note">
					{{ t('buildiq', 'Nothing here yet.') }}
				</p>

				<div
					v-for="(entry, position) in group.fields"
					:key="entry.index"
					class="form-layout__row">
					<label class="form-layout__grow">
						<span class="form-layout__label">{{
							t('buildiq', 'Property')
						}}</span>
						<select
							v-if="properties && properties.length"
							:value="entry.field.name || ''"
							@change="
								write(entry.index, 'name', $event.target.value)
							">
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
							:value="entry.field.name || ''"
							:placeholder="t('buildiq', 'applicantRole')"
							@input="
								write(entry.index, 'name', $event.target.value)
							" />
					</label>

					<label class="form-layout__grow">
						<span class="form-layout__label">{{
							t('buildiq', 'What the filer reads')
						}}</span>
						<input
							type="text"
							:value="entry.field.label || ''"
							:placeholder="t('buildiq', 'Rol van de aanvrager')"
							@input="
								write(entry.index, 'label', $event.target.value)
							" />
					</label>

					<label class="form-layout__narrow">
						<span class="form-layout__label">{{
							t('buildiq', 'Type')
						}}</span>
						<select
							:value="entry.field.type || 'string'"
							@change="
								write(entry.index, 'type', $event.target.value)
							">
							<option
								v-for="type in FIELD_TYPES"
								:key="type"
								:value="type">
								{{ type }}
							</option>
						</select>
					</label>

					<label class="form-layout__narrow">
						<span class="form-layout__label">{{
							t('buildiq', 'Section')
						}}</span>
						<select
							:value="entry.field.section || ''"
							@change="assign(entry.index, $event.target.value)">
							<option value="">
								{{ t('buildiq', 'No section') }}
							</option>
							<option
								v-for="s in sections"
								:key="s.name"
								:value="s.name">
								{{ s.label || s.name }}
							</option>
						</select>
					</label>

					<label class="form-layout__inline">
						<input
							type="checkbox"
							:checked="!!entry.field.required"
							@change="
								write(entry.index, 'required', $event.target.checked)
							" />
						{{ t('buildiq', 'Must be answered') }}
					</label>

					<button
						type="button"
						:disabled="position === 0"
						:aria-label="t('buildiq', 'Move field up')"
						:title="t('buildiq', 'Move field up')"
						@click="moveField(group, position, -1)">
						▲
					</button>
					<button
						type="button"
						:disabled="position === group.fields.length - 1"
						:aria-label="t('buildiq', 'Move field down')"
						:title="t('buildiq', 'Move field down')"
						@click="moveField(group, position, 1)">
						▼
					</button>
					<button
						type="button"
						:aria-label="t('buildiq', 'Remove field')"
						:title="t('buildiq', 'Remove field')"
						@click="removeField(entry.index)">
						✕
					</button>
				</div>
			</div>

			<button type="button" @click="addField">
				{{ t('buildiq', 'Add field') }}
			</button>
		</fieldset>
	</div>
</template>

<script>
import { toKebabCase } from '../../../utils/slugPattern.js'

const FIELD_TYPES = ['string', 'number', 'boolean', 'select', 'textarea', 'date']

export default {
	name: 'FormLayoutBuilder',

	props: {
		sections: {
			type: Array,
			default: () => [],
		},

		fields: {
			type: Array,
			default: () => [],
		},

		// The property names the target schema declares, or null when buildiq
		// could not read it. Null means free text rather than an empty picker:
		// an empty picker would look like a schema with no properties.
		properties: {
			type: Array,
			default: null,
		},
	},

	emits: ['update:sections', 'update:fields'],

	data() {
		return { FIELD_TYPES }
	},

	computed: {
		/**
		 * The fields grouped the way the filer meets them: the unsectioned ones
		 * first, then every declared section in its own order.
		 *
		 * @return {Array<object>} Groups carrying their fields and each field's
		 *   index in the flat `fields` array.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		groups() {
			const groups = [
				{
					name: '',
					label: t('buildiq', 'Outside every section'),
					fields: [],
				},
			]

			this.sections.forEach((section) => {
				groups.push({
					name: section.name || '',
					label: section.label || section.name || '',
					fields: [],
				})
			})

			this.fields.forEach((field, index) => {
				const name = (field && field.section) || ''
				const group =
					groups.find((candidate) => candidate.name === name) || groups[0]
				group.fields.push({ field: field || {}, index })
			})

			groups.forEach((group) => {
				group.fields.sort(
					(a, b) => this.orderOf(a.field) - this.orderOf(b.field),
				)
			})

			return groups
		},
	},

	methods: {
		/**
		 * A field's stored order, with an unordered field sorting last rather
		 * than first: a new field belongs at the end of what is already there.
		 *
		 * @param {object} field - the field.
		 * @return {number} Its order.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		orderOf(field) {
			const order = field && field.order
			return typeof order === 'number' ? order : Number.MAX_SAFE_INTEGER
		},

		/**
		 * The fields sitting in one section.
		 *
		 * @param {string} name - the section's reference.
		 * @return {Array<object>} Its fields.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		holds(name) {
			return this.fields.filter(
				(field) => ((field && field.section) || '') === (name || ''),
			)
		},

		/**
		 * Why a section cannot be deleted yet, or the plain delete label.
		 *
		 * @param {object} section - the section.
		 * @return {string} The button title.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		deleteTitle(section) {
			if (this.holds(section.name).length === 0) {
				return t('buildiq', 'Delete section')
			}

			return t(
				'buildiq',
				'Move the fields in this section somewhere else before deleting it.',
			)
		},

		/**
		 * Add one section, named after its heading.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		addSection() {
			const next = this.sections.slice()
			next.push({
				name: this.freeName('sectie'),
				label: '',
				order: next.length + 1,
			})
			this.$emit('update:sections', this.ordered(next))
		},

		/**
		 * Rename a section's heading, and follow it with the reference while the
		 * reference is still the derived one.
		 *
		 * @param {number} index - which section.
		 * @param {string} label - the new heading.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		renameSection(index, label) {
			const next = this.sections.slice()
			const current = next[index] || {}
			const derived = toKebabCase(current.label || '')
			const follows = (current.name || '') === derived || !current.label

			next[index] = { ...current, label }
			if (follows === true) {
				const name = this.freeName(toKebabCase(label) || 'sectie', index)
				next[index] = { ...next[index], name }
				this.$emit('update:fields', this.reassign(current.name || '', name))
			}

			this.$emit('update:sections', this.ordered(next))
		},

		/**
		 * Set a section's reference by hand, carrying its fields with it.
		 *
		 * @param {number} index - which section.
		 * @param {string} raw - what was typed.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		setSectionName(index, raw) {
			const next = this.sections.slice()
			const current = next[index] || {}
			const name = toKebabCase(raw)

			next[index] = { ...current, name }
			this.$emit('update:fields', this.reassign(current.name || '', name))
			this.$emit('update:sections', this.ordered(next))
		},

		/**
		 * Move a section, which moves the block of fields inside it.
		 *
		 * @param {number} index - which section.
		 * @param {number} delta - -1 up, 1 down.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		moveSection(index, delta) {
			const next = this.sections.slice()
			const target = index + delta
			if (target < 0 || target >= next.length) {
				return
			}

			const moved = next.splice(index, 1)[0]
			next.splice(target, 0, moved)
			this.$emit('update:sections', this.ordered(next))
		},

		/**
		 * Delete a section that holds nothing.
		 *
		 * @param {number} index - which section.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		removeSection(index) {
			const section = this.sections[index] || {}
			if (this.holds(section.name).length > 0) {
				return
			}

			const next = this.sections.slice()
			next.splice(index, 1)
			this.$emit('update:sections', this.ordered(next))
		},

		/**
		 * Rewrite `order` as 1..n so the stored order matches the visible one.
		 *
		 * @param {Array<object>} sections - the sections in their new order.
		 * @return {Array<object>} The same sections, renumbered.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		ordered(sections) {
			return sections.map((section, index) => ({
				...section,
				order: index + 1,
			}))
		},

		/**
		 * A section reference nothing else uses.
		 *
		 * @param {string} base - the derived name.
		 * @param {number} skip - a section index to ignore, for a rename.
		 * @return {string} A free name.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		freeName(base, skip = -1) {
			const taken = this.sections
				.filter((_, index) => index !== skip)
				.map((section) => section.name || '')

			if (taken.includes(base) === false) {
				return base
			}

			let suffix = 2
			while (taken.includes(`${base}-${suffix}`) === true) {
				suffix += 1
			}

			return `${base}-${suffix}`
		},

		/**
		 * Carry every field pointing at one section reference over to another.
		 *
		 * @param {string} from - the old reference.
		 * @param {string} to - the new one.
		 * @return {Array<object>} The rewritten fields.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		reassign(from, to) {
			return this.fields.map((field) => {
				if (((field && field.section) || '') !== from || from === '') {
					return field
				}

				return { ...field, section: to }
			})
		},

		/**
		 * Write one property of one field.
		 *
		 * @param {number} index - which field.
		 * @param {string} key - the property.
		 * @param {string|boolean} value - the new value.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		write(index, key, value) {
			const next = this.fields.slice()
			const current = next[index] || {}

			if (value === '' || value === false) {
				const { [key]: _omit, ...rest } = current
				next[index] = key === 'name' ? { ...rest, name: '' } : rest
			} else {
				next[index] = { ...current, [key]: value }
			}

			this.$emit('update:fields', this.renumber(next))
		},

		/**
		 * Move a field into another section, at the end of it.
		 *
		 * @param {number} index - which field.
		 * @param {string} section - the section reference, empty for none.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		assign(index, section) {
			const next = this.fields.slice()
			const current = next[index] || {}

			if (section === '') {
				const { section: _omit, ...rest } = current
				next[index] = { ...rest, order: Number.MAX_SAFE_INTEGER }
			} else {
				next[index] = {
					...current,
					section,
					order: Number.MAX_SAFE_INTEGER,
				}
			}

			this.$emit('update:fields', this.renumber(next))
		},

		/**
		 * Move a field one place inside its own section.
		 *
		 * @param {object} group - the group it sits in.
		 * @param {number} position - its place in that group.
		 * @param {number} delta - -1 up, 1 down.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		moveField(group, position, delta) {
			const target = position + delta
			if (target < 0 || target >= group.fields.length) {
				return
			}

			const order = group.fields.map((entry) => entry.index)
			const moved = order.splice(position, 1)[0]
			order.splice(target, 0, moved)

			const next = this.fields.slice()
			order.forEach((fieldIndex, place) => {
				next[fieldIndex] = { ...next[fieldIndex], order: place + 1 }
			})

			this.$emit('update:fields', next)
		},

		/**
		 * Add an empty field at the end of the unsectioned group.
		 *
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		addField() {
			const next = this.fields.slice()
			next.push({
				name: '',
				label: '',
				type: 'string',
				order: Number.MAX_SAFE_INTEGER,
			})
			this.$emit('update:fields', this.renumber(next))
		},

		/**
		 * Remove one field.
		 *
		 * @param {number} index - which field.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		removeField(index) {
			const next = this.fields.slice()
			next.splice(index, 1)
			this.$emit('update:fields', this.renumber(next))
		},

		/**
		 * Rewrite every field's `order` as 1..n inside its own section, so the
		 * stored order is exactly the visible one.
		 *
		 * @param {Array<object>} fields - the fields.
		 * @return {Array<object>} The same fields, renumbered.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		renumber(fields) {
			const counters = {}
			const indexed = fields.map((field, index) => ({ field, index }))

			indexed.sort((a, b) => this.orderOf(a.field) - this.orderOf(b.field))

			const numbered = fields.slice()
			indexed.forEach((entry) => {
				const section = (entry.field && entry.field.section) || ''
				counters[section] = (counters[section] || 0) + 1
				numbered[entry.index] = {
					...entry.field,
					order: counters[section],
				}
			})

			return numbered
		},
	},
}
</script>

<style scoped>
.form-layout__block {
	margin-block-end: 12px;
}

.form-layout__row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
	padding-block: 4px;
}

.form-layout__grow {
	flex: 1 1 160px;
}

.form-layout__narrow {
	flex: 0 1 140px;
}

.form-layout__label {
	display: block;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.form-layout__inline {
	display: flex;
	gap: 4px;
	align-items: center;
}

.form-layout__group {
	border-inline-start: 2px solid var(--color-border);
	padding-inline-start: 8px;
	margin-block-end: 8px;
}

.form-layout__group-title {
	font-size: 1em;
	margin-block: 4px;
}

.form-layout__note {
	color: var(--color-text-maxcontrast);
}
</style>
