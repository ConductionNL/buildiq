<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
	WidgetPlacementPanel — authors the uniform v2 `widgetEntry` placements in a
	page's `widgets[]` array (v2-widget-placement-editor).

	Until this panel existed every v2 placement in the fleet arrived from a
	template, a saved block or the copilot, and moving a widget one column to
	the left meant editing raw JSON. `WidgetBuilder.vue` still authors the v1
	`config.widgets` shape and is untouched by this change.

	TWO AUTHORING PATHS, ONE SHAPE (design.md D2). The `body` slot mounts the
	library's `CnWidgetGrid` with `editable`, which renders a GridStack drag and
	resize canvas and emits `layout-change`. Every other slot is authored
	through numeric fields, because `CnWidgetGrid`'s own `editableBody()` is
	`this.editable && this.slotName === 'body'`: setting `editable` on a sidebar
	or footer grid changes nothing at all, and the failure is invisible. The
	field rows are present for EVERY slot including `body`, so no slot family is
	drag-only and nobody is locked out by not having a pointer.

	Both paths run every geometric value through `services/slotGeometry.js`, and
	nothing here re-derives a column count. Every placement is written by
	spreading the one that was there, never by rebuilding it from a list of keys
	this file happens to know (design.md D6) — that is what lets `tabGroup`,
	`roles`, `visibleWhen` and whatever the next library version adds survive an
	unrelated edit.
