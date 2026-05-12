// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// applicationContext — mixin for the VirtualAppDetail page's tab + action
// components (manifest editor, version history, diff, actions). CnDetailPage /
// CnObjectSidebar pass a `component`-type tab (and the actionsComponent) the
// shared object context `{ objectId, register, schema }` — sometimes also a
// full `object`. This mixin resolves a usable Application record from whatever
// it gets (fetching from OR's REST objects API when only the uuid is known),
// derives the caller's role, and exposes a thin patch helper. Per ADR-022 it
// reads/writes Application objects via OR's REST API directly — no app-local
// CRUD wrapper.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useRole, getCurrentUserGroups } from '../composables/useRole.js'

const OR_OBJECTS = '/apps/openregister/api/objects/openbuilt/application'

export default {
	props: {
		// CnObjectSidebar's sharedTabProps / CnDetailPage's actionsComponent props.
		objectId: { type: [String, Number], default: '' },
		objectUuid: { type: [String, Number], default: '' },
		object: { type: Object, default: null },
		register: { type: [String, Number], default: '' },
		schema: { type: [String, Number], default: '' },
	},
	data() {
		return {
			obApp: null,
			obAppError: '',
			obAppLoading: false,
		}
	},
	computed: {
		obAppUuid() {
			if (this.obApp) {
				const self = this.obApp['@self'] || {}
				return self.id || self.uuid || this.obApp.uuid || this.obApp.id || ''
			}
			return String(this.objectId || this.objectUuid
				|| (this.object && ((this.object['@self'] || {}).id || this.object.uuid || this.object.id)) || '')
		},
		obAppRole() {
			return useRole(this.obApp, getCurrentUserGroups())
		},
	},
	created() {
		this.obLoadApp()
	},
	methods: {
		async obLoadApp() {
			if (this.object && (this.object.manifest !== undefined || this.object.slug !== undefined)) {
				this.obApp = this.object
				return
			}
			const uuid = this.obAppUuid
			if (!uuid) {
				this.obAppError = t('openbuilt', 'No application selected.')
				return
			}
			this.obAppLoading = true
			try {
				const { data } = await axios.get(generateUrl(`${OR_OBJECTS}/${uuid}`))
				this.obApp = (data && data.results) ? data.results : (data && data['@self'] ? data : data)
			} catch (e) {
				this.obAppError = `${t('openbuilt', 'Failed to load application')}: ${e.message || e}`
			} finally {
				this.obAppLoading = false
			}
		},
		/**
		 * PUT a shallow-merged patch onto the Application via OR's REST API and
		 * refresh `obApp` from the response.
		 *
		 * @param {object} patch Fields to merge onto the current Application body.
		 * @return {Promise<void>}
		 */
		async obPatchApp(patch) {
			const uuid = this.obAppUuid
			if (!uuid || !this.obApp) {
				return
			}
			const { data } = await axios.put(generateUrl(`${OR_OBJECTS}/${uuid}`), { ...this.obApp, ...patch })
			this.obApp = (data && data.results) ? data.results : (data && data['@self'] ? data : { ...this.obApp, ...patch })
		},
	},
}
