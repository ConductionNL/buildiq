<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageLayoutBodyEditor: what one page layout shows. The tabs with their
  - kind and order, the widgets of a widget tab with a width, an order,
  - display conditions and a high-contrast flag, the header, the task-list
  - columns and search fields, and the upload fields. Every shape is the
  - pageLayout schema in lib/Settings/register.d/51-page-layouts.json.
  -
  - Order is changed with move buttons rather than by dragging, so a keyboard
  - and a screen reader can do it as well as a mouse (WCAG 2.5.7).
  -
  - @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-004 to REQ-OBPL-009)
  -->
<template>
	<div class="layout-body">
		<fieldset class="layout-body__section">
			<legend>{{ t('buildiq', 'Tabs') }}</legend>
			<ol class="layout-body__list">
				<li
					v-for="(tab, ti) in tabs"
					:key="tab.id"
					class="layout-body__item"
					:data-tab="tab.id">
					<div class="layout-body__row">
						<label>
							{{ t('buildiq', 'Label') }}
							<input
								type="text"
								:value="tab.label || ''"
								@input="onTab(ti, 'label', $event.target.value)" />
						</label>
						<span class="layout-body__kind">{{
							kindLabel(tab.kind)
						}}</span>
						<button
							type="button"
							:disabled="ti === 0"
							:aria-label="
								t('buildiq', 'Move {name} up', {
									name: tab.label || tab.id,
								})
							"
							@click="onMoveTab(ti, -1)">
							{{ t('buildiq', 'Up') }}
						</button>
						<button
							type="button"
							:disabled="ti === tabs.length - 1"
							:aria-label="
								t('buildiq', 'Move {name} down', {
									name: tab.label || tab.id,
								})
							"
							@click="onMoveTab(ti, 1)">
							{{ t('buildiq', 'Down') }}
						</button>
						<button
							type="button"
							:aria-label="
								t('buildiq', 'Remove {name}', {
									name: tab.label || tab.id,
								})
							"
							@click="onRemoveTab(ti)">
							{{ t('buildiq', 'Remove') }}
						</button>
					</div>

					<label v-if="tab.kind === 'leaf'">
						{{ t('buildiq', 'Leaf id') }}
						<input
							type="text"
							:value="tab.ref || ''"
							placeholder="filinq-documents"
							@input="onTab(ti, 'ref', $event.target.value)" />
					</label>
					<label v-else-if="tab.kind === 'fieldGroup'">
						{{ t('buildiq', 'Fields, separated by commas') }}
						<input
							type="text"
							:value="(tab.fields || []).join(', ')"
							@input="
								onTab(ti, 'fields', splitList($event.target.value))
							" />
					</label>
					<div
						v-else-if="tab.kind === 'relatedList'"
						class="layout-body__row">
						<label>
							{{ t('buildiq', 'Related register') }}
							<input
								type="text"
								:value="tab.relatedRegister || ''"
								@input="
									onTab(ti, 'relatedRegister', $event.target.value)
								" />
						</label>
						<label>
							{{ t('buildiq', 'Related schema') }}
							<input
								type="text"
								:value="tab.relatedSchema || ''"
								@input="
									onTab(ti, 'relatedSchema', $event.target.value)
								" />
						</label>
					</div>
					<p
						v-if="missingReference(tab)"
						class="layout-body__warn"
						role="alert">
						{{ missingText(tab) }}
					</p>

					<div v-if="tab.kind === 'widgets'" class="layout-body__widgets">
						<ol class="layout-body__list">
							<li
								v-for="(widget, wi) in tab.widgets || []"
								:key="widget.id"
								class="layout-body__item">
								<div class="layout-body__row">
									<label>
										{{ t('buildiq', 'Widget id') }}
										<input
											type="text"
											:value="widget.id"
											@input="
												onWidget(
													ti,
													wi,
													'id',
													$event.target.value,
												)
											" />
									</label>
									<label>
										{{ t('buildiq', 'Width') }}
										<select
											:value="widget.width || 'medium'"
											@change="
												onWidget(
													ti,
													wi,
													'width',
													$event.target.value,
												)
											">
											<option
												v-for="w in widths"
												:key="w"
												:value="w">
												{{ widthLabel(w) }}
											</option>
										</select>
									</label>
									<label class="layout-body__inline">
										<input
											type="checkbox"
											:checked="widget.highContrast === true"
											@change="
												onWidget(
													ti,
													wi,
													'highContrast',
													$event.target.checked,
												)
											" />
										{{ t('buildiq', 'High contrast') }}
									</label>
									<button
										type="button"
										:disabled="wi === 0"
										:aria-label="
											t('buildiq', 'Move {name} up', {
												name: widget.id,
											})
										"
										@click="onMoveWidget(ti, wi, -1)">
										{{ t('buildiq', 'Up') }}
									</button>
									<button
										type="button"
										:disabled="wi === tab.widgets.length - 1"
										:aria-label="
											t('buildiq', 'Move {name} down', {
												name: widget.id,
											})
										"
										@click="onMoveWidget(ti, wi, 1)">
										{{ t('buildiq', 'Down') }}
									</button>
									<button
										type="button"
										:aria-label="
											t('buildiq', 'Remove {name}', {
												name: widget.id,
											})
										"
										@click="onRemoveWidget(ti, wi)">
										{{ t('buildiq', 'Remove') }}
									</button>
								</div>
								<div
									v-for="(condition, ci) in widget.conditions
									|| []"
									:key="ci"
									class="layout-body__row">
									<label>
										{{ t('buildiq', 'Show when field') }}
										<input
											type="text"
											:value="condition.field"
											@input="
												onCondition(ti, wi, ci, {
													...condition,
													field: $event.target.value,
												})
											" />
									</label>
									<label>
										{{ t('buildiq', 'Condition') }}
										<select
											:value="condition.operator"
											@change="
												onCondition(ti, wi, ci, {
													...condition,
													operator: $event.target.value,
												})
											">
											<option
												v-for="op in operators"
												:key="op"
												:value="op">
												{{ operatorLabel(op) }}
											</option>
										</select>
									</label>
									<label
										v-if="
											!['isEmpty', 'isNotEmpty'].includes(
												condition.operator,
											)
										">
										{{ t('buildiq', 'Value') }}
										<input
											type="text"
											:value="condition.value || ''"
											@input="
												onCondition(ti, wi, ci, {
													...condition,
													value: $event.target.value,
												})
											" />
									</label>
									<button
										type="button"
										@click="onRemoveCondition(ti, wi, ci)">
										{{ t('buildiq', 'Remove condition') }}
									</button>
								</div>
								<button
									type="button"
									@click="onAddCondition(ti, wi)">
									{{ t('buildiq', 'Add condition') }}
								</button>
							</li>
						</ol>
						<button type="button" @click="onAddWidget(ti)">
							{{ t('buildiq', 'Add widget') }}
						</button>
						<div
							class="layout-body__preview"
							:aria-label="t('buildiq', 'Preview of the widget rows')">
							<div
								v-for="(row, ri) in previewRows(tab.widgets || [])"
								:key="ri"
								class="layout-body__preview-row">
								<span
									v-for="widget in row"
									:key="widget.id"
									class="layout-body__preview-cell"
									:style="{ flexGrow: widthUnits(widget.width) }">
									{{ widget.id }}
								</span>
							</div>
						</div>
					</div>
				</li>
			</ol>
			<div class="layout-body__row">
				<label>
					{{ t('buildiq', 'Kind of tab') }}
					<select
						:value="newTabKind"
						@change="newTabKind = $event.target.value">
						<option v-for="kind in kinds" :key="kind" :value="kind">
							{{ kindLabel(kind) }}
						</option>
					</select>
				</label>
				<button type="button" @click="onAddTab">
					{{ t('buildiq', 'Add tab') }}
				</button>
			</div>
		</fieldset>

		<fieldset class="layout-body__section">
			<legend>{{ t('buildiq', 'Header') }}</legend>
			<div class="layout-body__row">
				<label>
					{{ t('buildiq', 'Title field') }}
					<input
						type="text"
						:value="header.titleField || ''"
						@input="onHeader('titleField', $event.target.value)" />
				</label>
				<label>
					{{ t('buildiq', 'Subtitle field') }}
					<input
						type="text"
						:value="header.subtitleField || ''"
						@input="onHeader('subtitleField', $event.target.value)" />
				</label>
				<label>
					{{ t('buildiq', 'Chips, separated by commas') }}
					<input
						type="text"
						:value="(header.chips || []).join(', ')"
						@input="onHeader('chips', splitList($event.target.value))" />
				</label>
			</div>
			<ol class="layout-body__list">
				<li
					v-for="(item, hi) in header.fields || []"
					:key="item.field + hi"
					class="layout-body__row">
					<span>{{ item.field }}</span>
					<button
						type="button"
						:disabled="hi === 0"
						:aria-label="
							t('buildiq', 'Move {name} up', { name: item.field })
						"
						@click="onMoveHeaderField(hi, -1)">
						{{ t('buildiq', 'Up') }}
					</button>
					<button
						type="button"
						:aria-label="
							t('buildiq', 'Remove {name}', { name: item.field })
						"
						@click="onRemoveHeaderField(hi)">
						{{ t('buildiq', 'Remove') }}
					</button>
				</li>
			</ol>
			<div class="layout-body__row">
				<label>
					{{ t('buildiq', 'Header field') }}
					<input v-model="newHeaderField" type="text" />
				</label>
				<button
					type="button"
					:disabled="!newHeaderField"
					@click="onAddHeaderField(newHeaderField)">
					{{ t('buildiq', 'Add to header') }}
				</button>
			</div>
		</fieldset>

		<fieldset class="layout-body__section">
			<legend>{{ t('buildiq', 'Task list') }}</legend>
			<ul class="layout-body__list">
				<li
					v-for="(column, ci) in taskList.columns || []"
					:key="'c' + ci"
					class="layout-body__row">
					<span>{{
						t('buildiq', 'Column: {field}', { field: column.field })
					}}</span>
					<button type="button" @click="onRemoveColumn(ci)">
						{{ t('buildiq', 'Remove') }}
					</button>
				</li>
				<li
					v-for="(search, si) in taskList.searchFields || []"
					:key="'s' + si"
					class="layout-body__row">
					<span>{{
						t('buildiq', 'Search on: {field}', { field: search.field })
					}}</span>
					<button type="button" @click="onRemoveSearchField(si)">
						{{ t('buildiq', 'Remove') }}
					</button>
				</li>
			</ul>
			<div class="layout-body__row">
				<label>
					{{ t('buildiq', 'Field') }}
					<input v-model="newTaskField" type="text" />
				</label>
				<button
					type="button"
					:disabled="!newTaskField"
					@click="onAddColumn(newTaskField)">
					{{ t('buildiq', 'Add column') }}
				</button>
				<button
					type="button"
					:disabled="!newTaskField"
					@click="onAddSearchField(newTaskField)">
					{{ t('buildiq', 'Add search field') }}
				</button>
			</div>
		</fieldset>

		<fieldset class="layout-body__section">
			<legend>{{ t('buildiq', 'Upload fields') }}</legend>
			<ul class="layout-body__list">
				<li
					v-for="(upload, ui) in uploadFields"
					:key="upload.field + ui"
					class="layout-body__item"
					:data-upload="upload.field">
					<div class="layout-body__row">
						<span>{{ upload.field }}</span>
						<label>
							{{ t('buildiq', 'Visibility') }}
							<select
								:value="upload.visibility || 'editable'"
								@change="
									onUploadField(
										ui,
										'visibility',
										$event.target.value,
									)
								">
								<option value="editable">
									{{ t('buildiq', 'Editable') }}
								</option>
								<option value="readOnly">
									{{ t('buildiq', 'Read only') }}
								</option>
								<option value="hidden">
									{{ t('buildiq', 'Hidden') }}
								</option>
							</select>
						</label>
						<label>
							{{ t('buildiq', 'Default') }}
							<input
								type="text"
								:value="upload.default || ''"
								@input="
									onUploadField(ui, 'default', $event.target.value)
								" />
						</label>
						<button type="button" @click="onRemoveUploadField(ui)">
							{{ t('buildiq', 'Remove') }}
						</button>
					</div>
					<p
						v-if="hiddenWithoutDefault([upload]).length > 0"
						class="layout-body__warn"
						role="alert">
						{{
							t(
								'buildiq',
								'A hidden field needs a default, or it can never be filled.',
							)
						}}
					</p>
				</li>
			</ul>
			<div class="layout-body__row">
				<label>
					{{ t('buildiq', 'Upload field') }}
					<input v-model="newUploadField" type="text" />
				</label>
				<button
					type="button"
					:disabled="!newUploadField"
					@click="onAddUploadField(newUploadField)">
					{{ t('buildiq', 'Add upload field') }}
				</button>
			</div>
		</fieldset>
	</div>