-->
<template>
	<section class="widget-placement-panel">
		<h4 class="widget-placement-panel__title">
			{{ t('buildiq', 'Widget placements') }}
		</h4>

		<p
			v-if="catalogueIsEmpty"
			class="widget-placement-panel__notice"
			role="alert">
			{{
				t(
					'buildiq',
					'The widget catalogue is empty, so the type picker has nothing to offer.',
				)
			}}
		</p>

		<!-- ADR-036 decision 1. Surfaced here, where the author is, rather than
		     as a save the validator rejects, and naming both documented ways
		     out because neither is obvious from the rule. -->
		<div
			v-if="disguisedWidgetKey"
			class="widget-placement-panel__notice widget-placement-panel__notice--warning"
			role="alert"
			data-testid="disguise-warning">
			<p>
				{{
					t(
						'buildiq',
						'This dashboard holds one full width custom widget and nothing else.',
					)
				}}
			</p>
			<p>
				{{ t('buildiq', 'That shape reads as a custom page in disguise.') }}
			</p>
			<p>
				{{ t('buildiq', 'Declare this page as custom, with this component:') }}
				<code data-testid="disguise-component">{{ disguisedWidgetKey }}</code>
			</p>
			<p>{{ t('buildiq', 'Or add a second widget to the page.') }}</p>
		</div>

		<p
			v-if="placements.length === 0"
			class="widget-placement-panel__empty"
			data-testid="placement-empty-state">
			{{
				t(
					'buildiq',
					'This page has no widgets yet. Add one to start placing them.',
				)
			}}
		</p>

		<div
			v-for="group in groups"
			:key="group.slot"
			class="widget-placement-panel__group">
			<h5 class="widget-placement-panel__group-title">
				{{ slotLabel(group.slot) }}
			</h5>

			<!-- Drag and resize, body slot only. See the note above on
			     `editableBody()`: pointing this at any other slot renders a
			     grid that silently never emits. -->
			<CnWidgetGrid
				v-if="group.slot === 'body'"
				class="widget-placement-panel__canvas"
				:widgets="bodyPlacements"
				slotName="body"
				:editable="true"
				@layoutChange="onLayoutChange" />

			<ul class="widget-placement-panel__rows">
				<li
					v-for="row in group.rows"
					:key="row.index"
					class="widget-placement-panel__row"
					data-testid="placement-row">
					<span class="widget-placement-panel__key">{{
						displayName(row.entry)
					}}</span>

					<label class="widget-placement-panel__field">
						<span>{{ t('buildiq', 'Slot') }}</span>
						<select
							class="widget-placement-panel__select"
							:value="row.entry.slot"
							data-testid="placement-slot"
							@change="updateSlot(row.index, $event.target.value)">
							<option
								v-for="option in slotOptions"
								:key="option"
								:value="option">
								{{ slotLabel(option) }}
							</option>
						</select>
					</label>

					<label
						v-if="offersSpan(row.entry.slot)"
						class="widget-placement-panel__field">
						<span>{{ t('buildiq', 'Column') }}</span>
						<input
							type="number"
							min="0"
							class="widget-placement-panel__number"
							:value="row.entry.gridX"
							data-testid="placement-grid-x"
							@input="updateGeometry(row.index, 'gridX', $event.target.value)" />
					</label>

					<label
						v-if="offersRow(row.entry.slot)"
						class="widget-placement-panel__field">
						<span>{{ t('buildiq', 'Row') }}</span>
						<input
							type="number"
							min="0"
							class="widget-placement-panel__number"
							:value="row.entry.gridY"
							data-testid="placement-grid-y"
							@input="updateGeometry(row.index, 'gridY', $event.target.value)" />
					</label>

					<label
						v-if="offersSpan(row.entry.slot)"
						class="widget-placement-panel__field">
						<span>{{ t('buildiq', 'Width') }}</span>
						<input
							type="number"
							min="1"
							:max="columnsFor(row.entry.slot)"
							class="widget-placement-panel__number"
							:value="row.entry.gridWidth"
							data-testid="placement-grid-width"
							@input="
								updateGeometry(row.index, 'gridWidth', $event.target.value)
							" />
					</label>

					<label class="widget-placement-panel__field">
						<span>{{ t('buildiq', 'Height') }}</span>
						<input
							type="number"
							min="1"
							class="widget-placement-panel__number"
							:value="row.entry.gridHeight"
							data-testid="placement-grid-height"
							@input="
								updateGeometry(row.index, 'gridHeight', $event.target.value)
							" />
					</label>

					<label
						v-if="noteIsRequired"
						class="widget-placement-panel__field widget-placement-panel__field--note">
						<span>{{ t('buildiq', 'Why this page is custom') }}</span>
						<input
							type="text"
							class="widget-placement-panel__note"
							:value="row.entry._note || ''"
							data-testid="placement-note"
							@input="updateNote(row.index, $event.target.value)" />
					</label>

					<!-- D8 leaves room here: the sibling change hangs a promote
					     toggle off this row, so the controls are a list rather
					     than two hard-coded buttons. -->
					<span class="widget-placement-panel__actions">
						<button
							type="button"
							class="widget-placement-panel__button"
							data-testid="placement-move-up"
							:disabled="row.index === 0"
							@click="move(row.index, -1)">
							{{ t('buildiq', 'Move up') }}
						</button>
						<button
							type="button"
							class="widget-placement-panel__button"
							data-testid="placement-move-down"
							:disabled="row.index === placements.length - 1"
							@click="move(row.index, 1)">
							{{ t('buildiq', 'Move down') }}
						</button>
						<button
							type="button"
							class="widget-placement-panel__button"
							data-testid="placement-edit"
							@click="openEdit(row.index)">
							{{ t('buildiq', 'Edit widget') }}
						</button>
						<button
							type="button"
							class="widget-placement-panel__button widget-placement-panel__button--danger"
							data-testid="placement-delete"
							@click="remove(row.index)">
							{{ t('buildiq', 'Remove placement') }}
						</button>
					</span>
				</li>
			</ul>
		</div>

		<div class="widget-placement-panel__add">
			<label class="widget-placement-panel__field">
				<span>{{ t('buildiq', 'Slot for the next widget') }}</span>
				<select
					v-model="pendingSlot"
					class="widget-placement-panel__select"
					data-testid="pending-slot">
					<option v-for="option in slotOptions" :key="option" :value="option">
						{{ slotLabel(option) }}
					</option>
				</select>
			</label>

			<label
				v-if="noteIsRequired"
				class="widget-placement-panel__field widget-placement-panel__field--note">
				<span>{{ t('buildiq', 'Why this page is custom') }}</span>
				<input
					v-model="pendingNote"
					type="text"
					class="widget-placement-panel__note"
					aria-describedby="widget-placement-note-hint"
					data-testid="pending-note" />
			</label>
			<p
				v-if="noteIsRequired"
				id="widget-placement-note-hint"
				class="widget-placement-panel__hint"
				data-testid="pending-note-hint">
				{{
					t(
						'buildiq',
						'A custom page must document why a standard page type was not feasible.',
					)
				}}
			</p>

			<button
				type="button"
				class="widget-placement-panel__button widget-placement-panel__button--primary"
				data-testid="placement-add"
				:disabled="!canAdd"
				@click="openAdd">
				{{ t('buildiq', 'Add widget') }}
			</button>
		</div>

		<!-- One modal for add and for edit, driven by `editingWidget`.
		     `userAddableOnly` stays off on purpose: the narrower user list
		     exists because a user picking for their own dashboard has no
		     register or schema in front of them, and a page designer is the
		     opposite situation (design.md D3). -->
		<CnAddWidgetModal
			:show="modalOpen"
			:editingWidget="editingWidget"
			:surface="widgetSurface"
			:userAddableOnly="false"
			:pageConfig="page && page.config ? page.config : null"
			:dataContext="dataContext"
			@close="closeModal"
			@submit="onModalSubmit" />
	</section>
