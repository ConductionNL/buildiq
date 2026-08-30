## Tasks

### 1. Fix the resolution

- [x] Re-verify the current hardcoded values in `FlowAndAgentExportBundler` by reading the file (do not trust any previously-reported number)
- [x] Change `OPENBUILD_REGISTER`/`AGENT_SCHEMA` from numeric class constants to slug strings (`'openbuild'`/`'agent'`), matching the pattern already used by `AgentsController`, `ObjectSchemaSlugResolver`, and `AppChannelApplier::credentialExists()`
- [x] Update the two constants' docblocks (`@var int` → `@var string`)

Acceptance criteria
- No other line in `bundleAgents()` changes — same `ObjectService::findAll()` call, same filter shape
- `bundleFlows()` is untouched

### 2. Tests

- [x] Update/extend `FlowAndAgentExportBundlerTest` to assert the `findAll()` filter carries the SLUGS `openbuild`/`agent`, not the old numeric ids — this is the regression guard for the exact bug being fixed
- [x] Keep all existing assertions (applicationSlug filter, `@self` stripped, file written) green

Acceptance criteria
- The new assertion FAILS against the pre-fix code (hardcoded numeric ids) and PASSES against the fix — proving it actually guards the defect

### 3. Quality gates

- [x] `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) clean on the changed files — ran the constituent commands directly (`vendor/bin/phpcs`, `vendor/bin/phpmd`, `vendor/bin/psalm`, `vendor/bin/phpstan`) against `lib/Service/FlowAndAgentExportBundler.php`, all clean; the file's only pre-existing PHPCS finding (missing class-level `@spec` tag) is unchanged before/after this diff, confirmed by comparing against `git stash`
- [x] `composer phpunit` green — `865` tests, `2643` assertions, `8` pre-existing errors (`Class "ZipArchive" not found` — host PHP has no zip extension, unrelated to this class, reproduced identically on the unmodified `apps-extra/openbuild` checkout); `FlowAndAgentExportBundlerTest` itself: `6/6` green

### 4. Live verification

- [x] Spin up an isolated docker-compose instance (own project name `g19bai`, containers `g19bai-db`/`g19bai-nc`, port 18280) with openregister + openconnector + openbuild + hermiq, running THIS worktree's openbuild code (bind-mounted)
- [x] Create a test Application (`verify-app`) and an Agent (`Verification Agent`) tagged with `applicationSlug: verify-app`
- [x] Trigger `ExportService`'s standalone-app export (`POST /api/applications/verify-app/exports`, executed via `occ background-job:execute`) and confirm the agent is bundled
- [x] Tear the instance down; confirmed via `docker ps`/`docker volume ls` no other session's containers or volumes were touched (identical container list before/after, no `g19bai-*` residue)

Acceptance criteria
- The exported ZIP/tree contains the agent's JSON under `lib/Settings/agents/` — MET

**Full round trip, including a negative control (fix reverted, re-verified, restored):**

| step | register/schema ids on this fresh instance | agent bundled? |
| --- | --- | --- |
| 1. Fixed code | `openbuild`=16, `agent`=115 (register-scoped; a second unrelated `agent` schema id=87 also exists — confirms schema slugs are NOT globally unique, exactly as `ObjectSchemaSlugResolver`'s docblock records) | **YES** — `lib/Settings/agents/b791bfc7-….json` present, correct content, no `@self` envelope |
| 2. Reverted to old hardcoded `206`/`5060` (neither exists on this instance at all) | n/a | **NO** — export still reports `status: succeeded`, ZIP contains zero agent files — the exact silent-failure mode described in the bug report, reproduced live |
| 3. Fix restored | same as step 1 | **YES** again — agent bundled |

This is the proof this bug needed — the original defect was only caught by live testing, not unit tests, and the negative control proves the unit-test regression guard alone would not have been sufficient evidence without also seeing the bug reproduce and clear on the same live instance.
