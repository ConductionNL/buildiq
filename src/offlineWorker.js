// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * The offline worker a running app registers (pages-installable-offline-and-mobile
 * T05), served by `GET /run/{slug}/offline-worker.js`, which stamps the bundle
 * version first.
 *
 * It wires the library's shell-worker decisions to the worker events: the app
 * shell and the bundle are cached, an object read never is (`respondTo` uses
 * the library's `isCacheable` and its `NEVER_CACHED` list). The listeners are
 * written here because the library marks itself side-effect free, so a bare
 * import of its worker module bundles to nothing.
 *
 * @spec openspec/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
import { cacheNameFor, respondTo, staleCacheNames } from '@conduction/nextcloud-vue/src/offline/serviceWorker.js'

/* istanbul ignore next -- only reachable inside a real service worker. */
if (typeof self !== 'undefined' && typeof self.addEventListener === 'function' && typeof self.skipWaiting === 'function') {
	const version = (typeof self.CN_OFFLINE_BUNDLE_VERSION === 'string') ? self.CN_OFFLINE_BUNDLE_VERSION : '0'
	const cacheName = cacheNameFor(version)

	self.addEventListener('install', () => self.skipWaiting())

	self.addEventListener('activate', (event) => {
		event.waitUntil((async () => {
			const names = await caches.keys()
			await Promise.all(staleCacheNames(names, cacheName).map((name) => caches.delete(name)))
			await self.clients.claim()
		})())
	})

	self.addEventListener('fetch', (event) => {
		event.respondWith((async () => {
			const cache = await caches.open(cacheName)
			return await respondTo(event.request, cache, (request) => fetch(request))
		})())
	})
}
