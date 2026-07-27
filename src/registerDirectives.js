// SPDX-License-Identifier: EUPL-1.2
import { VTooltip } from 'floating-vue'
import 'floating-vue/dist/style.css'

/**
 * Register the app's global directives on an app instance.
 *
 * Library components (CnCard, CnSchemaFormDialog, CnTabbedFormDialog, …) use
 * `v-tooltip` without registering the directive locally, so the host app must
 * install it globally.
 *
 * `Tooltip` used to come from `@nextcloud/vue`, but v9 (the Vue-3 line) no
 * longer exports it — that package now ships only `Focus` and `Linkify`. The
 * directive was always floating-vue's underneath, and floating-vue v5 (the
 * Vue-3 release) is still present in the tree, so we bind it directly. Without
 * this, every `v-tooltip` resolves to nothing and Vue warns at runtime.
 *
 * Vue 3 has no global `Vue` constructor — directives are per-app — so each
 * entry bootstrap (main / builder / settings) passes its own app instance.
 *
 * @param {import('vue').App} app The application instance to register on.
 * @return {void}
 */
export function registerDirectives(app) {
	// Add any further global directives here — this is the single place the app
	// installs them, so components can use them without a local registration.
	app.directive('tooltip', VTooltip)
}
