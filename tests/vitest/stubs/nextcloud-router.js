/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@nextcloud/router`.
 *
 * `generateUrl(path)` consults `OC.config.basename` and `OC.appswebroots`
 * at runtime; neither exists under jsdom. The stub returns a stable
 * `/index.php/...` prefix so tests can assert against the resulting URL
 * without depending on global setup.
 */

export const generateUrl = (path) => `/index.php${path.startsWith('/') ? '' : '/'}${path}`
export const generateOcsUrl = (path) => `/ocs/v2.php${path.startsWith('/') ? '' : '/'}${path}`
export const generateRemoteUrl = (path) => `/remote.php${path.startsWith('/') ? '' : '/'}${path}`
export const getRootUrl = () => ''
export const linkTo = (app, file) => `/apps/${app}/${file}`
export const imagePath = (app, file) => `/apps/${app}/img/${file}`