</template>

<script>
/** The tab kinds the pageLayout schema allows. */
export const TAB_KINDS = ['leaf', 'widgets', 'fieldGroup', 'relatedList']

/** The widget widths the pageLayout schema allows. */
export const WIDTHS = ['small', 'medium', 'large', 'extraLarge']

/** The condition operators the pageLayout schema allows. */
export const CONDITION_OPERATORS = [
	'equals',
	'notEquals',
	'isEmpty',
	'isNotEmpty',
	'contains',
]

/** Grid units per width, out of four in a row. */
const UNITS = { small: 1, medium: 2, large: 3, extraLarge: 4 }

/**
 * A fresh id that no item in the list carries yet.
 *
 * @param {string} prefix The id prefix.
 * @param {Array<{id: string}>} items The existing items.
 * @return {string} The id.
 */
function freshId(prefix, items) {
	let n = items.length + 1
	while (items.some((item) => item.id === `${prefix}-${n}`)) {
		n++
	}
	return `${prefix}-${n}`
}

/**
 * Renumber `order` to the position in the list.
 *
 * @param {Array<object>} items The items.
 * @return {Array<object>} New items with `order`.
 */
function renumber(items) {
	return items.map((item, order) => ({ ...item, order }))
}

/**
 * Add a tab of a kind with the empty reference its kind needs.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
 * @param {Array<object>} tabs The tabs.
 * @param {string} kind The kind.
 * @return {Array<object>} The new tabs.
 */
