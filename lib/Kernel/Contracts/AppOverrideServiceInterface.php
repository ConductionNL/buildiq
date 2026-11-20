<?php

/**
 * Contract of AppOverrideService
 *
 * The public surface of AppOverrideService that other components call, so they depend
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

/**
 * Contract of AppOverrideService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppOverrideServiceInterface {

	/**
	 * OpenRegister register slug that hosts the Buildiq control schemas.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * OpenRegister schema slug for the logical app record.
	 */
	public const APPLICATION_SCHEMA = 'built-app';

	/**
	 * OpenRegister schema slug for the deployable version record.
	 */
	public const APPLICATION_VERSION_SCHEMA = 'applicationVersion';

	/**
	 * Remove the hybrid override for a fleet app (idempotent).
	 *
	 * Deletes the hybrid Application and its production version so the fleet app
	 * reverts to its bundled manifest on the next manifest load. A missing
	 * record is a no-op (no error).
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 */
	public function delete(string $appId): void;

	/**
	 * Delete the caller's own user-scoped delta for a fleet app (idempotent).
	 *
	 * Owner-scoped to the passed UID — a caller can only ever delete their own
	 * user delta. A missing record is a no-op.
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 * @param string $uid The calling user's UID (the owner).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/layered-app-deltas/spec.md
	 */
	public function deleteUserDelta(string $appId, string $uid): void;

	/**
	 * Find the delta record for a fleet app, if any, from the unified model.
	 *
	 * Resolves the hybrid Application for the appId, then its production
	 * version, and returns a normalised record carrying the version's
	 * `manifestDelta` under the same `manifestDelta` key the shim expects.
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 *
	 * @return array<string, mixed>|null The record with a `manifestDelta` key, or null when none exists.
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 */
	public function findByAppId(string $appId): ?array;

	/**
	 * Find the hybrid Application for a fleet appId, if any.
	 *
	 * The migration and the wizard both set a hybrid app's `slug` to the fleet
	 * appId, so the natural key lookup filters on `slug` + `appType: hybrid`.
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 *
	 * @return array<string, mixed>|null The normalised Application, or null when none exists.
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function findHybridApplication(string $appId): ?array;

	/**
	 * Read the caller's own user-scoped delta record for a fleet app, for editing.
	 *
	 * Always owner-scoped to the passed UID — a caller can only ever read their
	 * own user delta (the UID is the session caller's, not a request param).
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 * @param string $uid The calling user's UID.
	 *
	 * @return array<string, mixed> A summary: `allowed` (app permits user overrides),
	 *                              `exists` (the caller has a user delta), `manifestDelta`,
	 *                              `versionUuid` (the OR row uuid for version history / rollback).
	 *
	 * @spec openspec/specs/layered-app-deltas/spec.md
	 */
	public function getUserDelta(string $appId, string $uid): array;

	/**
	 * List ALL users' user-scoped deltas for a hybrid app (maintainer view).
	 *
	 * Unlike {@see getUserDelta()} this is NOT owner-scoped — it returns every
	 * `scope: user` ApplicationVersion for the app, so a maintainer can see who
	 * has a personal override. The caller's authorization (owner/editor of the
	 * parent Application, or an NC admin) is enforced by the controller; the
	 * service just aggregates. Each entry is a small summary, NOT the delta body
	 * (a maintainer manages presence, not other users' private customizations).
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 *
	 * @return array<int, array<string, mixed>> List of `{ owner, versionUuid, semver, status, updatedAt }`.
	 *
	 * @spec openspec/specs/application-delta-layers-ui/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function listUserDeltas(string $appId): array;

	/**
	 * Resolve the layered manifest delta for a calling user (layered-versioned-app-deltas).
	 *
	 * Returns `base ⊕ admin-delta ⊕ user-delta` collapsed into a single keyed
	 * delta the client loader applies over the fleet app's bundled base (the
	 * "pre-merged chain" — the loader contract is unchanged). The caller's own
	 * user delta is layered ONLY when the parent Application's
	 * `allowUserOverrides` is true AND a `scope: user` row owned by the caller
	 * exists; otherwise the result is exactly today's admin delta. A user can
	 * never see another user's delta — the uid is the session caller's, never a
	 * request parameter (no-admin-idor).
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 * @param string|null $uid The calling user's UID, or null for an anonymous caller (admin layer only).
	 *
	 * @return array<string, mixed>|null The record with a merged `manifestDelta` key, or null when no hybrid app exists.
	 *
	 * @spec openspec/specs/layered-app-deltas/spec.md
	 */
	public function resolveLayeredDelta(string $appId, ?string $uid): ?array;

	/**
	 * Create or update the hybrid app + delta-only version for a fleet app.
	 *
	 * Find-then-create-or-update keyed by `appId`: when a hybrid Application
	 * already exists its production version's `manifestDelta` is updated in
	 * place; otherwise a new hybrid Application + delta-only version are created
	 * and linked via `productionVersion`.
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 * @param array<string, mixed> $delta The keyed manifest delta (already shape-validated by the caller).
	 * @param string|null $baseRef Optional JSON-encoded provenance note of the diffed-against base.
	 * @param string $updatedBy The UID of the saving user (provenance).
	 * @param bool $systemContext When true, write with OR RBAC + multitenancy bypassed — used by the
	 *                            migration repair step which runs as the Anonymous system user and so
	 *                            cannot satisfy the Application schema's create:[admin] guard. The HTTP
	 *                            shim leaves this false so the session user's RBAC applies.
	 *
	 * @return array<string, mixed> A normalised summary: appId, updatedBy, updatedAt, applicationUuid.
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 */
	public function upsert(string $appId, array $delta, ?string $baseRef, string $updatedBy, bool $systemContext = false): array;

	/**
	 * Create or update the caller's own user-scoped delta for a fleet app.
	 *
	 * Gated on the parent Application's `allowUserOverrides`. Writes a
	 * `scope: user`, `owner: <uid>` ApplicationVersion whose `baseRef` points at
	 * the admin delta version (`kind: application-version`) so resolution walks
	 * user → admin → base. The per-version promotion fields (semver/status/
	 * promotesTo) are inert on a user row. The write deliberately runs in system
	 * context (`_rbac: false`): a non-admin user cannot satisfy the
	 * ApplicationVersion schema's `create: [admin]` ACL, so authorization for
	 * the user layer is enforced HERE (the flag + the session-derived owner),
	 * not by OR's admin-only schema ACL — a caller can only ever write a row
	 * they own (the uid is the session caller's, never a request param).
	 *
	 * @param string $appId The kebab-case Nextcloud app id (natural key).
	 * @param array<string, mixed> $delta The keyed manifest delta (already shape-validated by the caller).
	 * @param string $uid The calling user's UID (the owner).
	 *
	 * @return array<string, mixed> A summary: `appId`, `owner`, `updatedAt`, `versionUuid`.
	 *
	 * @throws \RuntimeException When the app does not exist or does not allow user overrides.
	 *
	 * @spec openspec/specs/layered-app-deltas/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function upsertUserDelta(string $appId, array $delta, string $uid): array;

	/**
	 * Validate that a body is a well-formed keyed manifest delta.
	 *
	 * @param array<array-key, mixed> $delta The candidate delta body (may arrive list-shaped from a bad PUT).
	 *
	 * @return array<int, string> A list of violations; empty when the delta is valid.
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 */
	public function validateDeltaShape(array $delta): array;

	/**
	 * Detect a delta that resolves (over an empty base) to no renderable
	 * pages/menu (an "app-blanking" delta).
	 *
	 * @param array<string, mixed> $delta The candidate delta body.
	 *
	 * @return bool True when the delta resolves (over an empty base) to no pages/menu.
	 *
	 * @spec openspec/specs/app-override-persistence/spec.md
	 */
	public function wouldBlankApp(array $delta): bool;
}//end interface
