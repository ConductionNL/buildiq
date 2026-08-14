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
	<section class="openbuild-calculation-editor">
		<header class="openbuild-calculation-editor__header">
			<h3>{{ t('openbuild', 'Calculations') }}</h3>
		</header>
		<NcNoteCard type="info">
			{{
				t(
					'openbuild',
					'The calculation editor ships in v1.1 (see design Decision 7). Existing calculations declared on this schema are shown read-only below.',
				)
			}}
		</NcNoteCard>
		<pre v-if="calculations" class="openbuild-calculation-editor__readonly">{{
			formatted
		}}</pre>
		<p v-else class="openbuild-calculation-editor__empty">
			{{ t('openbuild', 'No calculations declared on this schema.') }}
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
		/**
		 * Render the calculations block as pretty JSON for read-only display.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
		 * @return {string} Formatted JSON, or empty string on error.
		 */
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
.openbuild-calculation-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-calculation-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuild-calculation-editor__readonly {
	margin: 0;
	padding: 8px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 13px;
	overflow: auto;
}

.openbuild-calculation-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
</style>