export function addTab(tabs, kind) {
	const list = tabs || []
	const tab = { id: freshId('tab', list), kind, label: '', order: list.length }
	if (kind === 'widgets') tab.widgets = []
	if (kind === 'fieldGroup') tab.fields = []
	if (kind === 'leaf') tab.ref = ''
	if (kind === 'relatedList') {
		tab.relatedRegister = ''
		tab.relatedSchema = ''
	}
	return [...list, tab]
}

/**
 * Move one item and renumber the order.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
 * @param {Array<object>} items The items.
 * @param {number} index The item to move.
 * @param {number} delta How far, negative is up.
 * @return {Array<object>} The new items.
 */
export function moveItem(items, index, delta) {
	const list = [...(items || [])]
	const target = Math.max(0, Math.min(list.length - 1, index + delta))
	const [item] = list.splice(index, 1)
	list.splice(target, 0, item)
	return renumber(list)
}

/**
 * The reference a tab's kind needs and does not have, or an empty string.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
 * @param {object} tab The tab.
 * @return {string} The missing key.
 */
export function missingReference(tab) {
	switch (tab?.kind) {
		case 'leaf':
			return tab.ref ? '' : 'ref'
		case 'fieldGroup':
			return (tab.fields || []).length > 0 ? '' : 'fields'
		case 'relatedList':
			if (!tab.relatedRegister) return 'relatedRegister'
			return tab.relatedSchema ? '' : 'relatedSchema'
		default:
			return ''
	}
}

