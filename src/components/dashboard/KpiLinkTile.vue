<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - KpiLinkTile — one clickable KPI tile of the Buildiq dashboard: a stats
  - block inside a button-role wrapper that emits `open` on click or Enter.
  - One root element, so the dashboard's `.ob-kpi-link` styles apply to it.
  -->
<template>
	<div
		class="ob-kpi-link"
		role="button"
		tabindex="0"
		@click="$emit('open')"
		@keyup.enter="$emit('open')">
		<CnStatsBlock
			horizontal
			:icon="icon"
			:title="title"
			:count="count"
			:countLabel="countLabel"
			:variant="variant"
			showZeroCount />
	</div>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'

export default {
	name: 'KpiLinkTile',
	components: { CnStatsBlock },
	props: {
		// The stats-block icon component.
		icon: { type: Object, required: true },
		// The translated title and count label.
		title: { type: String, required: true },
		countLabel: { type: String, default: '' },
		count: { type: Number, default: 0 },
		// The stats-block variant.
		variant: { type: String, default: 'default' },
	},

	emits: ['open'],
}
</script>
