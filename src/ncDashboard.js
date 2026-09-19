// SPDX-License-Identifier: EUPL-1.2
//
// ncDashboard.js: the browser half of a promoted virtual-app widget.
//
// `VirtualAppWidget::load()` hands this entry the descriptors the signed-in
// user may see, as server-provided initial state, then adds this script. The
// entry loops them and calls `OCA.Dashboard.register(id, cb)` once per
// descriptor, exactly as procest's `src/myTasksWidget.js` does for its own
// widgets.
//
// Rendering goes through the shared library's `CnWidgetWrapper` with
// `chrome="nc-dashboard"`, the variant built to match the native panel's
// design tokens. No panel styling is written here: a hand-rolled panel that is
// nearly right is worse than one that is exactly right, because nobody notices
// the drift until a Nextcloud theme update moves the tokens.
//
// Three lessons `src/builder.js` already learned are repeated here because all
// three fail SILENTLY:
//
//  1. `registerBuiltinDashboardWidgets()` must be called. The library
//     self-registers its widget types through bare side-effect imports in its
//     barrel, which webpack is free to drop and does. Without the explicit
//     call the registry is empty and every widget renders "Widget unavailable".
//  2. `gridstack/dist/gridstack.min.css` must be imported. It is a
//     peerDependency since nc-vue#557, and without it grid items render 0px
//     wide.
//  3. `publicPath: 'auto'` is set globally in `webpack.config.js`. Leave it. A
//     hardcoded `/apps/{appId}/js/` breaks async chunks under `custom_apps/`.

import {
	CnWidgetWrapper,
	getWidgetTypeEntry,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { NcEmptyContent } from '@nextcloud/vue'
import { createApp, h } from 'vue'
import pinia from './pinia.js'
import { registerDirectives } from './registerDirectives.js'
import { runtimeRegistry } from './runtimeRegistry.js'

import '@conduction/nextcloud-vue/css/index.css'
import '@nextcloud/dialogs/style.css'
// See lesson 2 above. Without this every grid item renders 0px wide.
import 'gridstack/dist/gridstack.min.css'
import './assets/app.css'

/**
 * Resolve the Vue component a descriptor's `widgetKey` names.
 *
 * Same order `CnWidgetGrid` applies: the library's built-in catalog first,
 * then the consumer registry, which wins last so an app can skin one widget
 * without mutating the shared registry.
 *
 * @param {object} descriptor A promoted widget descriptor.
 * @param {object} [registry] The consumer registry to resolve against.
 * @return {object|null} The component, or null when the key resolves to nothing.
 */
export function resolveWidgetComponent(descriptor, registry = runtimeRegistry) {
	const key = descriptor && descriptor.widgetKey
	if (!key) {
		return null
	}
	const override = registry && registry[key]
	if (override) {
		return override.component || override
	}
	const entry = getWidgetTypeEntry(key)
	return (entry && entry.renderer) || null
}

/**
 * Build the root component for one promoted widget.
 *
 * The wrapper's own title is switched OFF: Nextcloud already renders the panel
 * header from `IWidget::getTitle()` and `IIconWidget::getIconUrl()`, so
 * letting the wrapper draw a second one gives the panel two titles.
 *
 * @param {object} descriptor A promoted widget descriptor.
 * @param {object} [registry] The consumer registry to resolve against.
 * @return {object} A Vue component definition.
 */
export function createNcDashboardWidget(descriptor, registry = runtimeRegistry) {
	const component = resolveWidgetComponent(descriptor, registry)

	return {
		name: 'BuildiqNcDashboardWidget',
		render() {
			return h(
				CnWidgetWrapper,
				{
					chrome: 'nc-dashboard',
					title: descriptor.title || '',
					showTitle: false,
					showActions: false,
					widgetId: descriptor.id,
				},
				{
					default: () =>
						component
							? h(component, {
								...(descriptor.props || {}),
								content: descriptor.props || {},
								dataSource: descriptor.dataSource || {},
							})
							// Visible as UNKNOWN, never as a blank panel. A blank
							// panel reads as "this widget has nothing to show"
							// and sends nobody looking for the missing key.
							: h(NcEmptyContent, {
								name: t('buildiq', 'Widget unavailable'),
								description: descriptor.widgetKey || '',
							}),
				},
			)
		},
	}
}

/**
 * Register every descriptor with Nextcloud's dashboard.
 *
 * @param {Array<object>} descriptors The descriptors this user may see.
 * @param {object} [dashboard] The `OCA.Dashboard` registry.
 * @return {number} How many widgets were registered.
 */
export function registerNcDashboardWidgets(descriptors, dashboard) {
	if (!dashboard || typeof dashboard.register !== 'function') {
		return 0
	}

	let registered = 0
	for (const descriptor of descriptors) {
		if (!descriptor || !descriptor.id) {
			continue
		}
		dashboard.register(descriptor.id, (el) => {
			const app = createApp(createNcDashboardWidget(descriptor))
			app.mixin({ methods: { t, n } })
			app.use(pinia)
			registerDirectives(app)
			app.mount(el)
		})
		registered++
	}

	return registered
}

// Boot. Guarded so importing this module in a test does not need a dashboard.
if (typeof OCA !== 'undefined' && OCA && OCA.Dashboard) {
	registerIcons()
	// See lesson 1 above. Without this the catalog is empty and every widget
	// renders "Widget unavailable".
	registerBuiltinDashboardWidgets()
	try {
		registerTranslations()
	} catch (e) {
		// eslint-disable-next-line no-console
		console.warn(
			'[buildiq:ncDashboard] registerTranslations failed; lib strings fall back to English source',
			e,
		)
	}

	registerNcDashboardWidgets(
		loadState('buildiq', 'ncDashboardWidgets', []),
		OCA.Dashboard,
	)
}
