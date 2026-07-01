// SPDX-License-Identifier: EUPL-1.2
import Vue from 'vue'
import { PiniaVuePlugin } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import pinia from './pinia.js'
import AdminRoot from './views/settings/AdminRoot.vue'
import { registerDirectives } from './registerDirectives.js'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
registerDirectives()

loadTranslations('openbuild', () => {
	new Vue({
		pinia,
		render: h => h(AdminRoot),
	}).$mount('#openbuild-settings')
})
