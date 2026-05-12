<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - CalculationEditor — v1 STUB (REQ-OBSD-005 — calculations slice
  - deferred to v1.1 per design Decision 7). The formula DSL parser
  - depends on the declarative DSL package being published by chain
  - spec #3 (design OQ-1). v1 surfaces a read-only view of any
  - existing `x-openregister-calculations` block + a "coming in v1.1"
  - message; authoring lands in tasks 8.2.
  -->
<template>
	<section class="openbuilt-calculation-editor">
		<header class="openbuilt-calculation-editor__header">
			<h3>{{ t('openbuilt', 'Calculations') }}</h3>
		</header>
		<NcNoteCard type="info">
			{{ t('openbuilt', 'The calculation editor ships in v1.1 (see design Decision 7). Existing calculations declared on this schema are shown read-only below.') }}
		</NcNoteCard>
		<pre v-if="calculations" class="openbuilt-calculation-editor__readonly">{{ formatted }}</pre>
		<p v-else class="openbuilt-calculation-editor__empty">
			{{ t('openbuilt', 'No calculations declared on this schema.') }}
		</p>
	</section>
</template>

<script>
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'CalculationEditor',
	components: { NcNoteCard },
	props: {
		calculations: { type: [Object, Array], default: null },
	},
	computed: {
		formatted() {
			try {
				return JSON.stringify(this.calculations, null, 2)
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-calculation-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-calculation-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-calculation-editor__readonly {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 13px;
	overflow: auto;
}

.openbuilt-calculation-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
