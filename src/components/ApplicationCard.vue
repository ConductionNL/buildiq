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
	<div
		v-if="!hiddenByFilter"
		class="ob-app-card"
		:class="{ 'ob-app-card--selected': selected }">
		<div
			class="ob-app-card__inner"
			tabindex="0"
			role="link"
			@click="onCardActivate"
			@keyup.enter="onCardActivate">
			<div class="ob-app-card__head">
				<img
					class="ob-app-card__icon"
					:src="`/index.php/apps/buildiq/icons/${app.slug}.svg`"
					:alt="app.name || app.slug"
					width="20"
					height="20"
					@error="onIconError" />
				<h3 class="ob-app-card__title">
					{{ app.name || app.slug || t('buildiq', 'Untitled app') }}
				</h3>
				<span
					class="ob-app-card__type"
					:class="`ob-app-card__type--${appTypeKey}`"
					>{{ appTypeLabel }}</span
				>
				<span
					class="ob-app-card__badge"
					:class="`ob-app-card__badge--${statusKey}`"
					>{{ statusLabel }}</span
				>
			</div>
			<p v-if="app.description" class="ob-app-card__desc">
				{{ app.description }}
			</p>
			<div class="ob-app-card__meta">
				<span class="ob-app-card__chip"
					>{{ t('buildiq', 'Version') }} {{ productionSemver }}</span
				>
				<span v-if="role !== 'none'" class="ob-app-card__chip">{{
					roleLabel
				}}</span>
				<span class="ob-app-card__chip ob-app-card__chip--muted"
					>/{{ app.slug }}</span
				>
			</div>
		</div>
	</div>
</template>

