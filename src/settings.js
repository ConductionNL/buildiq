// SPDX-License-Identifier: EUPL-1.2
import { createApp, h } from 'vue'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'
import { registerDirectives } from './registerDirectives.js'

registerDirectives()

loadTranslations('openbuild', () => {
	const app = createApp({ render: () => h(AdminRoot) })
	app.use(pinia)
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.mount('#openbuild-settings')
})