/**
 * Break widgets into the rows a four-unit grid draws, in their order.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-tab-holds-a-grid-each-widget-with-a-width-and-an-order-req-obpl-005
 * @param {Array<object>} widgets The widgets.
 * @return {Array<Array<object>>} The rows.
 */
export function previewRows(widgets) {
	const sorted = [...(widgets || [])].sort(
		(a, b) => (a.order ?? 0) - (b.order ?? 0),
	)
	const rows = []
	let row = []
	let used = 0
	for (const widget of sorted) {
		const units = UNITS[widget.width] || UNITS.medium
		if (used + units > 4 && row.length > 0) {
			rows.push(row)
			row = []
			used = 0
		}
		row.push(widget)
		used += units
	}
	if (row.length > 0) rows.push(row)
	return rows
}

/**
 * The hidden upload fields that have no default.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
 * @param {Array<object>} fields The upload fields.
 * @return {Array<string>} Their field names.
 */
export function hiddenWithoutDefault(fields) {
	return (fields || [])
		.filter((f) => f.visibility === 'hidden' && (f.default ?? '') === '')
		.map((f) => f.field)
}

/**
 * Whether a layout body has something the server will refuse.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
 * @param {object} layout The layout.
 * @return {boolean} True when it is blocked.
 */
export function layoutBlocked(layout) {
	const tabs = (layout && layout.tabs) || []
	return (
		tabs.some((tab) => missingReference(tab) !== '')
		|| hiddenWithoutDefault((layout && layout.uploadFields) || []).length > 0
	)
}

