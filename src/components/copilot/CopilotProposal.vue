<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  CopilotProposal — one assistant turn in the builder copilot panel (spec
  `ai-copilot` REQ-OBAIC-003/007): the proposed step list (tool + key
  arguments per row) plus a before/after manifest diff (reusing
  ManifestDiff.vue in its static-blob mode), with Approve (disabled while
  `canApprove` is false) and Discard actions. No request is ever sent from
  here directly — the parent CopilotPanel owns the network calls so this
  component stays a pure, testable presentation piece.
-->
<template>
	<div data-testid="copilot-proposal" class="copilot-proposal">
		<p class="copilot-proposal__summary">
			{{ plan.summary }}
		</p>

		<ul class="copilot-proposal__steps">
			<li
				v-for="(step, idx) in plan.steps"
				:key="idx"
				class="copilot-proposal__step">
				<code>{{ step.tool }}</code>
				<span class="copilot-proposal__step-detail">{{
					stepDetail(step)
				}}</span>
			</li>
		</ul>

		<ManifestDiff
			v-for="(pair, versionKey) in plan.manifests"
			:key="versionKey"
			:from-manifest="pair.current"
			:to-manifest="pair.predicted"
			:from-label-text="t('openbuild', 'Current')"
			:to-label-text="t('openbuild', 'Predicted')" />

		<p v-if="!canApprove" class="copilot-proposal__error" role="alert">
			{{
				t(
					'openbuild',
					'This proposal did not pass validation and cannot be applied.',
				)
			}}
		</p>

		<div class="copilot-proposal__actions">
			<NcButton
				data-testid="copilot-discard"
				:disabled="busy"
				@click="$emit('discard')">
				{{ t('openbuild', 'Discard') }}
			</NcButton>
			<NcButton
				data-testid="copilot-approve"
				type="primary"
				:disabled="!canApprove || busy"
				@click="$emit('approve')">
				{{ busy ? t('openbuild', 'Applying…') : t('openbuild', 'Approve') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ManifestDiff from '../ManifestDiff.vue'

export default {
	name: 'CopilotProposal',

	components: { NcButton, ManifestDiff },

	props: {
		/** The plan `{summary, steps, manifests}` returned by /api/copilot/plan. */
		plan: {
			type: Object,
			required: true,
		},
		/** False while any predicted manifest fails the canonical validator. */
		canApprove: {
			type: Boolean,
			default: false,
		},
		/** True while the approved plan is executing. */
		busy: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['approve', 'discard'],

	methods: {
		/**
		 * A compact one-line summary of a step's key arguments for the step list row.
		 *
		 * @param {{tool: string, arguments: object}} step - a plan step.
		 * @return {string}
		 * @spec openspec/changes/ai-copilot-prompt-to-app/specs/ai-copilot/spec.md
		 */
		stepDetail(step) {
			const args = step.arguments || {}
			return (
				args.title
				|| args.label
				|| args.pageId
				|| args.slug
				|| args.appSlug
				|| ''
			)
		},
	},
}
</script>

<style scoped>
.copilot-proposal {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.copilot-proposal__summary {
	font-weight: 600;
	margin: 0;
}

.copilot-proposal__steps {
	margin: 0;
	padding-left: 20px;
}

.copilot-proposal__step-detail {
	color: var(--color-text-maxcontrast);
	margin-left: 4px;
}

.copilot-proposal__error {
	color: var(--color-error);
	margin: 0;
}

.copilot-proposal__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
