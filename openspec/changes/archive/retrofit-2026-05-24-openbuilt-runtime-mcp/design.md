# Design — Retrofit openbuilt-runtime MCP surface

> Retrofit change. Tasks describe retroactive annotation, not new implementation
> work. The code already exists at HEAD.

## Context

`lib/Mcp/OpenBuiltToolProvider.php` (1225 lines) was shipped under the
AI-companion fleet rollout (see project memory note
`project_ai-companion-fleet-rollout` — 2026-05-12). It implements
`OCA\OpenRegister\Mcp\IMcpToolProvider`, the per-app extension point
defined by the orchestrator in OpenRegister #1466 and ADR-019
(Pluggable Integration Registry). The 16 methods on the provider were
swept into Bucket 2a (`openbuilt-runtime`) by the 2026-05-24 coverage
scan because the `openbuilt-runtime` spec already covers the
slug-keyed manifest endpoint + nested `CnAppRoot` mount but does not
name the MCP surface as a deliverable of the same capability.

The 16 methods cleanly cluster into four observable behaviours:

| REQ | Methods | Observable behaviour |
|---|---|---|
| REQ-OBR-MCP-001 | getAppId, getTools, invokeTool, errorResult | The static MCP contract surface |
| REQ-OBR-MCP-002 | requireAuthenticatedUser, isAdmin, validateListAppsArgs, isValidSlug | Auth-gated dispatch + arg validation |
| REQ-OBR-MCP-003 | resolveApplicationBySlug, mapApplication, sourceDescriptor, buildDeepLink, toArray, extractUuid | Application resolution + uniform mapping |
| REQ-OBR-MCP-004 | loadVersion, saveVersionManifest | Draft-version manifest mutation isolation |

Helpers like `toArray` and `extractUuid` deliberately do NOT get their
own REQs — they only exist to support REQ-OBR-MCP-003. Splitting them
out would inflate the REQ count without adding observable behaviour.

## Decisions

- **Extend not cluster.** The MCP surface is a tool-call entry point
  into the same runtime that REQ-OBR-001..013 already specify
  (manifest endpoints, version snapshots, RBAC, etc.). The methods
  read/write the same objects via OpenRegister, so they belong as
  delta REQs on `openbuilt-runtime` rather than a new capability.
- **4 REQs, not 16.** Bias toward fewer REQs per the playbook. One
  observable behaviour per REQ. Helpers fold into the REQ they
  support.
- **Bug noted, not fixed.** `isValidSlug` duplicates the
  `SlugValidator` service surface. The Notes block on
  REQ-OBR-MCP-002 records this as a TODO; this PR does not silently
  collapse them.
- **Auth posture mirrored from observed code.** Every handler in the
  provider calls `requireAuthenticatedUser` before any OpenRegister
  read/write — REQ-OBR-MCP-002 reflects that, including the
  short-circuit envelope shape.
- **Default `versionSlug` to `development`.** Observed in every
  authoring tool descriptor; REQ-OBR-MCP-004 codifies it as a safety
  invariant against accidental production mutation.

## Out of scope

- Tightening `isValidSlug` against `SlugValidator` — separate PR.
- Adding `openbuilt.deleteApp` / `openbuilt.archiveVersion` tools — not
  implemented today, not specified here.
- Per-tool turn-budget / rate-limit semantics — orchestrator concern,
  not provider concern.
