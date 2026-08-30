<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	FlowsWidget — list the OpenRegister flows bound to this app, one row each.

	The fourth of the four structure tables on the app detail page, beside
	PagesWidget / MenuWidget / SchemasWidget. Business logic is the fourth part
	of an app (navigation, pages, data, logic), so it belongs on this page for
	the same reason the other three do: the app should be buildable from here
	without opening the running app.

	Flows live in OpenRegister, not in the manifest, so rows deep-link into
	OpenRegister rather than into a Buildiq designer.
-->
<template>
	<div class="ob-flows-widget">
		<header class="ob-flows-widget__header">
			<h3 class="ob-flows-widget__title">
				{{ t('buildiq', 'Flows') }}
			</h3>
		</header>
		<ul v-if="flows && flows.length > 0" class="ob-flows-widget__list">
			<li
				v-for="flow in flows"
				:key="flowKey(flow)"
				class="ob-flows-widget__row"
				role="button"
				tabindex="0"
				@click="openFlow(flow)"
				@keyup.enter="openFlow(flow)"
				@keyup.space="openFlow(flow)">
				<span class="ob-flows-widget__row-name">{{ flowName(flow) }}</span>
				<span class="ob-flows-widget__row-trigger">{{
					flow.trigger || '—'
				}}</span>
				<span class="ob-flows-widget__row-state">{{ flowState(flow) }}</span>
			</li>
		</ul>
		<p v-else class="ob-flows-widget__empty">
			{{ t('buildiq', 'No flows bound to this app yet.') }}
		</p>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'FlowsWidget',

	props: {
		// No appSlug: a flow row deep-links into OpenRegister, which owns the
		// flow, so nothing here is scoped by the Buildiq app slug.
		flows: { type: Array, default: () => [] },
	},

	methods: {
		/**
		 * A stable list key for a flow.
		 *
		 * Flows come from OpenRegister, where the uuid is the identity and the
		 * name is user-editable, so the uuid is preferred.
		 *
		 * @param {object} flow The flow record.
		 *
		 * @return {string}
		 *
		 * @spec exclude presentational key helper on a display-only widget
		 */
		flowKey(flow) {
			return String((flow && (flow.uuid || flow.id || flow.name)) || '')
		},

		/**
		 * The label to show for a flow.
		 *
		 * @param {object} flow The flow record.
		 *
		 * @return {string}
		 *
		 * @spec exclude presentational label helper on a display-only widget
		 */
		flowName(flow) {
			return String((flow && (flow.name || flow.uuid)) || '—')
		},

		/**
		 * Whether the flow is currently enabled, as a readable word.
		 *
		 * `enabled` is absent on older records. Absent is NOT the same as
		 * disabled, so an unknown state says so rather than defaulting to the
		 * innocent-looking answer.
		 *
		 * @param {object} flow The flow record.
		 *
		 * @return {string}
		 *
		 * @spec exclude presentational state helper on a display-only widget
		 */
		flowState(flow) {
			if (!flow || flow.enabled === undefined || flow.enabled === null) {
				return this.t('buildiq', 'Unknown')
			}

			return flow.enabled
				? this.t('buildiq', 'Enabled')
				: this.t('buildiq', 'Disabled')
		},

		/**
		 * Open a flow in OpenRegister.
		 *
		 * Flows are OpenRegister objects, so editing them belongs there. This
		 * widget lists them and hands off; it does not wrap OpenRegister's own
		 * editor (ADR-022).
		 *
		 * @param {object} flow The flow record.
		 *
		 * @return {void}
		 *
		 * @spec exclude deep-link hand-off to OpenRegister, no app-local behaviour
		 */
		openFlow(flow) {
			const uuid = flow && (flow.uuid || flow.id)
			if (!uuid) {
				return
			}

			window.open(
				generateUrl('/apps/openregister/flows/{uuid}', {
					uuid: String(uuid),
				}),
				'_blank',
				'noopener,noreferrer',
			)
		},
	},
}
</script>

<style scoped>
.ob-flows-widget {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
}

.ob-flows-widget__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	margin-bottom: 8px;
}

.ob-flows-widget__title {
	font-size: 15px;
	font-weight: 600;
	margin: 0;
}

.ob-flows-widget__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.ob-flows-widget__row {
	display: grid;
	grid-template-columns: 2fr 1fr auto;
	gap: 8px;
	align-items: center;
	padding: 6px 4px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.ob-flows-widget__row:hover,
.ob-flows-widget__row:focus-visible {
	background: var(--color-background-hover);
}

.ob-flows-widget__row-name {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.ob-flows-widget__row-trigger,
.ob-flows-widget__row-state {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.ob-flows-widget__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: 0;
}
</style>
