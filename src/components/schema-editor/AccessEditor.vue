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
	<section class="buildiq-access-editor">
		<header class="buildiq-access-editor__header">
			<h3>{{ t('buildiq', 'Access') }}</h3>
			<p class="buildiq-access-editor__hint">
				{{
					t(
						'buildiq',
						'Choose who can read, create, update or delete records of this schema. OpenRegister enforces this on every request, not only in the menu.',
					)
				}}
			</p>
		</header>

		<NcNoteCard v-if="readOnly" type="info">
			{{
				t(
					'buildiq',
					'Access scopes on the production version can only be changed by an owner.',
				)
			}}
		</NcNoteCard>

		<ul class="buildiq-access-editor__rows">
			<li v-for="row in rows" :key="row.op" class="buildiq-access-editor__row">
				<h4 class="buildiq-access-editor__row-title">
					{{ opLabel(row.op) }}
				</h4>

				<template v-if="isRepresentable(row)">
					<NcSelect
						:inputLabel="t('buildiq', 'Scope')"
						:modelValue="kindOption(row.kind)"
						:options="kindOptions"
						:clearable="false"
						:disabled="readOnly"
						label="label"
						trackBy="value"
						@update:modelValue="
							onKindChange(row.op, $event ? $event.value : 'everyone')
						" />

					<NcSelect
						v-if="row.kind === 'group'"
						:inputLabel="t('buildiq', 'Groups')"
						:modelValue="groupOptionsFor(row.groups)"
						:options="availableGroupOptions"
						:multiple="true"
						:taggable="true"
						:disabled="readOnly"
						label="label"
						trackBy="value"
						@update:modelValue="onGroupsChange(row.op, $event)"
						@tag="onGroupTag(row.op, $event)" />

					<div
						v-if="row.kind === 'condition'"
						class="buildiq-access-editor__condition">
						<NcSelect
							:inputLabel="t('buildiq', 'Field')"
							:modelValue="
								fieldOption(row.condition && row.condition.field)
							"
							:options="fieldOptions"
							:clearable="false"
							:disabled="readOnly"
							label="label"
							trackBy="value"
							@update:modelValue="
								onConditionFieldChange(
									row.op,
									$event ? $event.value : '',
								)
							" />
						<NcSelect
							:inputLabel="t('buildiq', 'Operator')"
							:modelValue="{
								value: 'equals',
								label: t('buildiq', 'equals'),
							}"
							:options="[
								{ value: 'equals', label: t('buildiq', 'equals') },
							]"
							:clearable="false"
							:disabled="true"
							label="label"
							trackBy="value" />
						<NcTextField
							:modelValue="
								(row.condition && row.condition.value) || ''
							"
							:label="t('buildiq', 'Value (@user.uid or a literal)')"
							:disabled="readOnly"
							@update:modelValue="
								onConditionValueChange(row.op, $event)
							" />
					</div>
				</template>

				<template v-else>
					<p class="buildiq-access-editor__managed-note">
						{{
							t(
								'buildiq',
								'Set outside the designer. Saving keeps this entry as it is.',
							)
						}}
					</p>
					<pre class="buildiq-access-editor__managed-raw">{{
						rawPreview(row)
					}}</pre>
				</template>
			</li>
		</ul>

		<div v-if="hasExtraKeys" class="buildiq-access-editor__extra">
			<h4>
				{{
					t(
						'buildiq',
						'Additional authorization metadata (managed outside the designer)',
					)
				}}
			</h4>
			<pre class="buildiq-access-editor__managed-raw">{{
				extraKeysPreview
			}}</pre>
		</div>
	</section>
</template>

