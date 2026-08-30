import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
// SPDX-License-Identifier: EUPL-1.2
import { createApp } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'
import { registerDirectives } from './registerDirectives.js'

loadTranslations('buildiq', () => {
	// Vue 3 has no global Vue constructor — t/n, pinia and the global
	// directives are installed on this entry's own app instance.
	const app = createApp(AdminRoot)
	app.mixin({ methods: { t, n } })
	app.use(pinia)
	registerDirectives(app)
	app.mount('#buildiq-settings')
})
