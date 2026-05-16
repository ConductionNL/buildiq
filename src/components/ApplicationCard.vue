<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ApplicationCard — custom card for the Virtual apps index grid
  - (`pages[].config.cardComponent: "ApplicationCard"`). CnIndexPage
  - mounts one per row passing `{ item, object, schema, register, selected }`.
  - The card body is a `<router-link>` to VirtualAppDetail so a click navigates
  - directly to /applications/{objectId} — CnIndexPage's own `row-click`
  - event is emit-only (no auto-routing), so we own the navigation here.
  - Shows the virtual app's name, lifecycle-status pill, version, and the
  - caller's role.
  -->
<template>
	<div class="ob-app-card" :class="{ 'ob-app-card--selected': selected }">
		<div
			class="ob-app-card__inner"
			tabindex="0"
			role="link"
			@click="onCardActivate"
			@keyup.enter="onCardActivate">
			<div class="ob-app-card__head">
				<img
					class="ob-app-card__icon"
					:src="`/index.php/apps/openbuilt/icons/${app.slug}.svg`"
					:alt="app.name || app.slug"
					width="20"
					height="20"
					@error="onIconError">
				<h3 class="ob-app-card__title">
					{{ app.name || app.slug || t('openbuilt', 'Untitled app') }}
				</h3>
				<span class="ob-app-card__badge" :class="`ob-app-card__badge--${statusKey}`">{{ statusLabel }}</span>
			</div>
			<p v-if="app.description" class="ob-app-card__desc">
				{{ app.description }}
			</p>
			<div class="ob-app-card__meta">
				<span class="ob-app-card__chip">{{ t('openbuilt', 'Version') }} {{ app.version || '—' }}</span>
				<span v-if="role !== 'none'" class="ob-app-card__chip">{{ roleLabel }}</span>
				<span class="ob-app-card__chip ob-app-card__chip--muted">/{{ app.slug }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { useRole, getCurrentUserGroups } from '../composables/useRole.js'

export default {
	name: 'ApplicationCard',
	props: {
		// CnIndexPage passes the row both as `item` and `object`.
		object: { type: Object, default: null },
		item: { type: Object, default: null },
		selected: { type: Boolean, default: false },
	},
	emits: ['click', 'select'],
	computed: {
		app() {
			return this.object || this.item || {}
		},
		// CnDetailPage reads :objectId from $route.params, which we set here.
		// OR returns the canonical id under @self.id; fall back to uuid/id for
		// objects coming from older mock fixtures or pre-@self responses.
		appUuid() {
			const self = this.app['@self'] || {}
			return self.id || this.app.uuid || this.app.id || ''
		},
		statusKey() {
			return ['draft', 'published', 'archived'].includes(this.app.status) ? this.app.status : 'draft'
		},
		statusLabel() {
			return {
				draft: t('openbuilt', 'Draft'),
				published: t('openbuilt', 'Published'),
				archived: t('openbuilt', 'Archived'),
			}[this.statusKey]
		},
		role() {
			return useRole(this.app, getCurrentUserGroups())
		},
		roleLabel() {
			return {
				owner: t('openbuilt', 'Owner'),
				editor: t('openbuilt', 'Editor'),
				viewer: t('openbuilt', 'Viewer'),
			}[this.role] || ''
		},
	},
	methods: {
		onIconError(e) {
			e.target.src = '/apps/openbuilt/img/app.svg'
		},
		onCardActivate(event) {
			this.$emit('click', event)
			if (this.$router) {
				this.$router.push({ name: 'VirtualAppDetail', params: { objectId: this.appUuid } })
			}
		},
	},
}
</script>

<style scoped>
.ob-app-card {
	display: block;
}

.ob-app-card__inner {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px 14px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	cursor: pointer;
	background: var(--color-main-background, #fff);
	transition: border-color 0.1s ease, box-shadow 0.1s ease;
}

.ob-app-card__inner:hover,
.ob-app-card--selected .ob-app-card__inner {
	border-color: var(--color-primary-element, #0082c9);
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.ob-app-card__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ob-app-card__icon {
	width: 20px;
	height: 20px;
	object-fit: contain;
	flex-shrink: 0;
}

.ob-app-card__title {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.ob-app-card__desc {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.ob-app-card__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 2px;
}

.ob-app-card__chip {
	font-size: 11px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.ob-app-card__chip--muted {
	background: transparent;
	color: var(--color-text-maxcontrast, #888);
	font-family: monospace;
}

.ob-app-card__badge {
	font-size: 11px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.ob-app-card__badge--draft {
	background: var(--color-background-dark, #eee);
	color: var(--color-main-text, #222);
}

.ob-app-card__badge--published {
	background: var(--color-success-default-background, rgba(70, 186, 97, 0.2));
	color: var(--color-success-text, #2d8a3e);
}

.ob-app-card__badge--archived {
	background: var(--color-warning-default-background, rgba(201, 121, 0, 0.2));
	color: var(--color-warning-text, #8a5300);
}
</style>
