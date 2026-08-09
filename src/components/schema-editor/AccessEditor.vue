<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AccessEditor — authors the schema-level `authorization` block that
  - OpenRegister enforces server-side (REQ-OBDSA-001 .. REQ-OBDSA-008).
  - Follows the `LifecycleEditor.vue` pattern: staged copy owned by the
  - parent view (`SchemaDesigner.vue`), controlled props, `update:access`
  - event, exported pure converters `accessToEditor` / `editorToAccess`.
  -
  - For each of read / create / update / delete the author picks exactly
  - one scope kind: everyone with app access, specific NC groups, own
  - records (the `@creator` sentinel), or a field-value condition. The
  - own-records and condition kinds are offered only when the connected
  - OpenRegister advertises them via `openregister.authorization.scopes`
  - (`useOrAccessCapabilities`) — see design.md Decision 3. Entries the
  - editor cannot represent (unknown sentinels, conditions on an
  - instance without the capability, unrelated top-level keys such as
  - `_note`) are rendered read-only and preserved byte-identical by
  - `editorToAccess` — this is simultaneously the fix for the
  - pre-existing strip-on-save bug (composeSchemaBody() never used to
  - carry `authorization` through at all).
  -->
<template>
	<section class="openbuild-access-editor">
		<header class="openbuild-access-editor__header">
			<h3>{{ t('openbuild', 'Access') }}</h3>
			<p class="openbuild-access-editor__hint">
				{{ t('openbuild', 'Scope who can read, create, update, or delete records of this schema. This is enforced by OpenRegister — it is the actual security boundary, not just navigation hiding.') }}
			</p>
		</header>

		<NcNoteCard v-if="readOnly" type="info">
			{{ t('openbuild', 'Access scopes on the production version can only be changed by an owner.') }}
		</NcNoteCard>

		<ul class="openbuild-access-editor__rows">
			<li
				v-for="row in rows"
				:key="row.op"
				class="openbuild-access-editor__row">
				<h4 class="openbuild-access-editor__row-title">
					{{ opLabel(row.op) }}
				</h4>

				<template v-if="isRepresentable(row)">
					<NcSelect
						:input-label="t('openbuild', 'Scope')"
						:model-value="kindOption(row.kind)"
						:options="kindOptions"
						:clearable="false"
						:disabled="readOnly"
						label="label"
						track-by="value"
						@update:modelValue="onKindChange(row.op, $event ? $event.value : 'everyone')" />

					<NcSelect
						v-if="row.kind === 'group'"
						:input-label="t('openbuild', 'Groups')"
						:model-value="groupOptionsFor(row.groups)"
						:options="availableGroupOptions"
						:multiple="true"
						:taggable="true"
						:disabled="readOnly"
						label="label"
						track-by="value"
						@update:modelValue="onGroupsChange(row.op, $event)"
						@tag="onGroupTag(row.op, $event)" />

					<div v-if="row.kind === 'condition'" class="openbuild-access-editor__condition">
						<NcSelect
							:input-label="t('openbuild', 'Field')"
							:model-value="fieldOption(row.condition && row.condition.field)"
							:options="fieldOptions"
							:clearable="false"
							:disabled="readOnly"
							label="label"
							track-by="value"
							@update:modelValue="onConditionFieldChange(row.op, $event ? $event.value : '')" />
						<NcSelect
							:input-label="t('openbuild', 'Operator')"
							:model-value="{ value: 'equals', label: t('openbuild', 'equals') }"
							:options="[{ value: 'equals', label: t('openbuild', 'equals') }]"
							:clearable="false"
							:disabled="true"
							label="label"
							track-by="value" />
						<NcTextField
							:model-value="(row.condition && row.condition.value) || ''"
							:label="t('openbuild', 'Value (@user.uid or a literal)')"
							:disabled="readOnly"
							@update:modelValue="onConditionValueChange(row.op, $event)" />
					</div>
				</template>

				<template v-else>
					<p class="openbuild-access-editor__managed-note">
						{{ t('openbuild', 'Managed outside the designer — this entry is preserved as-is on save.') }}
					</p>
					<pre class="openbuild-access-editor__managed-raw">{{ rawPreview(row) }}</pre>
				</template>
			</li>
		</ul>

		<div v-if="hasExtraKeys" class="openbuild-access-editor__extra">
			<h4>{{ t('openbuild', 'Additional authorization metadata (managed outside the designer)') }}</h4>
			<pre class="openbuild-access-editor__managed-raw">{{ extraKeysPreview }}</pre>
		</div>
	</section>
