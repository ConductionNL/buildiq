// SPDX-License-Identifier: EUPL-1.2
import { createApp } from 'vue'
import {
	translate as t,
	translatePlural as n,
	loadTranslations,
} from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'
import { registerDirectives } from './registerDirectives.js'

loadTranslations('openbuild', () => {
	// Vue 3 has no global Vue constructor — t/n, pinia and the global
	// directives are installed on this entry's own app instance.
	const app = createApp(AdminRoot)
	app.mixin({ methods: { t, n } })
	app.use(pinia)
	registerDirectives(app)
	app.mount('#openbuild-settings')
})
