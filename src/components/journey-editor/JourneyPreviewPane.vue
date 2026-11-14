<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->
<!--
  JourneyPreviewPane — the live preview of the journey being designed
  (journey-designer, task 4). It mounts the real `CnJourney` the portal uses,
  with the in-flight journey and an in-memory run store, so the preview is
  the renderer and advancing it writes nothing. Every change to the journey
  restarts the preview.

  @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
-->
<template>
	<section class="journey-preview" :aria-label="t('buildiq', 'Preview')">
		<header class="journey-preview__head">
			<h3>{{ t('buildiq', 'Preview') }}</h3>
			<p class="journey-preview__note">
				{{ t('buildiq', 'The preview saves nothing and writes into no register.') }}
			</p>
			<button type="button" data-testid="journey-preview-restart" @click="restart">
				{{ t('buildiq', 'Start over') }}
			</button>
		</header>
		<CnJourney
			:key="generation"
			:journey="rendered"
			:store="store"
			@submitted="onSubmitted" />
		<p v-if="submitted" class="journey-preview__done" role="status" data-testid="journey-preview-submitted">
			{{ t('buildiq', 'Preview finished. Nothing was sent.') }}
		</p>
	</section>
</template>

<script>
import { CnJourney } from '@conduction/nextcloud-vue'
import { createPreviewRunStore, previewJourney } from '../../services/journeyPreview.js'

export default {
	name: 'JourneyPreviewPane',

	components: { CnJourney },

	props: {
		/** The journey as designed. */
		journey: {
			type: Object,
			default: () => ({ steps: [] }),
		},

		/** The form objects by id. */
		forms: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			generation: 0,
			store: createPreviewRunStore(String(this.journey.id || '')),
			submitted: false,
		}
	},

	computed: {
		/**
		 * The journey as the renderer takes it.
		 *
		 * @return {object}
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
		 */
		rendered() {
			return previewJourney(this.journey, this.forms)
		},
	},

	watch: {
		rendered: {
			deep: true,
			handler() {
				this.restart()
			},
		},
	},

	methods: {
		/**
		 * Throw the preview run away and mount the renderer again.
		 *
		 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
		 */
		restart() {
			this.store = createPreviewRunStore(String(this.journey.id || ''))
			this.submitted = false
			this.generation++
		},

		/** Note that the preview ran to its end. */
		onSubmitted() {
			this.submitted = true
		},
	},
}
</script>

<style scoped>
.journey-preview {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.journey-preview__head {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
}

.journey-preview__note {
	color: var(--color-text-maxcontrast);
	flex: 1 1 200px;
}
</style>
