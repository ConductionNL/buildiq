<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - AggregationEditor — v1 STUB (REQ-OBSD-005 — aggregations slice
  - deferred to v1.1 per design Decision 7). The full typed-record
  - editor depends on the declarative DSL package being published by
  - chain spec #3 (design OQ-1). v1 surfaces a read-only view of any
  - existing `x-openregister-aggregations` block + a "coming in v1.1"
  - message; authoring lands in tasks 8.1.
  -->
<template>
	<section class="openbuilt-aggregation-editor">
		<header class="openbuilt-aggregation-editor__header">
			<h3>{{ t('openbuilt', 'Aggregations') }}</h3>
		</header>
		<NcNoteCard type="info">
			{{ t('openbuilt', 'The aggregation editor ships in v1.1 (see design Decision 7). Existing aggregations declared on this schema are shown read-only below.') }}
		</NcNoteCard>
		<pre v-if="aggregations" class="openbuilt-aggregation-editor__readonly">{{ formatted }}</pre>
		<p v-else class="openbuilt-aggregation-editor__empty">
			{{ t('openbuilt', 'No aggregations declared on this schema.') }}
		</p>
	</section>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'AggregationEditor',
	components: { NcNoteCard },
	props: {
		aggregations: { type: [Object, Array], default: null },
	},
	computed: {
		formatted() {
			try {
				return JSON.stringify(this.aggregations, null, 2)
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-aggregation-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-aggregation-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-aggregation-editor__readonly {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 13px;
	overflow: auto;
}

.openbuilt-aggregation-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
