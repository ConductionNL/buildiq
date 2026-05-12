// SPDX-License-Identifier: EUPL-1.2
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import router from './router/index.js'
import App from './App.vue'
import { initializeStores } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)

// Fire-and-forget translation load. `@nextcloud/l10n`'s loadTranslations()
// fetches l10n/<locale>.json (e.g. en_US.json) and the returned promise
// rejects on a 404. The app ships en.json / en_US.json / nl.json; any other
// locale just falls back to the English source strings. Boot MUST NOT depend
// on this resolving — chaining $mount inside loadTranslations meant the whole
// app silently failed to render whenever the locale bundle was missing.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('openbuilt', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op — translations are best-effort
	}
}

tryLoadTranslations()

// Create the Vue instance, activate Pinia, mount, then initialise stores.
const app = new Vue({
	pinia,
	router,
	render: h => h(App),
})

// Mount immediately so the App renders (NC32 needs #content to be taken over).
app.$mount('#content')

// Initialise stores after mount. (App.vue also calls this in created() — it is
// idempotent, so the two calls are safe; this one keeps the contract explicit.)
initializeStores()
