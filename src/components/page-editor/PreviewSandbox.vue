<!--
  SPDX-FileCopyrightText: 2026 Buildiq Contributors
  SPDX-License-Identifier: EUPL-1.2

  PreviewSandbox — mounts the live preview as its own Vue application.

  CnAppRoot renders the page body through `<router-view>`, and CnPageRenderer
  resolves which page to render from `$route.name`. Mounted as an ordinary
  child of the designer, both of those see Buildiq's router: the nested view
  sits one level deeper than anything in that flat route table, so no page
  body renders at all, and a menu click navigates the designer itself away.

  Giving the preview its own app with a memory-history router built from the
  manifest under edit fixes both — the shell routes against the pages being
  designed, and nothing it does reaches the page around it.
-->
<template>
	<div ref="host" class="preview-sandbox" />
</template>

<script>
import { CnAppRoot, CnPageRenderer } from '@conduction/nextcloud-vue'
import {
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { createPinia, setActivePinia } from 'pinia'
import { createApp, h, shallowReactive } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import designerPinia from '../../pinia.js'
import { registerDirectives } from '../../registerDirectives.js'

// Own component identity per route record: the barrel export is a frozen
// module record and the router keeps bookkeeping on the object it is handed.
const PreviewPageRenderer = { ...CnPageRenderer }

// What a preview may still do: navigate inside itself, work the affordances
// that only reveal UI, and take input. `aria-expanded` / `aria-haspopup` are
// what a disclosure widget IS — action menus, nav groups, comboboxes all
// carry one — so the allow-list stays behavioural rather than a list of
// Nextcloud class names. Everything else acts on real data.
const HARMLESS_TARGETS = [
	'a[href]',
	'[aria-expanded]',
	// Not `dialog`: a modal is where the real actions live.
	'[aria-haspopup]:not([aria-haspopup="dialog"])',
	'[role="tab"]',
	'input',
	'textarea',
	'select',
	'label',
	'[contenteditable="true"]',
].join(',')

export default {
	name: 'PreviewSandbox',

	props: {
		appId: {
			type: String,
			required: true,
		},

		manifest: {
			type: Object,
			required: true,
		},

		registry: {
			type: Object,
			default: () => ({}),
		},

		customComponents: {
			type: Object,
			default: () => ({}),
		},

		pageTypes: {
			type: Object,
			default: () => ({}),
		},

		translate: {
			type: Function,
			default: null,
		},

		permissions: {
			type: [Array, Object],
			default: () => [],
		},
	},

	computed: {
		/**
		 * The prop bag handed to the sandboxed CnAppRoot.
		 *
		 * @return {object} CnAppRoot props.
		 */
		rootProps() {
			return {
				appId: this.appId,
				// The flag CnAppRoot reads to decide whether to publish the
				// Buildiq edit button. There is no editing a preview.
				manifest: { ...this.manifest, openbuildEditable: false },
				registry: this.registry,
				customComponents: this.customComponents,
				pageTypes: this.pageTypes,
				translate: this.translate,
				permissions: this.permissions,
			}
		},

		/**
		 * Identity of the route table the manifest currently describes. Only a
		 * change here needs a new router; every other edit flows through as a
		 * prop update, so typing in an editor does not re-mount the preview.
		 *
		 * @return {string} signature over every page id and route.
		 */
		routeSignature() {
			return this.previewPages()
				.map((page) => `${page.id}@${page.route}`)
				.join('|')
		},
	},

	watch: {
		routeSignature() {
			this.createSandbox()
		},

		rootProps(props) {
			if (this._sandboxState) {
				Object.assign(this._sandboxState, props)
			}
		},
	},

	mounted() {
		// On the document, not the host: an open action menu is teleported to
		// <body> and would never be seen from inside the preview's own subtree.
		document.addEventListener('click', this.blockActivation, true)
		document.addEventListener('keydown', this.blockActivation, true)
		this.createSandbox()
	},

	beforeUnmount() {
		document.removeEventListener('click', this.blockActivation, true)
		document.removeEventListener('keydown', this.blockActivation, true)
		this.destroySandbox()
	},

	methods: {
		/**
		 * Whether an event belongs to the preview.
		 *
		 * Most do so by sitting inside the host. An open action menu does not:
		 * it is teleported to `<body>`, and the only link back is the trigger,
		 * which points at the menu's id through `aria-controls` while it is
		 * open (NcActions) or `aria-owns`.
		 *
		 * @param {EventTarget} target The event's target.
		 * @return {boolean} true when the preview owns it.
		 */
		ownsTarget(target) {
			const host = this.$refs.host
			if (!host || !target?.nodeType) {
				return false
			}
			if (host.contains(target)) {
				return true
			}
			for (let el = target; el; el = el.parentElement) {
				if (!el.id) {
					continue
				}
				const id = window.CSS?.escape ? window.CSS.escape(el.id) : el.id
				if (
					host.querySelector(`[aria-controls="${id}"], [aria-owns="${id}"]`)
				) {
					return true
				}
			}
			return false
		},

		/**
		 * Swallow activations that would do something. A preview should still
		 * feel like the app — menus open, groups expand, fields take input —
		 * but its refresh, "Add …" and save buttons run against real data, so
		 * those never reach the component.
		 *
		 * Capture phase, so nothing inside sees a blocked event. floating-vue
		 * listens on `window` in capture, which is ahead of the document, so an
		 * open menu still closes when you click away from it.
		 *
		 * @param {MouseEvent|KeyboardEvent} event The activation to judge.
		 * @return {void}
		 */
		blockActivation(event) {
			// Only the two keys that activate a control; Escape, arrows and
			// Tab belong to the affordances above. Checked first so a keystroke
			// anywhere in the designer costs one comparison.
			if (
				event.type === 'keydown'
				&& event.key !== 'Enter'
				&& event.key !== ' '
			) {
				return
			}
			if (!this.ownsTarget(event.target)) {
				return
			}
			if (event.target?.closest?.(HARMLESS_TARGETS)) {
				return
			}
			event.preventDefault()
			event.stopPropagation()
		},

		/**
		 * Manifest pages that can become a route.
		 *
		 * @return {object[]} pages carrying both an id and a route.
		 */
		previewPages() {
			const pages = Array.isArray(this.manifest?.pages)
				? this.manifest.pages
				: []
			return pages.filter((page) => page && page.id && page.route)
		},

		/**
		 * Route table for the sandbox, mirroring `routesFromManifest()` in
		 * main.js: one route per page, named by page id, rendering
		 * CnPageRenderer.
		 *
		 * @return {object[]} vue-router 4 route records.
		 */
		buildRoutes() {
			const seen = new Set()
			const routes = []
			for (const page of this.previewPages()) {
				if (seen.has(page.id)) {
					continue
				}
				seen.add(page.id)
				routes.push({
					name: page.id,
					path: page.route,
					component: PreviewPageRenderer,
					props: page.route.includes(':'),
				})
			}
			// Memory history starts at "/", which an app need not declare, so
			// land on the first page that takes no parameters.
			const landing = routes.find((route) => !route.path.includes(':'))
			if (landing) {
				routes.push({ path: '/:catchAll(.*)', redirect: landing.path })
			}
			return routes
		},

		/**
		 * (Re)create the preview application.
		 *
		 * @return {void}
		 */
		createSandbox() {
			const previousPath = this._sandboxRouter?.currentRoute?.value?.fullPath
			this.destroySandbox()
			const host = this.$refs.host
			if (!host) {
				return
			}

			const state = shallowReactive({ ...this.rootProps })
			const router = createRouter({
				history: createMemoryHistory(),
				routes: this.buildRoutes(),
			})
			const app = createApp({ render: () => h(CnAppRoot, state) })
			app.mixin({ methods: { t, n } })
			// Its own store instances, so a preview never writes over the
			// designer's state.
			app.use(createPinia())
			app.use(router)
			registerDirectives(app)

			if (previousPath && previousPath !== '/') {
				router.replace(previousPath).catch(() => {})
			}
			app.mount(host)
			// Installing a pinia also makes it the active one process-wide. Put
			// the designer's back, or a store resolved outside a component would
			// come from the preview.
			setActivePinia(designerPinia)

			this._sandboxApp = app
			this._sandboxRouter = router
			this._sandboxState = state
		},

		/**
		 * Tear the preview application down.
		 *
		 * @return {void}
		 */
		destroySandbox() {
			if (this._sandboxApp) {
				this._sandboxApp.unmount()
			}
			this._sandboxApp = null
			this._sandboxRouter = null
			this._sandboxState = null
		},
	},
}
</script>

<style scoped>
.preview-sandbox {
	width: 100%;
	height: 100%;
}

/* NcContent sizes itself off the window — `width: calc(100% - body margins)`
   and `height: var(--body-height)`, both meant for a full page. In here it has
   to fill the scaled preview viewport instead, which is what its `position:
   fixed` already resolves against. */
.preview-sandbox :deep(.content) {
	width: 100%;
	height: 100%;
}
</style>