</template>

<script>
import { NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

import { useOrAccessCapabilities } from '../../composables/useOrAccessCapabilities.js'

/** Operations authored by the sub-editor, in display order. */
const OPS = ['read', 'create', 'update', 'delete']

/**
 * True when a condition entry has the shape design.md Decision 1 requires:
 * `{ field: <non-empty string>, operator: 'equals', value: <string> }`.
 *
 * @param {*} entry Raw `authorization.conditions.<op>` value.
 * @return {boolean} True when the shape is well-formed.
 */
function isValidCondition(entry) {
	return !!entry
		&& typeof entry === 'object'
		&& typeof entry.field === 'string'
		&& entry.field !== ''
		&& entry.operator === 'equals'
		&& typeof entry.value === 'string'
}

/**
 * True when an array is a plain NC-group-id list: every entry is a
 * string, none of which is the `@creator` sentinel or otherwise
 * `@`-prefixed (reserved for future sentinels).
 *
 * @param {*} list Raw `authorization.<op>` value.
 * @return {boolean} True when the array is a representable group list.
 */
function isGroupList(list) {
	return Array.isArray(list) && list.length > 0 && list.every(
		(v) => typeof v === 'string' && v !== '@creator' && !v.startsWith('@'),
	)
}

/**
 * Parse a single operation's persisted state into an editor row.
 *
 * @param {string} op One of `read`/`create`/`update`/`delete`.
 * @param {object} auth The raw persisted `authorization` object (never null).
 * @return {object} The editor row for this operation.
 */
function parseOpRow(op, auth) {
	const conditionEntry = auth.conditions && auth.conditions[op]
	if (conditionEntry !== undefined) {
		if (isValidCondition(conditionEntry)) {
			return {
				op,
				kind: 'condition',
				condition: {
					field: conditionEntry.field,
					operator: 'equals',
					value: conditionEntry.value,
				},
			}
		}
		return { op, kind: 'unrepresentable', raw: { [op]: auth[op], conditions: conditionEntry } }
	}
	const list = auth[op]
	if (list === undefined) {
		return { op, kind: 'everyone' }
	}
	if (Array.isArray(list) && list.length === 1 && list[0] === '@creator') {
		return { op, kind: 'own' }
	}
	if (isGroupList(list)) {
		return { op, kind: 'group', groups: [...list] }
	}
	return { op, kind: 'unrepresentable', raw: list }
}

/**
 * Convert a persisted `authorization` block into the AccessEditor's
 * editor model. Pure and lossless: anything the editor cannot parse
 * (malformed conditions, mixed/foreign sentinels, non-array values) is
 * kept as an `unrepresentable` row carrying the original raw value so
 * `editorToAccess` can restore it byte-identical; unrelated top-level
 * keys (e.g. hand-authored `_note`) are surfaced in `extraKeys`.
 *
 * @param {object|null|undefined} authorization Persisted schema `authorization` block.
 * @return {{rows: Array<object>, extraKeys: object}} The editor model.
 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-002
 */
export function accessToEditor(authorization) {
	const auth = (authorization && typeof authorization === 'object') ? authorization : {}
	const rows = OPS.map((op) => parseOpRow(op, auth))
	const extraKeys = {}
	Object.keys(auth).forEach((key) => {
		if (!OPS.includes(key) && key !== 'conditions') {
			extraKeys[key] = auth[key]
		}
	})
	return { rows, extraKeys }
}

/**
 * Compile the editor model back into an `authorization` block, merging
 * over the preserved raw block so entries the editor never touched
 * (unrepresentable rows, unrelated top-level keys) survive verbatim.
 * Returns `null` when the compiled result would be an empty object, so
 * `composeSchemaBody()` can omit the `authorization` key entirely.
 *
 * @param {{rows: Array<object>, extraKeys?: object}} access Editor model.
 * @param {object|null|undefined} rawAuthorization The originally-persisted block.
 * @return {object|null} Compiled `authorization` block, or null when empty.
 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-002
 */
export function editorToAccess(access, rawAuthorization) {
	const raw = (rawAuthorization && typeof rawAuthorization === 'object') ? rawAuthorization : {}
	const result = {}
	// Preserve unrelated top-level keys verbatim (e.g. hand-authored `_note`).
	Object.keys(raw).forEach((key) => {
		if (!OPS.includes(key) && key !== 'conditions') {
			result[key] = raw[key]
		}
	})
	const conditions = {}
	const rows = (access && Array.isArray(access.rows)) ? access.rows : []
	rows.forEach((row) => {
		const { op } = row
		if (row.kind === 'unrepresentable') {
			// Preserve exactly what was persisted for this operation.
			if (Object.prototype.hasOwnProperty.call(raw, op)) {
				result[op] = raw[op]
			}
			if (raw.conditions && Object.prototype.hasOwnProperty.call(raw.conditions, op)) {
				conditions[op] = raw.conditions[op]
			}
			return
		}
		if (row.kind === 'everyone') {
			// Omit the key entirely — OR's default is "everyone with app access".
			return
		}
		if (row.kind === 'group') {
			const groups = Array.isArray(row.groups) ? row.groups.filter((g) => !!g) : []
			if (groups.length > 0) {
				result[op] = groups
			}
			return
		}
		if (row.kind === 'own') {
			result[op] = ['@creator']
			return
		}
		if (row.kind === 'condition') {
			result[op] = []
			conditions[op] = {
				field: (row.condition && row.condition.field) || '',
				operator: 'equals',
				value: (row.condition && row.condition.value) || '',
			}
		}
	})
	if (Object.keys(conditions).length > 0) {
		result.conditions = conditions
	}
	return Object.keys(result).length > 0 ? result : null
}

export default {
	name: 'AccessEditor',
	components: {
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	props: {
		access: { type: Object, default: () => ({ rows: [], extraKeys: {} }) },
		fieldNames: { type: Array, default: () => [] },
		availableGroups: { type: Array, default: () => [] },
		readOnly: { type: Boolean, default: false },
	},
	emits: ['update:access'],
	computed: {
		/**
		 * The staged per-operation rows, defaulting to an empty list when
		 * the parent has not yet staged anything.
		 *
		 * @spec openspec/specs/data-scopes-authoring/spec.md#req-obdsa-001
		 * @return {Array<object>} Editor rows.
		 */
		rows() {
			return (this.access && this.access.rows) || []
		},
		/**
		 * Scope kinds the connected OpenRegister advertises
		 * (REQ-OBDSA-003), read once per render via the capability
		 * composable (synchronous — capabilities are preloaded).
		 *
		 * @return {string[]} Advertised scope kinds.
		 */
		capabilityScopes() {
			return useOrAccessCapabilities().scopes
		},
		/**
		 * Scope-kind picker options, filtered to what the connected
		 * OpenRegister can enforce (REQ-OBDSA-003): everyone + groups are
		 * always offered; own-records / condition only when advertised.
		 *
		 * @return {Array<{value: string, label: string}>} Kind options.
		 */
		kindOptions() {
			const options = [
				{ value: 'everyone', label: this.t('openbuild', 'Everyone with app access') },
				{ value: 'group', label: this.t('openbuild', 'Specific groups') },
			]
			if (this.capabilityScopes.includes('creator')) {
				options.push({ value: 'own', label: this.t('openbuild', 'Own records (creator)') })
			}
			if (this.capabilityScopes.includes('condition')) {
				options.push({ value: 'condition', label: this.t('openbuild', 'Condition') })
			}
			return options
		},
		/**
		 * Group picker options seeded from the Application's referenced
		 * groups (design.md Decision 2) — no full group-directory listing.
		 *
		 * @return {Array<{value: string, label: string}>} Group options.
		 */
		availableGroupOptions() {
			return (this.availableGroups || []).map((gid) => ({ value: gid, label: gid }))
		},
		/**
		 * Field picker options for condition rows, sourced from the
		 * staged FieldEditor field names.
		 *
		 * @return {Array<{value: string, label: string}>} Field options.
		 */
		fieldOptions() {
			return (this.fieldNames || []).map((name) => ({ value: name, label: name }))
		},
		/**
		 * Whether any unrelated top-level authorization keys are present.
		 *
		 * @return {boolean} True when `extraKeys` is non-empty.
		 */
		hasExtraKeys() {
			return !!(this.access && this.access.extraKeys && Object.keys(this.access.extraKeys).length > 0)
		},
		/**
		 * Pretty-printed preview of the preserved extra top-level keys.
		 *
		 * @return {string} JSON preview.
		 */
		extraKeysPreview() {
			return JSON.stringify((this.access && this.access.extraKeys) || {}, null, 2)
		},
	},
	methods: {
		/**
		 * Human label for an operation.
		 *
		 * @param {string} op Operation key.
		 * @return {string} Label.
		 */
		opLabel(op) {
			const labels = {
				read: this.t('openbuild', 'Read'),
				create: this.t('openbuild', 'Create'),
				update: this.t('openbuild', 'Update'),
				delete: this.t('openbuild', 'Delete'),
			}
			return labels[op] || op
		},
		/**
		 * Whether a row can be rendered with editable controls: it must
		 * not be genuinely unrepresentable, and its kind must be within
		 * what the connected OR currently supports (REQ-OBDSA-002 /
		 * REQ-OBDSA-003 — a row parsed as `own`/`condition` on an instance
		 * that no longer advertises the capability degrades to read-only
		 * too, so it is never silently rewritten).
		 *
		 * @param {object} row Editor row.
		 * @return {boolean} True when editable controls should render.
		 */
		isRepresentable(row) {
			if (row.kind === 'unrepresentable') {
				return false
			}
			if (row.kind === 'own' && !this.capabilityScopes.includes('creator')) {
				return false
			}
			if (row.kind === 'condition' && !this.capabilityScopes.includes('condition')) {
				return false
			}
			return true
		},
		/**
		 * Pretty-printed preview of a row's preserved raw value.
		 *
		 * @param {object} row Editor row.
		 * @return {string} JSON preview.
		 */
		rawPreview(row) {
			if (row.kind === 'unrepresentable') {
				return JSON.stringify(row.raw, null, 2)
			}
			if (row.kind === 'own') {
				return JSON.stringify(['@creator'], null, 2)
			}
			return JSON.stringify(row.condition || {}, null, 2)
		},
		/**
		 * Resolve the selected kind option for the picker.
		 *
		 * @param {string} kind Row kind.
		 * @return {object|null} Matching option.
		 */
		kindOption(kind) {
			return this.kindOptions.find((o) => o.value === kind) || this.kindOptions[0]
		},
		/**
		 * Resolve the selected group options for a row's group tag input.
		 *
		 * @param {string[]} groups Group ids.
		 * @return {Array<object>} Option objects.
		 */
		groupOptionsFor(groups) {
			return (groups || []).map((gid) => ({ value: gid, label: gid }))
		},
		/**
		 * Resolve the selected field option for a condition row.
		 *
		 * @param {string} name Field name.
		 * @return {object|null} Matching option.
		 */
		fieldOption(name) {
			return this.fieldOptions.find((o) => o.value === name) || null
		},
		/**
		 * Emit an updated `access` model with one row replaced.
		 *
		 * @param {string} op Operation key.
		 * @param {object} nextRow Replacement row.
		 * @return {void}
		 */
		emitRowChange(op, nextRow) {
			const nextRows = this.rows.map((r) => (r.op === op ? nextRow : r))
			this.$emit('update:access', { ...this.access, rows: nextRows })
		},
		/**
		 * Switch a row's scope kind, resetting to sensible defaults.
		 *
		 * @param {string} op Operation key.
		 * @param {string} kind New scope kind.
		 * @return {void}
		 */
		onKindChange(op, kind) {
			if (kind === 'group') {
				this.emitRowChange(op, { op, kind: 'group', groups: [] })
			} else if (kind === 'own') {
				this.emitRowChange(op, { op, kind: 'own' })
			} else if (kind === 'condition') {
				this.emitRowChange(op, { op, kind: 'condition', condition: { field: '', operator: 'equals', value: '' } })
			} else {
				this.emitRowChange(op, { op, kind: 'everyone' })
			}
		},
		/**
		 * Apply a full-replace groups selection (NcSelect `multiple` input).
		 *
		 * @param {string} op Operation key.
		 * @param {Array<object>|null} options Selected option objects.
		 * @return {void}
		 */
		onGroupsChange(op, options) {
			// A taggable NcSelect emits BOTH `@tag` (with the raw typed string)
			// and `@input` (with the new selection). In that `@input` payload the
			// freshly created entry is the raw STRING, not a `{ value, label }`
			// option — so mapping `o.value` blindly turned every newly typed group
			// into `undefined`, which persisted as `null`. The `@tag` handler had
			// already stored the right value; this then clobbered it. Accept both
			// shapes and drop anything empty.
			const groups = Array.isArray(options)
				? options
					.map((o) => (typeof o === 'string' ? o : o?.value))
					.filter((g) => g !== undefined && g !== null && g !== '')
				: []
			this.emitRowChange(op, { op, kind: 'group', groups })
		},
		/**
		 * Append a free-entry group tag (NcSelect `taggable`).
		 *
		 * @param {string} op Operation key.
		 * @param {string} tag Free-typed group id.
		 * @return {void}
		 */
		onGroupTag(op, tag) {
			if (!tag) {
				return
			}
			const row = this.rows.find((r) => r.op === op)
			const groups = [...((row && row.groups) || []), tag]
			this.emitRowChange(op, { op, kind: 'group', groups })
		},
		/**
		 * Update the condition field for a condition row.
		 *
		 * @param {string} op Operation key.
		 * @param {string} field Field name.
		 * @return {void}
		 */
		onConditionFieldChange(op, field) {
			const row = this.rows.find((r) => r.op === op)
			const condition = { ...(row && row.condition), field, operator: 'equals' }
			this.emitRowChange(op, { op, kind: 'condition', condition })
		},
		/**
		 * Update the condition value for a condition row.
		 *
		 * @param {string} op Operation key.
		 * @param {string} value Condition value (`@user.uid` token or literal).
		 * @return {void}
		 */
		onConditionValueChange(op, value) {
			const row = this.rows.find((r) => r.op === op)
			const condition = { ...(row && row.condition), operator: 'equals', value }
			this.emitRowChange(op, { op, kind: 'condition', condition })
		},
	},
}
</script>

<style scoped>
.openbuild-access-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.openbuild-access-editor__header h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 600;
}

.openbuild-access-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.openbuild-access-editor__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.openbuild-access-editor__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-access-editor__row-title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.openbuild-access-editor__condition {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 8px;
}

.openbuild-access-editor__managed-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.openbuild-access-editor__managed-raw {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 12px;
	overflow-x: auto;
}

.openbuild-access-editor__extra {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.openbuild-access-editor__extra h4 {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
}
</style>