export default {
	name: 'PageLayoutBodyEditor',

	props: {
		// The whole layout. Only tabs, header, taskList and uploadFields are
		// changed here; every other key is passed back as it came.
		modelValue: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			newTabKind: 'fieldGroup',
			newHeaderField: '',
			newTaskField: '',
			newUploadField: '',
			kinds: TAB_KINDS,
			widths: WIDTHS,
			operators: CONDITION_OPERATORS,
		}
	},

	computed: {
		/**
		 * The tabs in their order.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @return {Array<object>} The tabs.
		 */
		tabs() {
			return [...((this.modelValue && this.modelValue.tabs) || [])].sort(
				(a, b) => (a.order ?? 0) - (b.order ?? 0),
			)
		},

		/**
		 * The header block.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007
		 * @return {object} The header.
		 */
		header() {
			return (this.modelValue && this.modelValue.header) || {}
		},

		/**
		 * The task-list block.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008
		 * @return {object} The task list.
		 */
		taskList() {
			return (this.modelValue && this.modelValue.taskList) || {}
		},

		/**
		 * The upload fields.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
		 * @return {Array<object>} The fields.
		 */
		uploadFields() {
			return (this.modelValue && this.modelValue.uploadFields) || []
		},

		/**
		 * Whether the layout holds something the server will refuse.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
		 * @return {boolean} True when blocked.
		 */
		blocked() {
			return layoutBlocked(this.modelValue)
		},
	},

	methods: {
		missingReference,
		previewRows,
		hiddenWithoutDefault,

		/**
		 * Emit the layout with some keys replaced.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @param {object} patch The keys to replace.
		 * @return {void}
		 */
		emitPatch(patch) {
			this.$emit('update:modelValue', { ...(this.modelValue || {}), ...patch })
		},

		/**
		 * Split a comma-separated list.
		 *
		 * @param {string} text The text.
		 * @return {Array<string>} The items.
		 */
		splitList(text) {
			return String(text || '')
				.split(',')
				.map((item) => item.trim())
				.filter((item) => item !== '')
		},

		/**
		 * The label of a tab kind.
		 *
		 * @param {string} kind The kind.
		 * @return {string} The label.
		 */
		kindLabel(kind) {
			return (
				{
					leaf: this.t('buildiq', 'Content from another app'),
					widgets: this.t('buildiq', 'Widgets'),
					fieldGroup: this.t('buildiq', 'Group of fields'),
					relatedList: this.t('buildiq', 'List of related records'),
				}[kind] || kind
			)
		},

		/**
		 * The label of a widget width.
		 *
		 * @param {string} width The width.
		 * @return {string} The label.
		 */
		widthLabel(width) {
			return (
				{
					small: this.t('buildiq', 'Small, a quarter of the row'),
					medium: this.t('buildiq', 'Medium, half the row'),
					large: this.t('buildiq', 'Large, three quarters of the row'),
					extraLarge: this.t('buildiq', 'Extra large, the whole row'),
				}[width] || width
			)
		},

		/**
		 * The grid units of a width.
		 *
		 * @param {string} width The width.
		 * @return {number} Units out of four.
		 */
		widthUnits(width) {
			return UNITS[width] || UNITS.medium
		},

		/**
		 * The label of a condition operator.
		 *
		 * @param {string} op The operator.
		 * @return {string} The label.
		 */
		operatorLabel(op) {
			return (
				{
					equals: this.t('buildiq', 'is'),
					notEquals: this.t('buildiq', 'is not'),
					isEmpty: this.t('buildiq', 'is empty'),
					isNotEmpty: this.t('buildiq', 'is not empty'),
					contains: this.t('buildiq', 'contains'),
				}[op] || op
			)
		},

		/**
		 * What a tab is missing, in words.
		 *
		 * @param {object} tab The tab.
		 * @return {string} The message.
		 */
		missingText(tab) {
			return {
				ref: this.t(
					'buildiq',
					'This tab needs the id of the leaf it shows.',
				),

				fields: this.t('buildiq', 'This tab needs at least one field.'),
				relatedRegister: this.t(
					'buildiq',
					'This tab needs the register of the related records.',
				),

				relatedSchema: this.t(
					'buildiq',
					'This tab needs the schema of the related records.',
				),
			}[missingReference(tab)]
		},

		/**
		 * Add a tab of the chosen kind.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @return {void}
		 */
		onAddTab() {
			this.emitPatch({ tabs: addTab(this.tabs, this.newTabKind) })
		},

		/**
		 * Move a tab.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @param {number} index The tab.
		 * @param {number} delta How far.
		 * @return {void}
		 */
		onMoveTab(index, delta) {
			this.emitPatch({ tabs: moveItem(this.tabs, index, delta) })
		},

		/**
		 * Remove a tab.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @param {number} index The tab.
		 * @return {void}
		 */
		onRemoveTab(index) {
			this.emitPatch({
				tabs: renumber(this.tabs.filter((_, i) => i !== index)),
			})
		},

		/**
		 * Change one key of a tab.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004
		 * @param {number} index The tab.
		 * @param {string} key The key.
		 * @param {unknown} value The value.
		 * @return {void}
		 */
		onTab(index, key, value) {
			const tabs = this.tabs.map((tab, i) =>
				i === index ? { ...tab, [key]: value } : tab,
			)
			this.emitPatch({ tabs })
		},

		/**
		 * Replace the widgets of a tab.
		 *
		 * @param {number} tabIndex The tab.
		 * @param {Array<object>} widgets The widgets.
		 * @return {void}
		 */
		setWidgets(tabIndex, widgets) {
			this.onTab(tabIndex, 'widgets', widgets)
		},

		/**
		 * Add a widget to a widget tab.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-tab-holds-a-grid-each-widget-with-a-width-and-an-order-req-obpl-005
		 * @param {number} tabIndex The tab.
		 * @return {void}
		 */
		onAddWidget(tabIndex) {
			const widgets = this.tabs[tabIndex].widgets || []
			this.setWidgets(tabIndex, [
				...widgets,
				{
					id: freshId('widget', widgets),
					order: widgets.length,
					width: 'medium',
					conditions: [],
					highContrast: false,
				},
			])
		},

		/**
		 * Move a widget.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-tab-holds-a-grid-each-widget-with-a-width-and-an-order-req-obpl-005
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @param {number} delta How far.
		 * @return {void}
		 */
		onMoveWidget(tabIndex, index, delta) {
			this.setWidgets(
				tabIndex,
				moveItem(this.tabs[tabIndex].widgets, index, delta),
			)
		},

		/**
		 * Remove a widget.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-tab-holds-a-grid-each-widget-with-a-width-and-an-order-req-obpl-005
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @return {void}
		 */
		onRemoveWidget(tabIndex, index) {
			this.setWidgets(
				tabIndex,
				renumber(this.tabs[tabIndex].widgets.filter((_, i) => i !== index)),
			)
		},

		/**
		 * Change one key of a widget.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-carries-display-conditions-and-a-high-contrast-flag-req-obpl-006
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @param {string} key The key.
		 * @param {unknown} value The value.
		 * @return {void}
		 */
		onWidget(tabIndex, index, key, value) {
			const widgets = this.tabs[tabIndex].widgets.map((w, i) =>
				i === index ? { ...w, [key]: value } : w,
			)
			this.setWidgets(tabIndex, widgets)
		},

		/**
		 * Add a display condition to a widget.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-carries-display-conditions-and-a-high-contrast-flag-req-obpl-006
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @return {void}
		 */
		onAddCondition(tabIndex, index) {
			const widget = this.tabs[tabIndex].widgets[index]
			this.onWidget(tabIndex, index, 'conditions', [
				...(widget.conditions || []),
				{ field: '', operator: 'equals', value: '' },
			])
		},

		/**
		 * Replace one display condition.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-carries-display-conditions-and-a-high-contrast-flag-req-obpl-006
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @param {number} conditionIndex The condition.
		 * @param {object} condition The new condition.
		 * @return {void}
		 */
		onCondition(tabIndex, index, conditionIndex, condition) {
			const widget = this.tabs[tabIndex].widgets[index]
			const conditions = (widget.conditions || []).map((c, i) =>
				i === conditionIndex ? condition : c,
			)
			this.onWidget(tabIndex, index, 'conditions', conditions)
		},

		/**
		 * Remove one display condition.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-widget-carries-display-conditions-and-a-high-contrast-flag-req-obpl-006
		 * @param {number} tabIndex The tab.
		 * @param {number} index The widget.
		 * @param {number} conditionIndex The condition.
		 * @return {void}
		 */
		onRemoveCondition(tabIndex, index, conditionIndex) {
			const widget = this.tabs[tabIndex].widgets[index]
			this.onWidget(
				tabIndex,
				index,
				'conditions',
				(widget.conditions || []).filter((_, i) => i !== conditionIndex),
			)
		},

		/**
		 * Change one key of the header.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007
		 * @param {string} key The key.
		 * @param {unknown} value The value.
		 * @return {void}
		 */
		onHeader(key, value) {
			this.emitPatch({ header: { ...this.header, [key]: value } })
		},

		/**
		 * Add a field to the header.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007
		 * @param {string} field The field.
		 * @return {void}
		 */
		onAddHeaderField(field) {
			const fields = this.header.fields || []
			this.onHeader('fields', [
				...fields,
				{ field, label: '', order: fields.length },
			])
			this.newHeaderField = ''
		},

		/**
		 * Move a header field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007
		 * @param {number} index The field.
		 * @param {number} delta How far.
		 * @return {void}
		 */
		onMoveHeaderField(index, delta) {
			this.onHeader('fields', moveItem(this.header.fields, index, delta))
		},

		/**
		 * Remove a header field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007
		 * @param {number} index The field.
		 * @return {void}
		 */
		onRemoveHeaderField(index) {
			this.onHeader(
				'fields',
				renumber(this.header.fields.filter((_, i) => i !== index)),
			)
		},

		/**
		 * Add a task-list column.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008
		 * @param {string} field The field.
		 * @return {void}
		 */
		onAddColumn(field) {
			const columns = this.taskList.columns || []
			this.emitPatch({
				taskList: {
					...this.taskList,
					columns: [
						...columns,
						{ field, label: '', order: columns.length },
					],
				},
			})
			this.newTaskField = ''
		},

		/**
		 * Remove a task-list column.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008
		 * @param {number} index The column.
		 * @return {void}
		 */
		onRemoveColumn(index) {
			this.emitPatch({
				taskList: {
					...this.taskList,
					columns: renumber(
						this.taskList.columns.filter((_, i) => i !== index),
					),
				},
			})
		},

		/**
		 * Add a task-list search field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008
		 * @param {string} field The field.
		 * @return {void}
		 */
		onAddSearchField(field) {
			this.emitPatch({
				taskList: {
					...this.taskList,
					searchFields: [
						...(this.taskList.searchFields || []),
						{ field, operator: 'contains' },
					],
				},
			})
			this.newTaskField = ''
		},

		/**
		 * Remove a task-list search field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008
		 * @param {number} index The search field.
		 * @return {void}
		 */
		onRemoveSearchField(index) {
			this.emitPatch({
				taskList: {
					...this.taskList,
					searchFields: this.taskList.searchFields.filter(
						(_, i) => i !== index,
					),
				},
			})
		},

		/**
		 * Add an upload field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
		 * @param {string} field The field.
		 * @return {void}
		 */
		onAddUploadField(field) {
			this.emitPatch({
				uploadFields: [
					...this.uploadFields,
					{
						field,
						label: '',
						order: this.uploadFields.length,
						visibility: 'editable',
					},
				],
			})
			this.newUploadField = ''
		},

		/**
		 * Change one key of an upload field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
		 * @param {number} index The field.
		 * @param {string} key The key.
		 * @param {unknown} value The value.
		 * @return {void}
		 */
		onUploadField(index, key, value) {
			this.emitPatch({
				uploadFields: this.uploadFields.map((f, i) =>
					i === index ? { ...f, [key]: value } : f,
				),
			})
		},

		/**
		 * Remove an upload field.
		 *
		 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md#requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009
		 * @param {number} index The field.
		 * @return {void}
		 */
		onRemoveUploadField(index) {
			this.emitPatch({
				uploadFields: renumber(
					this.uploadFields.filter((_, i) => i !== index),
				),
			})
		},
	},
}
</script>

<style scoped>
.layout-body__section {
	margin-block-end: 12px;
}

.layout-body__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.layout-body__item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-block: 6px;
	border-block-end: 1px solid var(--color-border);
}

.layout-body__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
}

.layout-body__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.layout-body__kind {
	color: var(--color-text-maxcontrast);
}

.layout-body__warn {
	margin: 0;
	color: var(--color-error-text);
}

.layout-body__widgets {
	padding-inline-start: 12px;
	border-inline-start: 2px solid var(--color-border);
}

.layout-body__preview {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block-start: 6px;
}

.layout-body__preview-row {
	display: flex;
	gap: 4px;
}

.layout-body__preview-cell {
	flex-basis: 0;
	padding: 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	text-align: center;
}
</style>
