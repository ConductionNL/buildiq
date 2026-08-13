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
import { fetchApplicationRecord } from '../composables/useApplicationRecord.js'

const OR_OBJECTS = '/apps/openregister/api/objects/openbuild/application'

export default {
	props: {
		// CnObjectSidebar's sharedTabProps / CnDetailPage's actionsComponent props.
		objectId: { type: [String, Number], default: '' },
		objectUuid: { type: [String, Number], default: '' },
		object: { type: Object, default: null },
		register: { type: [String, Number, Object], default: '' },
		// CnDetailPage binds :schema="currentSchema" — the RESOLVED schema object
		// (its documented @binding is `{object} schema`), not a slug string. Accept
		// String/Number too for CnObjectSidebar's sharedTabProps, which pass the
		// slug. This mixin never reads schema/register (it works off objectId /
		// object), so the wider type is purely to match what callers pass and avoid
		// the "Invalid prop: type check failed for prop schema" warning.
		schema: { type: [String, Number, Object], default: '' },
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
			return String(
				this.objectId
					|| this.objectUuid
					|| (this.object
						&& ((this.object['@self'] || {}).id
							|| this.object.uuid
							|| this.object.id))
					|| '',
			)
		},
		obAppRole() {
			return useRole(this.obApp, getCurrentUserGroups())
		},
	},
	created() {
		this.obLoadApp()
	},
	methods: {
		/**
		 * Resolve the Application record. By default the full `object` prop (when
		 * CnDetailPage passed one) is used as-is; pass `force` to always refetch
		 * from OR — the prop is a render-time snapshot and goes stale after
		 * server-side mutations like publish/unpublish.
		 *
		 * @param {boolean} force Skip the object-prop shortcut and refetch by uuid.
		 * @return {Promise<void>}
		 */
		async obLoadApp(force = false) {
			if (
				!force
				&& this.object
				&& (this.object.manifest !== undefined
					|| this.object.slug !== undefined)
			) {
				this.obApp = this.object
				return
			}
			const uuid = this.obAppUuid
			if (!uuid) {
				this.obAppError = t('openbuild', 'No application selected.')
				return
			}
			this.obAppLoading = true
			try {
				// Shared in-flight fetch (#49). SIX components mix this in — the
				// detail-page actions component and five sidebar tabs (Diff,
				// Manifest, Export jobs, Icon, Versions) — and they all mount at
				// once, each previously issuing its own GET for the same record.
				// Together with the header and dashboard that produced ~10
				// identical requests per page load, all within ~2ms of each
				// other. Routing every consumer through one coalescing helper
				// collapses the burst to a single round-trip.
				const data = await fetchApplicationRecord(uuid)
				this.obApp =
					data && data.results
						? data.results
						: data && data['@self']
							? data
							: data
			} catch (e) {
				this.obAppError = `${t('openbuild', 'Failed to load application')}: ${e.message || e}`
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
			const { data } = await axios.put(generateUrl(`${OR_OBJECTS}/${uuid}`), {
				...this.obApp,
				...patch,
			})
			this.obApp =
				data && data.results
					? data.results
					: data && data['@self']
						? data
						: { ...this.obApp, ...patch }
		},
	},
}
