// SPDX-License-Identifier: EUPL-1.2
/**
 * No-op (Vue 3 / @nextcloud/vue v9, ADR-066).
 *
 * v9 removed the `Tooltip` directive and the library migrated every `v-tooltip`
 * to a native `:title` attribute; openbuild uses no `v-tooltip` of its own. There
 * is therefore no global directive to install, and Vue 3 has no `Vue.directive`
 * global anyway (directives register per-app via `app.directive`). Kept as a no-op
 * so the main / builder / settings bootstraps don't need touching.
 *
 * @return {void}
 */
export function registerDirectives() {}
