<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	BlockRemapDialog — standalone dialog (gate-modal-isolation) opened by
	the block-library panel's insert flow whenever a block's
	`schemaDependencies` do not exact-match a schema slug present in the
	target app (component-blocks REQ "Schema-dependency mismatch triggers
	an explicit remap prompt"). Never auto-guesses a mapping — the
	developer must explicitly choose a target schema per dependency, or
	explicitly leave it unresolved (inserted as a visible placeholder,
	never a silent drop).
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Resolve schema references')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-block-remap">
			<p class="ob-block-remap__intro">
				{{
					t(
						'buildiq',
						'This block references schemas that do not exist under the same name in this app. Map each one to a schema here, or leave it unresolved — an unresolved binding inserts as a visible "needs remap" placeholder, it is never silently dropped.',
					)
				}}
			</p>

			<div v-for="dep in dependencies" :key="dep" class="ob-block-remap__row">
				<span class="ob-block-remap__source">{{ dep }}</span>
				<NcSelect
					v-model="selections[dep]"
					:inputLabel="t('buildiq', 'Map “{dep}” to', { dep })"
					:options="schemaOptions"
					:clearable="true"
					:placeholder="t('buildiq', 'Leave unresolved')" />
			</div>

			<p v-if="!dependencies.length" class="ob-block-remap__none">
				{{ t('buildiq', 'No schema references need resolving.') }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" @click="confirm">
				{{ t('buildiq', 'Insert block') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'

export default {
	name: 'BlockRemapDialog',
	components: { NcButton, NcDialog, NcSelect },
	props: {
		open: { type: Boolean, default: false },
		// The mismatched dependency slugs (from computeSchemaMismatches).
		dependencies: { type: Array, default: () => [] },
		// Schema slugs available in the target app.
		targetSchemaSlugs: { type: Array, default: () => [] },
	},

	emits: ['update:open', 'resolved'],
	data() {
		return {
			// Map of dependency slug -> selected NcSelect option ({id, label}) or null.
			selections: {},
		}
	},

	computed: {
		/**
		 * Options offered in every remap picker.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		schemaOptions() {
			return this.targetSchemaSlugs.map((slug) => ({ id: slug, label: slug }))
		},
	},

	watch: {
		/**
		 * Reset every dependency's picker whenever the dialog opens.
		 *
		 * @param {boolean} value - the new `open` prop value.
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		open(value) {
			if (value) {
				this.resetSelections()
			}
		},

		/**
		 * Reset the picker state whenever the mismatched-dependency list changes.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		dependencies() {
			this.resetSelections()
		},
	},

	/**
	 * Reset the picker state when already open on mount.
	 *
	 * @return {void}
	 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
	 */
	created() {
		if (this.open) {
			this.resetSelections()
		}
	},

	methods: {
		/**
		 * Reset every dependency's picker to unresolved (null).
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		resetSelections() {
			const next = {}
			for (const dep of this.dependencies) {
				next[dep] = null
			}
			this.selections = next
		},

		/**
		 * Close without resolving anything.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		onClose() {
			this.$emit('update:open', false)
		},

		/**
		 * Build `{ remapMap, unresolvedDependencies }` from the current
		 * picker state and emit it — `blockInsert.js#insertBlock` consumes
		 * this shape directly.
		 *
		 * @return {void}
		 * @spec openspec/changes/component-blocks/specs/component-blocks/spec.md
		 */
		confirm() {
			const remapMap = {}
			const unresolvedDependencies = []
			for (const dep of this.dependencies) {
				const selection = this.selections[dep]
				const target = selection && (selection.id ?? selection)
				if (target) {
					remapMap[dep] = target
				} else {
					unresolvedDependencies.push(dep)
				}
			}
			this.$emit('resolved', { remapMap, unresolvedDependencies })
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-block-remap {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 360px;
}

.ob-block-remap__intro {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.ob-block-remap__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.ob-block-remap__source {
	font-family: monospace;
	font-size: 0.85rem;
	color: var(--color-main-text);
}

.ob-block-remap__none {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
