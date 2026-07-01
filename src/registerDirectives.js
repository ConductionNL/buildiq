// SPDX-License-Identifier: EUPL-1.2
import Vue from 'vue'
import { Tooltip } from '@nextcloud/vue'

/**
 * Register @nextcloud/vue's global directives once per entry bootstrap.
 *
 * Library components (CnCard, CnSchemaFormDialog, CnTabbedFormDialog, …) use
 * `v-tooltip` without registering the directive locally, so the host app must
 * install it globally. Each webpack entry bundles its own Vue constructor, so
 * this must run in every bootstrap (main / builder / settings), not just once.
 *
 * @return {void}
 */
export function registerDirectives() {
	Vue.directive('tooltip', Tooltip)
}
