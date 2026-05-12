/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `@nextcloud/auth`.
 *
 * The real implementation reads `<head>` <meta requesttoken="..."> and a
 * `oc_session_passphrase` cookie; jsdom doesn't have either. The stub
 * returns a deterministic token so tests can assert request headers.
 */

export const getRequestToken = () => 'test-request-token'
export const getCurrentUser = () => ({ uid: 'test-admin', displayName: 'Test Admin', isAdmin: true })
