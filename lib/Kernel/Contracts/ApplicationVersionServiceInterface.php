<?php

/**
 * Contract of ApplicationVersionService
 *
 * The public surface of ApplicationVersionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use OCA\Buildiq\Support\ReleaseOverride;
use RuntimeException;

/**
 * Contract of ApplicationVersionService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationVersionServiceInterface {

	/**
	 * Shared register that hosts both Application and ApplicationVersion.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Prefix of the PER-VERSION register the creation wizard provisions.
	 *
	 * SEPARATE FROM REGISTER_SLUG, AND DELIBERATELY STILL `openbuild-`.
	 *
	 * Until the app-id rename these were the same string, and several callers
	 * built a per-version name as `REGISTER_SLUG . '-' . $slug`. Renaming
	 * REGISTER_SLUG to `buildiq` silently changed what those callers emit,
	 * because one constant was doing two jobs. The applicationVersion schema
	 * pins this one with `"pattern": "^openbuild-[a-z0-9][a-z0-9-]*[a-z0-9]$"`,
	 * so the renamed value is not merely inconsistent — it is REJECTED, and the
	 * failure surfaces as "Property 'register' should match pattern … but
	 * 'buildiq-…' does not" at the moment an ApplicationVersion is written.
	 *
	 * Naming the two apart is what stops the next rename from doing it again.
	 * Moving the per-version registers as well is a separate decision: existing
	 * ones are real, populated registers, and renaming them needs a migration
	 * and a change to that schema pattern, neither of which belongs in an
	 * app-slug rename.
	 *
	 * @var string
	 */
	public const VERSION_REGISTER_PREFIX = 'openbuild-';

	/**
	 * Schema slug of the parent Application object.
	 */
	public const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Schema slug of the versioned-model ApplicationVersion object.
	 */
	public const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Delete an ApplicationVersion using the named strategy (spec REQ-OBV-108).
	 *
	 * Branching effect chain:
	 *
	 *   - `delete-now`: drop the per-version register (and every row inside
	 *     it) via {@see RegisterService::delete()}, then delete the
	 *     ApplicationVersion row.
	 *   - `orphan-grace`: mark the per-version register orphaned by writing
	 *     a timestamped flag into its `configuration` array, then delete the
	 *     ApplicationVersion row. A background job (out of scope here)
	 *     prunes registers orphaned for more than 30 days.
	 *   - `keep-register`: leave the register untouched; delete only the
	 *     ApplicationVersion row.
	 *
	 * Rejects deletion of an ApplicationVersion currently pointed at by its
	 * parent Application's `productionVersion`.
	 *
	 * @param string $versionUuid UUID of the ApplicationVersion to delete
	 * @param string $strategy One of the STRATEGY_* constants
	 *
	 * @return void
	 *
	 * @throws RuntimeException On unknown strategy, missing version, or
	 *                          production-version refusal
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-25
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function deleteVersion(string $versionUuid, string $strategy): void;

	/**
	 * Reject a `promotesTo` assignment that would form a cycle (spec REQ-OBV-104).
	 *
	 * Walks `promotesTo` forward from the proposed target up to
	 * {@see self::CYCLE_GUARD_HOPS} hops. Throws when the current row's
	 * UUID is encountered along the walk (cycle), when the proposed
	 * target itself equals the current row's UUID (self-loop), or when
	 * the hop cap is exceeded (chain corruption).
	 *
	 * @param string $currentUuid UUID of the row being saved
	 * @param string|null $proposedTargetUuid Proposed `promotesTo` value
	 *
	 * @return void
	 *
	 * @throws RuntimeException When a cycle is detected or the cap is exceeded
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-23
	 */
	public function guardNoCycle(string $currentUuid, ?string $proposedTargetUuid): void;

	/**
	 * Verify that `Application.productionVersion`'s back-reference is sound (REQ-OBV-105).
	 *
	 * Reads the proposed ApplicationVersion and asserts that its
	 * `application` relation points back at the parent Application's
	 * UUID. Throws otherwise — the caller surfaces a 422 to the client.
	 *
	 * @param string $applicationUuid UUID of the parent Application being saved
	 * @param string $proposedVersionUuid UUID proposed as `productionVersion`
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the back-reference does not point at the parent
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-31
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function guardProductionVersionOwnership(string $applicationUuid, string $proposedVersionUuid): void;

	/**
	 * Apply the semver auto-bump rule (spec REQ-OBV-103) to a pending save.
	 *
	 * Given the previously-persisted state (`$current`, may be null on
	 * create) and the candidate state (`$next` — mutated in place when a
	 * bump is required), this method:
	 *
	 *   - Computes the manifest hash of `$next`.
	 *   - Compares with `$current`'s stored `manifestHash` (mapper-internal
	 *     bookkeeping; not part of the public schema). When different,
	 *     `$next.semver` is patch-bumped and `$next.manifestHash` is set
	 *     to the new hash.
	 *   - On a brand-new row (no `$current`), `$next.semver` defaults to
	 *     `0.1.0` when absent and `$next.manifestHash` is initialised.
	 *
	 * @param array<string,mixed>|null $current The persisted state, or null on create
	 * @param array<string,mixed> $next The candidate next state (mutated in place)
	 *
	 * @return array<string,mixed> The mutated `$next` array
	 *
	 * @throws \JsonException When the manifest cannot be canonicalised
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-22
	 */
	public function onSave(?array $current, array $next): array;

	/**
	 * Release a version: publish it, point production at it, archive the previous
	 * production (spec REQ-OBV-110 / design Decision 3).
	 *
	 * Cross-row imperative glue (ADR-031 §Exceptions(1)): one version's lifecycle
	 * transition + the Application's single-valued pointer + a second version's
	 * demotion, atomic in intent. Ordered pointer-move before demotion so a
	 * mid-failure leaves at most a published-but-not-production version, which a
	 * re-run converges. Never drops or mints a register.
	 *
	 * Steps:
	 *   1. guardProductionVersionOwnership — back-reference must match (else 422).
	 *   2. transition chosen version `draft → published` (saving `status` drives the
	 *      x-openregister-lifecycle transition + BuiltAppRoute upsert, REQ-OBV-106).
	 *   3. set `Application.productionVersion` = chosen version uuid.
	 *   4. archive the previous production version (if any and different) so exactly
	 *      one production version remains (single-production invariant).
	 *
	 * Before step 2 the app's test suite runs (lifecycle-release-test-gate D2):
	 * a failing suite throws {@see \OCA\Buildiq\Exception\ReleaseTestsFailedException}
	 * with the stored run and nothing is written, unless `$override` is an
	 * owner's "release anyway" with a reason.
	 *
	 * @param string $applicationUuid Parent Application UUID
	 * @param string $versionUuid ApplicationVersion UUID to release
	 * @param ReleaseOverride|null $override An owner's "release anyway", when asked for
	 *
	 * @return array{productionVersion: string, published: string, archived: string|null, testRun?: array<string, mixed>}
	 *
	 * @throws RuntimeException On back-reference mismatch or a missing object
	 * @throws \OCA\Buildiq\Exception\ReleaseTestsFailedException When the suite fails and no override was given
	 * @throws \OCA\Buildiq\Exception\ReleaseOverrideRefusedException When the override is not an owner's or has no reason
	 *
	 * @spec openspec/specs/application-versions/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function releaseVersion(string $applicationUuid, string $versionUuid, ?ReleaseOverride $override = null): array;
}//end interface