<script>
import { NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { useOrAccessCapabilities } from '../../composables/useOrAccessCapabilities.js'

/** Operations authored by the sub-editor, in display order. */
const OPS = ['read', 'create', 'update', 'delete']

/**
 * The value itself when it is an object, else an empty object.
 *
 * @param {object|Array|string|number|boolean|null|undefined} value A raw `authorization` block.
 * @return {object} The block, or `{}`.
 */
function asObject(value) {
	return value && typeof value === 'object' ? value : {}
}

/**
 * The top-level keys of an `authorization` block that are neither an
 * operation nor `conditions` (e.g. hand-authored `_note`), in key order.
 *
 * @param {object} auth The `authorization` block.
 * @return {object} The unrelated keys with their values.
 */
function extraKeysOf(auth) {
	const extraKeys = {}
	Object.keys(auth).forEach((key) => {
		if (!OPS.includes(key) && key !== 'conditions') {
			extraKeys[key] = auth[key]
		}
	})
	return extraKeys
}

/**
 * True when a condition entry is an object naming a non-empty field.
 *
 * @param {object|Array|string|number|boolean|null|undefined} entry Raw `authorization.conditions.<op>` value.
 * @return {boolean} True when the entry names a field.
 */
function namesField(entry) {
	return (
		!!entry
		&& typeof entry === 'object'
		&& typeof entry.field === 'string'
		&& entry.field !== ''
	)
}

/**
 * True when a condition entry has the shape design.md Decision 1 requires:
 * `{ field: <non-empty string>, operator: 'equals', value: <string> }`.
 *
 * @param {object|Array|string|number|boolean|null|undefined} entry Raw `authorization.conditions.<op>` value.
 * @return {boolean} True when the shape is well-formed.
 */
function isValidCondition(entry) {
	return (
		namesField(entry)
		&& entry.operator === 'equals'
		&& typeof entry.value === 'string'
	)
}

/**
 * True when an array is a plain NC-group-id list: every entry is a
 * string, none of which is the `@creator` sentinel or otherwise
 * `@`-prefixed (reserved for future sentinels).
 *
 * @param {object|Array|string|number|boolean|null|undefined} list Raw `authorization.<op>` value.
 * @return {boolean} True when the array is a representable group list.
 */
function isGroupList(list) {
	return (
		Array.isArray(list)
		&& list.length > 0
		&& list.every(
			(v) => typeof v === 'string' && v !== '@creator' && !v.startsWith('@'),
		)
	)
}

/**
 * True when an operation list is exactly the own-records sentinel.
 *
 * @param {object|Array|string|number|boolean|null|undefined} list Raw `authorization.<op>` value.
 * @return {boolean} True for `['@creator']`.
 */
function isOwnList(list) {
	return Array.isArray(list) && list.length === 1 && list[0] === '@creator'
}

/**
 * The editor's copy of a well-formed condition entry.
 *
 * @param {{field: string, value: string}} entry A valid condition entry.
 * @return {{field: string, operator: string, value: string}} The condition.
 */
function pickCondition(entry) {
	return { field: entry.field, operator: 'equals', value: entry.value }
}

/**
 * Parse an operation that has a condition entry into an editor row.
 *
 * @param {string} op One of `read`/`create`/`update`/`delete`.
 * @param {object} auth The raw persisted `authorization` object (never null).
 * @return {object} A `condition` row, or an `unrepresentable` one.
 */
function parseConditionRow(op, auth) {
	const conditionEntry = auth.conditions && auth.conditions[op]
	if (isValidCondition(conditionEntry)) {
		return { op, kind: 'condition', condition: pickCondition(conditionEntry) }
	}
	return {
		op,
		kind: 'unrepresentable',
		raw: { [op]: auth[op], conditions: conditionEntry },
	}
}

/**
 * Parse an operation's group list into an editor row.
 *
 * @param {string} op One of `read`/`create`/`update`/`delete`.
 * @param {object|Array|string|number|boolean|null|undefined} list Raw `authorization.<op>` value.
 * @return {object} The editor row for this operation.
 */
function parseListRow(op, list) {
	if (list === undefined) {
		return { op, kind: 'everyone' }
	}
	if (isOwnList(list)) {
		return { op, kind: 'own' }
	}
	if (isGroupList(list)) {
		return { op, kind: 'group', groups: [...list] }
	}
	return { op, kind: 'unrepresentable', raw: list }
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
		return parseConditionRow(op, auth)
	}
	return parseListRow(op, auth[op])
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
	const auth = asObject(authorization)
	const rows = OPS.map((op) => parseOpRow(op, auth))
	return { rows, extraKeys: extraKeysOf(auth) }
}

/**
 * Restore an unrepresentable row exactly as it was persisted.
 *
 * @param {object} row The editor row.
 * @param {{raw: object, result: object, conditions: object}} compiled The block being compiled.
 * @return {void}
 */
function compileUnrepresentable(row, compiled) {
	const { op } = row
	const { raw } = compiled
	if (Object.hasOwn(raw, op)) {
		compiled.result[op] = raw[op]
	}
	if (raw.conditions && Object.hasOwn(raw.conditions, op)) {
		compiled.conditions[op] = raw.conditions[op]
	}
}

/**
 * Compile a group row; an empty group list leaves the operation open.
 *
 * @param {object} row The editor row.
 * @param {{raw: object, result: object, conditions: object}} compiled The block being compiled.
 * @return {void}
 */
function compileGroup(row, compiled) {
	const groups = Array.isArray(row.groups) ? row.groups.filter((g) => !!g) : []
	if (groups.length > 0) {
		compiled.result[row.op] = groups
	}
}

/**
 * Compile an own-records row.
 *
 * @param {object} row The editor row.
 * @param {{raw: object, result: object, conditions: object}} compiled The block being compiled.
 * @return {void}
 */
function compileOwn(row, compiled) {
	compiled.result[row.op] = ['@creator']
}

