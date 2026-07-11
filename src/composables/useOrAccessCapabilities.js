// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// useOrAccessCapabilities — feature-detects the row-level access scope kinds
// the connected OpenRegister instance advertises, via
// `openregister.authorization.scopes` in the Nextcloud capabilities
// document (read through `@nextcloud/capabilities`).
//
// Pure + synchronous, mirroring the `useAppStatus.js` soft-check style
// (design.md "Capability precedent"): capabilities are preloaded into the
// page by Nextcloud server-side, so there is no async fetch here — just a
// defensive read with a safe baseline fallback.
//
// Baseline `['group']` — current OpenRegister enforces per-operation NC
// group ID lists on `authorization.<op>` but has no `@creator` sentinel or
// `authorization.conditions` primitive yet (see proposal.md "Upstream leaf
// requirements"). When OR starts advertising `creator` / `condition` in its
// capabilities document, this composable picks it up automatically — no
// OR version sniffing (design.md Decision 3).
//
// @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-003

import { getCapabilities } from '@nextcloud/capabilities'

/** @type {Array<string>} Baseline scope kinds offered against any connected OR. */
export const BASELINE_SCOPES = Object.freeze(['group'])

/**
 * Read the connected OpenRegister's advertised authorization scope kinds.
 *
 * Falls back to `BASELINE_SCOPES` when the capabilities key is absent,
 * malformed (non-array), or `getCapabilities()` throws — a missing/broken
 * capability must never crash the Schema Designer, it must just mean the
 * baseline scope kinds (everyone / groups) are offered.
 *
 * @return {{ scopes: string[] }} The advertised (or baseline) scope kinds.
 * @spec openspec/changes/data-scopes-authoring/specs/data-scopes-authoring/spec.md#req-obdsa-003
 */
export function useOrAccessCapabilities() {
	try {
		const capabilities = getCapabilities()
		const scopes = capabilities
			&& capabilities.openregister
			&& capabilities.openregister.authorization
			&& capabilities.openregister.authorization.scopes
		if (Array.isArray(scopes) && scopes.length > 0) {
			return { scopes }
		}
	} catch (e) {
		// Defensive: never throw from a pure capability read.
	}
	return { scopes: [...BASELINE_SCOPES] }
}