<script>
import { imagePath } from '@nextcloud/router'
import { getCurrentUserGroups, useRole } from '../composables/useRole.js'
import {
	ensureProductionVersionsLoaded,
	productionVersions,
} from '../store/productionVersions.js'

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
		/**
		 * Observed behaviour of `app` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		app() {
			return this.object || this.item || {}
		},

		/**
		 * Resolve the production ApplicationVersion this card reports on.
		 *
		 * Spec C moved `status` and `semver` from Application onto
		 * ApplicationVersion, so the badge and version chip must read them from
		 * the version, not the Application.
		 *
		 * Two shapes are accepted, in order:
		 *
		 *   1. `productionVersionDetail` — the resolved `{uuid, slug, name,
		 *      semver, status}` projection that `ApplicationsController::listMine`
		 *      attaches. This is the real path in the running app.
		 *   2. an inline `productionVersion` OBJECT — the legacy
		 *      `?extend=productionVersion` shape, kept so a caller that embeds
		 *      the version (and the component's own unit fixtures) still work.
		 *
		 * A bare `productionVersion` UUID STRING resolves to null, because a
		 * string carries neither field. That used to be the ONLY shape this
		 * endpoint ever returned, which is why every card in the list read
		 * "Draft / Version —" regardless of the app's real lifecycle state —
		 * hello-world showed Draft while its production version was
		 * `{status: 'published', semver: '1.0.0'}`. The fix is the resolved
		 * field above; this computed just has to prefer it.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/openbuild-runtime/spec.md#req-obr-007b
		 */
		productionVersion() {
			// 1. An inline object — the legacy `?extend=productionVersion` shape,
			//    and what the component's unit fixtures pass.
			const pv = this.app.productionVersion
			if (pv && typeof pv === 'object') {
				return pv
			}
			// 2. A UUID string — what this data path actually delivers. Resolve it
			//    through the page-wide version index (src/store/productionVersions.js).
			if (typeof pv === 'string' && pv !== '') {
				return productionVersions[pv] || null
			}
			return null
		},

		/**
		 * Semver string from the production ApplicationVersion, or '—' while
		 * loading / when the application has no production version yet.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		productionSemver() {
			return (this.productionVersion && this.productionVersion.semver) || '—'
		},

		// CnDetailPage reads :objectId from $route.params, which we set here.
		// OR returns the canonical id under @self.id; fall back to uuid/id for
		// objects coming from older mock fixtures or pre-@self responses.
		/**
		 * Observed behaviour of `appUuid` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		appUuid() {
			const self = this.app['@self'] || {}
			return self.id || this.app.uuid || this.app.id || ''
		},

		/**
		 * The app's type discriminator (unify-apps-with-app-type). An absent
		 * `appType` reads as `virtual` (legacy default), matching the schema.
		 *
		 * @return {string} 'virtual' | 'hybrid'
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		appTypeKey() {
			return this.app.appType === 'hybrid' ? 'hybrid' : 'virtual'
		},

		/**
		 * Human-readable label for the app type pill.
		 *
		 * @return {string}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		appTypeLabel() {
			return this.appTypeKey === 'hybrid'
				? t('buildiq', 'Hybrid')
				: t('buildiq', 'Virtual')
		},

		/**
		 * Whether this card is hidden by the active all/virtual/hybrid filter,
		 * read from the `?filter=` URL query param (set by VirtualAppsActions and
		 * persisted in the URL so a filtered view is shareable). `all` or an
		 * absent param shows everything.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		hiddenByFilter() {
			const filter =
				this.$route && this.$route.query ? this.$route.query.filter : null
			if (!filter || filter === 'all') {
				return false
			}
			return filter !== this.appTypeKey
		},

		/**
		 * Status key resolved from productionVersion (spec C). Falls back to
		 * 'draft' when no production version is present so the card has a
		 * sensible default while loading or for brand-new applications.
		 *
		 * @return {string}
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		statusKey() {
			const status = this.productionVersion && this.productionVersion.status
			return ['draft', 'published', 'archived'].includes(status)
				? status
				: 'draft'
		},

		/**
		 * Observed behaviour of `statusLabel` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		statusLabel() {
			return {
				draft: t('buildiq', 'Draft'),
				published: t('buildiq', 'Published'),
				archived: t('buildiq', 'Archived'),
			}[this.statusKey]
		},

		/**
		 * Observed behaviour of `role` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		role() {
			return useRole(this.app, getCurrentUserGroups())
		},

		/**
		 * Observed behaviour of `roleLabel` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		roleLabel() {
			return (
				{
					owner: t('buildiq', 'Owner'),
					editor: t('buildiq', 'Editor'),
					viewer: t('buildiq', 'Viewer'),
				}[this.role] || ''
			)
		},
	},

	/**
	 * Kick off the page-wide production-version lookup.
	 *
	 * Shared and de-duplicated, so a grid of N cards issues ONE request, not N.
	 * The store is reactive, so cards that render before it settles re-render
	 * with the real status and semver when it does.
	 *
	 * @return {void}
	 * @spec openspec/specs/openbuild-runtime/spec.md#req-obr-007b
	 */
	created() {
		ensureProductionVersionsLoaded()
	},

	methods: {
		/**
		 * Observed behaviour of `onIconError` (retrofit annotation).
		 *
		 * @param {Event} e - The `<img>` `error` event for the app icon (the app has no
		 *   uploaded icon, or its URL 404s). `e.target` is swapped to the bundled
		 *   fallback icon at most once — see the re-entry guard below.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		onIconError(e) {
			// imagePath resolves to the app's real web root (e.g.
			// /apps/buildiq/img/app.svg, or /apps-shared/… in dev). The previous
			// hardcoded '/apps/buildiq/img/app.svg' 404s when the web root differs,
			// which re-fired this error handler and re-set the same failing src in an
			// infinite loop (spamming the request + draining resources).
			const fallback = imagePath('buildiq', 'app.svg')
			// Guard against re-entry: if the fallback itself fails to load, the error
			// event lands here again — bail once we're already showing the fallback
			// so we swap the src at most once. Compare the literal attribute (not the
			// resolved .src property, which is absolute) against the fallback path.
			if (e.target.getAttribute('src') === fallback) {
				return
			}
			e.target.src = fallback
		},

		/**
		 * Observed behaviour of `onCardActivate` (retrofit annotation).
		 *
		 * @param {MouseEvent|KeyboardEvent} event - The activation event — a card
		 *   `click`, or `keyup.enter` on the focused card (the keyboard path). Re-emitted
		 *   verbatim as `click` for parents that want to intercept, before this card
		 *   navigates to the application's detail route itself.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-3
		 */
		onCardActivate(event) {
			this.$emit('click', event)
			if (this.$router) {
				this.$router.push({
					name: 'VirtualAppDetail',
					params: { objectId: this.appUuid },
				})
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
	transition:
		border-color 0.1s ease,
		box-shadow 0.1s ease;
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

.ob-app-card__type {
	font-size: 11px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-left: auto;
}

.ob-app-card__type--virtual {
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.15));
	color: var(--color-primary-element, #0082c9);
}

.ob-app-card__type--hybrid {
	background: var(--color-background-dark, #eee);
	color: var(--color-text-maxcontrast, #555);
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

/*
 * WCAG 2.2 AA 2.3.3 Animation from Interactions. The card animates its border
 * and shadow on hover/selection; honour an OS-level reduced-motion preference.
 * Scoped to this card's own selector so it cannot reach into NC component
 * internals.
 */
@media (prefers-reduced-motion: reduce) {
	.ob-app-card__inner {
		transition: none;
	}
}
</style>