/**
 * Compile a condition row: an empty list plus the condition entry.
 *
 * @param {object} row The editor row.
 * @param {{raw: object, result: object, conditions: object}} compiled The block being compiled.
 * @return {void}
 */
function compileCondition(row, compiled) {
	const condition = row.condition || {}
	compiled.result[row.op] = []
	compiled.conditions[row.op] = {
		field: condition.field || '',
		operator: 'equals',
		value: condition.value || '',
	}
}

/**
 * Row compilers by row kind. `everyone` has none: its key is omitted, as
 * OR's default is "everyone with app access".
 */
const ROW_COMPILERS = new Map([
	['unrepresentable', compileUnrepresentable],
	['group', compileGroup],
	['own', compileOwn],
	['condition', compileCondition],
])

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
	const raw = asObject(rawAuthorization)
	// Preserve unrelated top-level keys verbatim (e.g. hand-authored `_note`).
	const compiled = { raw, result: extraKeysOf(raw), conditions: {} }
	const rows = access && Array.isArray(access.rows) ? access.rows : []
	rows.forEach((row) => ROW_COMPILERS.get(row.kind)?.(row, compiled))
	if (Object.keys(compiled.conditions).length > 0) {
		compiled.result.conditions = compiled.conditions
	}
	return Object.keys(compiled.result).length > 0 ? compiled.result : null
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
		 *
		 * @spec openspec/specs/data-scopes-authoring/spec.md
		 */
		kindOptions() {
			const options = [
				{
					value: 'everyone',
					label: this.t('buildiq', 'Everyone with app access'),
				},
				{ value: 'group', label: this.t('buildiq', 'Specific groups') },
			]
			if (this.capabilityScopes.includes('creator')) {
				options.push({
					value: 'own',
					label: this.t('buildiq', 'Own records (creator)'),
				})
			}
			if (this.capabilityScopes.includes('condition')) {
				options.push({
					value: 'condition',
					label: this.t('buildiq', 'Condition'),
				})
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
			return (this.availableGroups || []).map((gid) => ({
				value: gid,
				label: gid,
			}))
		},

		/**
		 * Field picker options for condition rows, sourced from the
		 * staged FieldEditor field names.
		 *
		 * @return {Array<{value: string, label: string}>} Field options.
		 */
		fieldOptions() {
			return (this.fieldNames || []).map((name) => ({
				value: name,
				label: name,
			}))
		},

		/**
		 * Whether any unrelated top-level authorization keys are present.
		 *
		 * @return {boolean} True when `extraKeys` is non-empty.
		 */
		hasExtraKeys() {
			return !!(
				this.access
				&& this.access.extraKeys
				&& Object.keys(this.access.extraKeys).length > 0
			)
		},

		/**
		 * Pretty-printed preview of the preserved extra top-level keys.
		 *
		 * @return {string} JSON preview.
		 */
		extraKeysPreview() {
			return JSON.stringify(
				(this.access && this.access.extraKeys) || {},
				null,
				2,
			)
		},
	},

	methods: {
		/**
		 * Human label for an operation.
		 *
		 * @param {string} op Operation key.
		 * @return {string} Label.
		 *
		 * @spec openspec/specs/data-scopes-authoring/spec.md
		 */
		opLabel(op) {
			const labels = {
				read: this.t('buildiq', 'Read'),
				create: this.t('buildiq', 'Create'),
				update: this.t('buildiq', 'Update'),
				delete: this.t('buildiq', 'Delete'),
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
			if (
				row.kind === 'condition'
				&& !this.capabilityScopes.includes('condition')
			) {
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
			return (
				this.kindOptions.find((o) => o.value === kind) || this.kindOptions[0]
			)
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
				this.emitRowChange(op, {
					op,
					kind: 'condition',
					condition: { field: '', operator: 'equals', value: '' },
				})
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
			const condition = {
				...(row && row.condition),
				field,
				operator: 'equals',
			}
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
			const condition = {
				...(row && row.condition),
				operator: 'equals',
				value,
			}
			this.emitRowChange(op, { op, kind: 'condition', condition })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.buildiq-access-editor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.buildiq-access-editor__header h3 {
	margin: 0 0 4px;
	font-size: 18px;
	font-weight: 600;
}

.buildiq-access-editor__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.buildiq-access-editor__rows {
	@include c.plain-list(12px);
}

.buildiq-access-editor__row {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	background: var(--color-main-background);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.buildiq-access-editor__row-title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.buildiq-access-editor__condition {
	display: grid;
	grid-template-columns: 1fr 1fr 1fr;
	gap: 8px;
}

.buildiq-access-editor__managed-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.buildiq-access-editor__managed-raw {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 12px;
	overflow-x: auto;
}

.buildiq-access-editor__extra {
	border-top: 1px solid var(--color-border);
	padding-top: 12px;
}

.buildiq-access-editor__extra h4 {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
}
</style>
