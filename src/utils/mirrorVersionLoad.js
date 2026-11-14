/**
 * Mirror a `useApplicationVersion()` result into a builder view's own
 * `applicationVersion`, `versionLoading` and `versionError` while it loads.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Follow the resolved version until loading ends; then copy the loading
 * flag and the error once and stop both watchers, so a finished fetch
 * leaves no watcher behind.
 *
 * @param {object} vm - the component instance (`$watch` and the three fields).
 * @param {{applicationVersion: import('vue').Ref, loading: import('vue').Ref, error: import('vue').Ref}} version - the composable's refs.
 * @return {void}
 */
export function mirrorVersionLoad(vm, version) {
	const { applicationVersion, loading, error } = version
	const unwatch = vm.$watch(
		() => applicationVersion.value,
		(v) => {
			vm.applicationVersion = v
		},
	)
	const unwatchLoading = vm.$watch(
		() => loading.value,
		(v) => {
			vm.versionLoading = v
			if (!v) {
				unwatch()
				unwatchLoading()
				vm.versionError = error.value
			}
		},
	)
}
