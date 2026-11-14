<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!--
  - StepHeadRow — the header row of one step in the walkthrough designer's
  - step lists (tour steps and setup wizard steps): the step number, the step
  - id field, and move up / move down / delete. The owning editor applies the
  - edits; this row only emits them.
  -->
<template>
	<div class="wt-designer__step-head">
		<span class="wt-designer__step-num">{{ index + 1 }}</span>
		<NcTextField
			:label="t('buildiq', 'Step id')"
			:modelValue="stepId"
			@update:modelValue="(v) => $emit('update:stepId', v)" />
		<NcButton
			variant="tertiary"
			:disabled="index === 0"
			:aria-label="t('buildiq', 'Move up')"
			@click="$emit('move', -1)">
			<template #icon>
				<ArrowUp :size="20" />
			</template>
		</NcButton>
		<NcButton
			variant="tertiary"
			:disabled="last"
			:aria-label="t('buildiq', 'Move down')"
			@click="$emit('move', 1)">
			<template #icon>
				<ArrowDown :size="20" />
			</template>
		</NcButton>
		<NcButton
			variant="tertiary"
			:aria-label="t('buildiq', 'Delete step')"
			@click="$emit('delete')">
			<template #icon>
				<Delete :size="20" />
			</template>
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcTextField } from '@nextcloud/vue'
import ArrowDown from 'vue-material-design-icons/ArrowDown.vue'
import ArrowUp from 'vue-material-design-icons/ArrowUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'StepHeadRow',
	components: { ArrowDown, ArrowUp, Delete, NcButton, NcTextField },
	props: {
		// The step's position in its list (0-based).
		index: { type: Number, required: true },
		// The step id ('' when unset).
		stepId: { type: String, default: '' },
		// Whether the step is the last of its list.
		last: { type: Boolean, default: false },
	},

	emits: ['update:stepId', 'move', 'delete'],

	methods: {
		t,
	},
}
</script>

<style scoped>
.wt-designer__step-num {
	flex: 0 0 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	font-size: 0.8rem;
}
</style>
