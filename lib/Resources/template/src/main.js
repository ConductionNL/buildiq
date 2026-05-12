// SPDX-License-Identifier: EUPL-1.2
//
// Tier-4 manifest consumer entrypoint per ADR-024.
//
// Boots a thin Vue instance whose root component is CnAppRoot. The bundled
// manifest (./manifest.json, written by PlaceholderResolver at export time)
// is registered with useAppManifest() — this is the in-process overload
// added by chain spec #2 (openbuilt-manifest-runtime), which avoids a
// network round-trip and gives CnAppRoot a synchronous source of truth for
// navigation / pages / deep-links.
//
// Once the manifest covers the app's UX surface (it always does — that is
// the Tier-4 contract), no other view files are needed in the exported app.
//
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { useAppManifest } from '@conduction/nextcloud-vue'

import pinia from './pinia.js'
import App from './App.vue'
import manifest from './manifest.json'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages).
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles.
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// Register the bundled manifest with the runtime before the root component
// mounts; CnAppRoot reads from this registry. The chain-spec-#2 overload
// signature is { manifest } (in-process JS object), not a URL fetch.
useAppManifest({ manifest })

loadTranslations(manifest.id, () => {
	const app = new Vue({
		pinia,
		render: h => h(App),
	})

	app.$mount('#content')
})
