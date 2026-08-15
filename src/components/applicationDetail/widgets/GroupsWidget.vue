<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	GroupsWidget — flat list of Application `permissions.{owners,editors,viewers}`
	entries with role badges + member counts. REQ-OBADO-008.

	A click navigates to the permissions editor surface. The exact target
	(modal vs route) is verified at apply time — today we emit a
	`open-permissions` event so the parent surface can wire it to the
	existing PermissionsModal (src/modals/PermissionsModal.vue). The
	widget itself stays free of dialog-specific imports per ADR-004
	(modal isolation).
-->
<template>
	<div class="ob-groups-widget">
		<header class="ob-groups-widget__header">
			<h3 class="ob-groups-widget__title">
				{{ t('openbuild', 'Groups & users') }}
			</h3>
			<NcButton variant="tertiary" @click="openEditor">
				{{ t('openbuild', 'Manage permissions') }}
			</NcButton>
		</header>
		<ul v-if="rows.length > 0" class="ob-groups-widget__list">
			<li
				v-for="row in rows"
				:key="`${row.role}-${row.principal}`"
				class="ob-groups-widget__row">
				<span class="ob-groups-widget__row-name">{{ row.label }}</span>
				<span
					class="ob-groups-widget__row-role"
					:class="[`ob-groups-widget__row-role--${row.role}`]">
					{{ roleLabel(row.role) }}
				</span>
				<span class="ob-groups-widget__row-members">{{
					memberLabel(row)
				}}</span>
			</li>
		</ul>
		<p v-else class="ob-groups-widget__empty">
			{{ t('openbuild', 'No permissions configured.') }}
		</p>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'

const ROLES = ['owners', 'editors', 'viewers']

export default {
	name: 'GroupsWidget',
	components: { NcButton },
	props: {
		application: { type: Object, default: () => ({}) },
	},

	computed: {
		/**
		 * Flatten `permissions.{owners,editors,viewers}` into a row array.
		 *
		 * Each row carries `{ role, principal, label, isGroup }`.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-2
		 */
		rows() {
			const permissions =
				(this.application && this.application.permissions) || {}
			const out = []
			ROLES.forEach((role) => {
				const bucket = Array.isArray(permissions[role])
					? permissions[role]
					: []
				bucket.forEach((entry) => {
					if (typeof entry !== 'string' || !entry) {
						return
					}
					const isUser = entry.startsWith('user:')
					const isGroup = entry.startsWith('group:') || !isUser
					const label = isUser
						? entry.slice(5)
						: entry.startsWith('group:')
							? entry.slice(6)
							: entry
					out.push({ role, principal: entry, label, isGroup })
				})
			})
			return out
		},
	},

	methods: {
		/**
		 * Human-readable role label.
		 *
		 * @param {string} role Role key.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-2
		 */
		roleLabel(role) {
			if (role === 'owners') return t('openbuild', 'Owner')
			if (role === 'editors') return t('openbuild', 'Editor')
			return t('openbuild', 'Viewer')
		},

		/**
		 * Members label — "1" for user rows, group placeholder otherwise.
		 *
		 * @param {object} row Row.
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-2
		 */
		memberLabel(row) {
			if (!row.isGroup) {
				return t('openbuild', '1 user')
			}
			return t('openbuild', 'group')
		},

		/**
		 * Open the permissions editor for the Application.
		 *
		 * Today we emit `open-permissions` so the parent surface can wire
		 * it into the existing PermissionsModal. A future spec may swap
		 * this to a route.
		 *
		 * @return {void}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-2
		 */
		openEditor() {
			this.$emit('open-permissions', this.application)
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-groups-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-groups-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.ob-groups-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-groups-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-groups-widget__row {
	display: grid;
	grid-template-columns: 1fr auto auto;
	gap: 12px;
	align-items: center;
	padding: 8px 12px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover, #f5f5f5);
}

.ob-groups-widget__row-role {
	font-size: 12px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-dark, #eee);
}

.ob-groups-widget__row-role--owners {
	background: var(--color-warning-hover);
	color: var(--color-warning-text);
}

.ob-groups-widget__row-role--editors {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.ob-groups-widget__row-role--viewers {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.ob-groups-widget__row-members {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-groups-widget__empty {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
	font-style: italic;
}
</style>