</template>

<script>
import {
	CnAddWidgetModal,
	CnWidgetGrid,
	getWidgetTypeEntry,
	listWidgetTypes,
} from '@conduction/nextcloud-vue'
import { mintWidgetId } from '../../services/blockInsert.js'
import {
	customPageInDisguiseKey,
	pageRequiresPlacementNote,
	widgetSurfaceForPageType,
} from '../../services/pagePlacementChecks.js'
import {
	applySlotRules,
	columnsForSlot,
	defaultGeometryFor,
	isValidSlot,
	SLOT_DISPLAY_ORDER,
	slotOffersRow,
	slotOffersSpan,
} from '../../services/slotGeometry.js'

export default {
	name: 'WidgetPlacementPanel',
	components: { CnWidgetGrid, CnAddWidgetModal },
	props: {
		/**
		 * The selected page. `widgets[]`, `type` and `config` are read; nothing
		 * on it is mutated, and the whole replacement array leaves through
		 * `update:widgets`.
		 */
		page: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:widgets'],
	data() {
		return {
			modalOpen: false,
			// -1 means the modal is open for an ADD; any other value is the
			// index in `widgets[]` of the placement being edited.
			editingIndex: -1,
			pendingSlot: 'body',
			pendingNote: '',
		}
	},

	computed: {
		/**
		 * The page's placements, always an array.
		 *
		 * @return {Array<object>} the page's `widgets[]`.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		placements() {
			return this.page && Array.isArray(this.page.widgets)
				? this.page.widgets
				: []
		},

		/**
		 * The body-slot placements, handed to the editable grid. Kept as a
		 * computed so the array identity is stable between renders: the grid
		 * initialises GridStack on mount, not on every prop write.
		 *
		 * @return {Array<object>} the body placements, in page order.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		bodyPlacements() {
			return this.placements.filter((entry) => entry && entry.slot === 'body')
		},

		/**
		 * The placements grouped by slot, body first. A slot with no placements
		 * is left out, except `body`, which always shows so the canvas and the
		 * page's main surface are visible on an empty page.
		 *
		 * @return {Array<{slot: string, rows: Array<{index: number, entry: object}>}>}
		 *   the groups in display order.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		groups() {
			const bySlot = new Map([['body', []]])
			this.placements.forEach((entry, index) => {
				if (!entry) {
					return
				}
				const slot = isValidSlot(entry.slot) ? entry.slot : 'body'
				if (!bySlot.has(slot)) {
					bySlot.set(slot, [])
				}
				bySlot.get(slot).push({ index, entry })
			})

			const ordered = [...bySlot.keys()].sort((a, b) => {
				const ai = SLOT_DISPLAY_ORDER.indexOf(a)
				const bi = SLOT_DISPLAY_ORDER.indexOf(b)
				if (ai !== bi) {
					return (ai === -1 ? SLOT_DISPLAY_ORDER.length : ai)
						- (bi === -1 ? SLOT_DISPLAY_ORDER.length : bi)
				}
				return a.localeCompare(b)
			})

			return ordered.map((slot) => ({ slot, rows: bySlot.get(slot) }))
		},

		/**
		 * Every slot an author may choose, in the row selector and for the next
		 * placement: the five literals, plus every `tab:<id>` / `section:<id>`
		 * the page's own config declares, plus any slot already in use.
		 *
		 * Free text is deliberately not offered (design.md open question Q2):
		 * a placement in a tab the page does not declare renders nowhere, and
		 * says nothing about why.
		 *
		 * @return {string[]} the offerable slot names.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		slotOptions() {
			const options = [...SLOT_DISPLAY_ORDER]
			const config
				= this.page && this.page.config && typeof this.page.config === 'object'
					? this.page.config
					: {}
			const declared = [
				...(Array.isArray(config.tabs)
					? config.tabs.map((tab) => (tab && tab.id ? `tab:${tab.id}` : null))
					: []),
				...(Array.isArray(config.sections)
					? config.sections.map((section) =>
							section && section.id ? `section:${section.id}` : null,
						)
					: []),
				...this.placements.map((entry) => (entry ? entry.slot : null)),
			]
			for (const slot of declared) {
				if (isValidSlot(slot) && !options.includes(slot)) {
					options.push(slot)
				}
			}
			return options
		},

		/**
		 * The surface the page represents, for the modal's type picker.
		 *
		 * @return {string} `'detail-page'` or `'app-dashboard'`.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		widgetSurface() {
			return widgetSurfaceForPageType(this.page ? this.page.type : '')
		},

		/**
		 * The widget types an administrator may place on this page. Read from
		 * the library catalogue, filtered by the page's surface, and used to
		 * tell an EMPTY catalogue apart from a page with nothing on it. An
		 * empty catalogue renders an empty type picker and throws nothing,
		 * which is the failure this reports.
		 *
		 * @return {string[]} the offerable widget type keys.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		offerableTypes() {
			return listWidgetTypes(this.widgetSurface)
		},

		/**
		 * Whether the catalogue has nothing to offer on this surface.
		 *
		 * @return {boolean} true when the type picker would be empty.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		catalogueIsEmpty() {
			return this.offerableTypes.length === 0
		},

		/**
		 * The `widgetKey` that makes this page a custom page in disguise, or
		 * null.
		 *
		 * @return {string|null} the offending key.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		disguisedWidgetKey() {
			return customPageInDisguiseKey(this.page)
		},

		/**
		 * Whether this page demands a note on every placement.
		 *
		 * @return {boolean} true on a `custom` page.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		noteIsRequired() {
			return pageRequiresPlacementNote(this.page)
		},

		/**
		 * Whether a new placement may be started. On a custom page the note is
		 * collected before the type picker opens, because the library modal
		 * owns its own confirm button and has no note field to block.
		 *
		 * @return {boolean} true when the add action is available.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		canAdd() {
			if (this.catalogueIsEmpty) {
				return false
			}
			return !this.noteIsRequired || this.pendingNote.trim() !== ''
		},

		/**
		 * The placement the modal is editing, in the `{type, content}` shape
		 * `CnAddWidgetModal` reads. Null while the modal is open for an add.
		 *
		 * @return {?{type: string, content: object}} the modal's edit seed.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		editingWidget() {
			if (this.editingIndex < 0) {
				return null
			}
			const entry = this.placements[this.editingIndex]
			if (!entry) {
				return null
			}
			return {
				type: entry.widgetKey,
				content: entry.props && typeof entry.props === 'object'
					? { ...entry.props }
					: {},
			}
		},

		/**
		 * `{ register, schema }` for the data sub-form, from the page's own
		 * config. Null on a page with no single object, which is most
		 * dashboards.
		 *
		 * @return {?{register: string, schema: string}} the object context.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		dataContext() {
			const config
				= this.page && this.page.config && typeof this.page.config === 'object'
					? this.page.config
					: null
			if (!config || !config.register || !config.schema) {
				return null
			}
			return { register: config.register, schema: config.schema }
		},
	},

	methods: {
		/**
		 * The human label for a slot.
		 *
		 * @param {string} slot - the slot name.
		 * @return {string} the label shown to the author.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		slotLabel(slot) {
			const labels = {
				body: t('buildiq', 'Body'),
				sidebar: t('buildiq', 'Sidebar'),
				'header-actions': t('buildiq', 'Header actions'),
				footer: t('buildiq', 'Footer'),
				modal: t('buildiq', 'Modal'),
			}
			return labels[slot] || slot
		},

		/**
		 * The name shown on a placement row: the catalogue's display name for
		 * the type, falling back to the raw key.
		 *
		 * @param {object} entry - the placement.
		 * @return {string} the row label.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		displayName(entry) {
			const key = entry && entry.widgetKey ? entry.widgetKey : ''
			const registered = getWidgetTypeEntry(key)
			return (registered && registered.displayName) || key
		},

		/**
		 * Whether this slot offers a row control.
		 *
		 * @param {string} slot - the placement's slot.
		 * @return {boolean} true when `gridY` is the author's to set.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		offersRow(slot) {
			return slotOffersRow(slot)
		},

		/**
		 * Whether this slot offers column and span controls.
		 *
		 * @param {string} slot - the placement's slot.
		 * @return {boolean} true when `gridX` and `gridWidth` are the author's.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		offersSpan(slot) {
			return slotOffersSpan(slot, this.page)
		},

		/**
		 * The resolved column count for a slot, for the span field's `max`.
		 *
		 * @param {string} slot - the placement's slot.
		 * @return {number} the effective column count.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		columnsFor(slot) {
			return columnsForSlot(slot, this.page)
		},

		/**
		 * Replace one placement, spreading the existing entry so every key this
		 * panel does not surface survives, and re-applying the slot's rules.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @param {object} changed - the keys being written.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		writeEntry(index, changed) {
			const current = this.placements[index]
			if (!current) {
				return
			}
			const next = this.placements.slice()
			next[index] = applySlotRules({ ...current, ...changed }, this.page)
			this.emitWidgets(next)
		},

		/**
		 * Move a placement to another slot, re-applying the target slot's rules
		 * before it is stored: a body placement moved into `header-actions`
		 * lands on row zero, and one moved into `sidebar` lands at span one.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @param {string} slot - the target slot.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		updateSlot(index, slot) {
			this.writeEntry(index, { slot })
		},

		/**
		 * Write one grid coordinate. The raw input string goes straight into
		 * the geometry helper, which parses it and constrains it, so a value
		 * that would break a rule is never stored and reported later.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @param {'gridX'|'gridY'|'gridWidth'|'gridHeight'} key - the coordinate.
		 * @param {string} value - the number input's raw value.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		updateGeometry(index, key, value) {
			this.writeEntry(index, { [key]: value })
		},

		/**
		 * Write a placement's note on a custom page. An emptied note deletes
		 * the key rather than storing `''`, following the round-trip discipline
		 * `DashboardPageEditor.vue`'s `update()` documents.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @param {string} value - the note text.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		updateNote(index, value) {
			const current = this.placements[index]
			if (!current) {
				return
			}
			const entry = { ...current }
			if (typeof value === 'string' && value.trim() !== '') {
				entry._note = value
			} else {
				delete entry._note
			}
			const next = this.placements.slice()
			next[index] = applySlotRules(entry, this.page)
			this.emitWidgets(next)
		},

		/**
		 * Reorder a placement within `widgets[]` by one position. Available
		 * from the keyboard, so reordering never needs a pointer.
		 *
		 * @param {number} index - the placement's current position.
		 * @param {number} offset - `-1` to move up, `1` to move down.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		move(index, offset) {
			const target = index + offset
			if (target < 0 || target >= this.placements.length) {
				return
			}
			const next = this.placements.slice()
			const [moved] = next.splice(index, 1)
			next.splice(target, 0, moved)
			this.emitWidgets(next)
		},

		/**
		 * Remove exactly one placement and leave every other entry alone: the
		 * survivors are the same objects, never rebuilt copies.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		remove(index) {
			if (!this.placements[index]) {
				return
			}
			const next = this.placements.slice()
			next.splice(index, 1)
			this.emitWidgets(next)
		},

		/**
		 * Re-read the body placements after a drag or a resize and emit them.
		 *
		 * UNCONDITIONALLY (design.md R3). `CnWidgetGrid.handleGridChange` writes
		 * the new geometry onto the entries IN PLACE and then emits the very
		 * array it was handed, so there is no previous value left to diff
		 * against. A handler that compared the payload to what it held would
		 * see no change and skip the write, leaving the designer looking clean
		 * while the manifest had moved.
		 *
		 * @param {Array<object>} payload - the entries the grid emitted.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		onLayoutChange(payload) {
			const emitted = Array.isArray(payload) ? payload : []
			let bodyIndex = -1
			const next = this.placements.map((entry) => {
				if (!entry || entry.slot !== 'body') {
					return entry
				}
				bodyIndex += 1
				// Identity first: the grid hands back the same objects. Then the
				// id, which is how the grid itself keys an item. Then body order,
				// which is the grid's own index fallback.
				const moved
					= emitted.find((candidate) => candidate === entry)
						|| (entry.id
							? emitted.find(
									(candidate) => candidate && candidate.id === entry.id,
								)
							: null)
						|| emitted[bodyIndex]
						|| entry
				return applySlotRules(
					{
						...entry,
						gridX: moved.gridX,
						gridY: moved.gridY,
						gridWidth: moved.gridWidth,
						gridHeight: moved.gridHeight,
					},
					this.page,
				)
			})
			this.emitWidgets(next)
		},

		/**
		 * Open the type picker for a new placement in the pending slot.
		 *
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		openAdd() {
			if (!this.canAdd) {
				return
			}
			this.editingIndex = -1
			this.modalOpen = true
		},

		/**
		 * Open the type picker pre-filled with an existing placement.
		 *
		 * @param {number} index - the placement's position in `widgets[]`.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		openEdit(index) {
			if (!this.placements[index]) {
				return
			}
			this.editingIndex = index
			this.modalOpen = true
		},

		/**
		 * Close the type picker without writing anything.
		 *
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		closeModal() {
			this.modalOpen = false
			this.editingIndex = -1
		},

		/**
		 * Fold the modal's `{type, content, chrome}` payload into a placement's
		 * `props`.
		 *
		 * The chrome rides inside `props` rather than on the entry itself: the
		 * v2 `widgetEntry` is `additionalProperties: false` and declares no
		 * `title`, `showTitle`, `icon` or `styleConfig`, so writing them at the
		 * top level produces a placement the schema rejects. `CnWidgetGrid`
		 * spreads `widget.props` last onto the rendered component, and
		 * `CnAddWidgetModal.seedChrome` reads `content.title`, `showTitle`,
		 * `icon` and `styleConfig.backgroundColor` back out, so the appearance
		 * round-trips through an edit.
		 *
		 * @param {object} payload - the modal's submit payload.
		 * @param {object|null} existingProps - the placement's current props.
		 * @return {object} the merged props.
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		buildProps(payload, existingProps) {
			const content
				= payload && payload.content && typeof payload.content === 'object'
					? payload.content
					: {}
			const chrome
				= payload && payload.chrome && typeof payload.chrome === 'object'
					? payload.chrome
					: {}
			const props = { ...(existingProps || {}), ...content }
			if (chrome.customTitle) {
				props.title = chrome.customTitle
			}
			if (typeof chrome.showTitle === 'boolean') {
				props.showTitle = chrome.showTitle
			}
			if (chrome.customIcon) {
				props.icon = chrome.customIcon
			}
			if (chrome.backgroundColor) {
				props.styleConfig = {
					...(props.styleConfig || {}),
					backgroundColor: chrome.backgroundColor,
				}
			}
			return props
		},

		/**
		 * Persist the modal's payload, as a new placement or onto the one being
		 * edited.
		 *
		 * A new placement is minted an id by `blockInsert.js`'s `mintWidgetId`,
		 * seeded with the ids already on the page, exactly as `insertBlock`
		 * seeds it. An edit NEVER regenerates an id: a stored delta override
		 * keys `widgets[]` by id, so a changed id does not error, it silently
		 * stops matching.
		 *
		 * @param {{type: string, content: object, chrome: object}} payload - the
		 *   modal's submit payload.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		onModalSubmit(payload) {
			if (!payload || !payload.type) {
				this.closeModal()
				return
			}

			if (this.editingIndex >= 0) {
				const current = this.placements[this.editingIndex]
				if (current) {
					const props = this.buildProps(payload, current.props)
					const entry = { ...current, widgetKey: payload.type }
					if (Object.keys(props).length > 0) {
						entry.props = props
					} else {
						delete entry.props
					}
					const next = this.placements.slice()
					next[this.editingIndex] = applySlotRules(entry, this.page)
					this.emitWidgets(next)
				}
				this.closeModal()
				return
			}

			const slot = isValidSlot(this.pendingSlot) ? this.pendingSlot : 'body'
			const existingIds = new Set(
				this.placements.map((entry) => entry && entry.id).filter(Boolean),
			)
			const entry = {
				id: mintWidgetId(payload.type, existingIds),
				widgetKey: payload.type,
				...defaultGeometryFor(slot, this.page, this.placements),
			}
			const props = this.buildProps(payload, null)
			if (Object.keys(props).length > 0) {
				entry.props = props
			}
			if (this.noteIsRequired && this.pendingNote.trim() !== '') {
				entry._note = this.pendingNote
			}

			this.emitWidgets([...this.placements, applySlotRules(entry, this.page)])
			this.pendingNote = ''
			this.closeModal()
		},

		/**
		 * The one write path out of this panel: the whole replacement
		 * `widgets[]` array.
		 *
		 * @param {Array<object>} widgets - the complete replacement array.
		 * @return {void}
		 * @spec openspec/changes/v2-widget-placement-editor/specs/openbuild-page-designer/spec.md
		 */
		emitWidgets(widgets) {
			this.$emit('update:widgets', widgets)
		},
	},
}
</script>

<style scoped>
.widget-placement-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px;
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.widget-placement-panel__title,
.widget-placement-panel__group-title {
	margin: 0;
}

.widget-placement-panel__notice {
	margin: 0;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.widget-placement-panel__notice--warning {
	border-inline-start: 4px solid var(--color-warning);
}

.widget-placement-panel__notice p {
	margin: 0 0 4px;
}

.widget-placement-panel__empty,
.widget-placement-panel__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
}

.widget-placement-panel__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.widget-placement-panel__canvas {
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
	min-height: 60px;
}

.widget-placement-panel__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.widget-placement-panel__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.widget-placement-panel__key {
	flex: 1 1 120px;
	font-weight: bold;
}

.widget-placement-panel__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 0.8rem;
}

.widget-placement-panel__field--note {
	flex: 1 1 200px;
}

.widget-placement-panel__number {
	width: 70px;
}

.widget-placement-panel__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.widget-placement-panel__button {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.widget-placement-panel__button--primary {
	align-self: flex-start;
	background: var(--color-primary-element-light);
}

.widget-placement-panel__button--danger {
	color: var(--color-error);
}

.widget-placement-panel__button[disabled] {
	cursor: not-allowed;
	opacity: 0.5;
}

.widget-placement-panel__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}
</style>
