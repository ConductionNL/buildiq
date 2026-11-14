<?php

/**
 * Contract of ApplicationInsightsService
 *
 * The public surface of ApplicationInsightsService that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of ApplicationInsightsService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationInsightsServiceInterface {

	/**
	 * Allowed `window` query parameter values (REQ-OBAI-001).
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_WINDOWS = ['7d', '30d', '90d'];

	/**
	 * Compute the insights payload for a given Application + Version + window.
	 *
	 * Returns `null` for any failure mode the caller maps to 404 (IDOR-safe
	 * — no existence leak):
	 *   - Unknown `appUuid`
	 *   - Unknown `versionUuid`
	 *   - Version whose `application` relation does not point at `appUuid`
	 *   - RBAC failure (viewer-on-non-production, no role at all on production)
	 *
	 * Returns `null` for an invalid `window` value (caller is expected to
	 * pre-validate at the controller layer; the defensive check here keeps
	 * the service safe in isolation).
	 *
	 * The per-version register fallback takes its prefix from
	 * {@see ApplicationVersionService::VERSION_REGISTER_PREFIX}. This method
	 * used to type it as `sprintf('buildiq-%s-%s', ...)`, and that named a
	 * register no instance can hold: every writer of a per-version register
	 * uses the constant, which is `openbuild-` and is frozen there on purpose,
	 * and the applicationVersion schema pins `"pattern": "^openbuild-..."` so a
	 * `buildiq-` register is rejected at write time. The read then matched
	 * nothing and the panel showed zero objects, zero files and zero audit
	 * events, which is byte for byte what a real but empty version looks like.
	 * {@see \OCA\Buildiq\Tests\Unit\Support\RegisterSlugPinTest} guards it now.
	 *
	 * @param string $appUuid Application UUID (path parameter).
	 * @param string $versionUuid ApplicationVersion UUID (path parameter).
	 * @param string $window Window string — one of `7d`, `30d`, `90d`.
	 * @param IUser|null $caller The authenticated user, or null for unauthenticated.
	 *
	 * @return array<string, mixed>|null Insights payload `{kpis, activity}` or null on 404.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-16
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-19
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-20
	 */
	public function computeInsights(
		string $appUuid,
		string $versionUuid,
		string $window,
		?IUser $caller,
	): ?array;

	/**
	 * Resolve + authorise the (Application, Version, caller) tuple.
	 *
	 * Returns `[$application, $version]` on success, or `null` on any 404
	 * mode (unknown app, unknown version, IDOR mismatch, RBAC denial).
	 *
	 * Public so the controller can call it explicitly as a guard step —
	 * hydra gate-7 (no-admin-idor) expects a `require*` / `authorize*` /
	 * `ensure*` / `check*` call in the controller method body alongside
	 * the `#[NoAdminRequired]` annotation, even when the same logic also
	 * lives inside the service layer.
	 *
	 * @param string $appUuid Application UUID.
	 * @param string $versionUuid ApplicationVersion UUID.
	 * @param IUser|null $caller The authenticated user, or null for unauthenticated.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-17
	 */
	public function requireAuthorisedCaller(
		string $appUuid,
		string $versionUuid,
		?IUser $caller,
	): ?array;
}//end interface
