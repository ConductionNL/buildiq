// SPDX-License-Identifier: EUPL-1.2
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { listResults } from '../utils/listResults.js'

/**
 * The application picker of the authoring pages (agents, automations): the
 * caller's Applications and their loading flag. A failed read sets the
 * page's own `errorMessage`.
 */
export const applicationPickerMixin = {
	data() {
		return {
			loadingApplications: false,
			applications: [],
		}
	},

	methods: {
		/**
		 * Load the caller's Applications for the picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-001
		 */
		async fetchApplications() {
			this.loadingApplications = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/buildiq/api/applications')
				const { data } = await axios.get(url)
				this.applications = listResults(data)
			} catch {
				this.errorMessage = t('buildiq', 'Could not load applications.')
			} finally {
				this.loadingApplications = false
			}
		},
	},
}
