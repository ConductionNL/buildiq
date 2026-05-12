// SPDX-License-Identifier: EUPL-1.2
import Vue from 'vue'
import Router from 'vue-router'
import { generateUrl } from '@nextcloud/router'
import Dashboard from '../views/Dashboard.vue'
import ApplicationEditor from '../views/ApplicationEditor.vue'
import BuilderHost from '../views/BuilderHost.vue'

// NOTE: AdminRoot is intentionally NOT registered as a vue-router route.
// Admin settings are mounted via the separate `openbuilt-settings.js` bundle
// loaded by `templates/settings/admin.php` and reached through Nextcloud's
// admin settings framework (/index.php/settings/admin/openbuilt). Exposing
// admin settings as an in-app route bypasses the admin auth gate — see
// ADR-004 hard rule + hydra-gate-admin-router.

Vue.use(Router)

export default new Router({
	mode: 'history',
	base: generateUrl('/apps/openbuilt'),
	routes: [
		{ path: '/', name: 'Dashboard', component: Dashboard },
		// Manifest editor (textarea v1). Visual editor lands in chain spec #5.
		{ path: '/applications', name: 'ApplicationEditor', component: ApplicationEditor },
		// Virtual-app host. The trailing wildcard forwards path segments to
		// the inner CnAppRoot's router (per design.md Decision 5).
		{ path: '/builder/:slug/:pathMatch(.*)?', name: 'BuilderHost', component: BuilderHost },
		{ path: '*', redirect: '/' },
	],
})
