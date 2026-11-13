<?php

/**
 * Contract of AppHealthService
 *
 * The public surface of AppHealthService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of AppHealthService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AppHealthServiceInterface {

	/**
	 * The register the samples live in.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * The sample schema.
	 */
	public const SCHEMA_SLUG = 'app-health-sample';

	/**
	 * Window lengths in days.
	 */
	public const WINDOW_DAYS = ['7d' => 7, '30d' => 30, '90d' => 90];

	/**
	 * The health of an app version over a window. The caller has already been authorised.
	 *
	 * @param string $applicationUuid The application.
	 * @param string $versionUuid The version.
	 * @param string $window 7d, 30d or 90d.
	 * @param DateTimeImmutable|null $now The moment; now when null.
	 *
	 * @return array<string, mixed>|null The aggregate, null for an unknown window.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 */
	public function computeHealth(string $applicationUuid, string $versionUuid, string $window, ?DateTimeImmutable $now = null): ?array;

	/**
	 * An object as its data array.
	 *
	 * @param mixed $object An entity or array.
	 *
	 * @return array<string, mixed> The data.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-app-detail-page-shows-the-app-s-health-req-bqdm-006
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function normalise(mixed $object): array;

	/**
	 * Store one sample per posted page for an app version the caller may open.
	 *
	 * @param string $applicationUuid The application, derived by the server.
	 * @param string $versionUuid The version, derived by the server.
	 * @param mixed $pages The posted pages.
	 *
	 * @return int How many samples were stored.
	 *
	 * @spec openspec/changes/operate-debug-log-and-monitoring/specs/built-app-health-monitoring/spec.md#requirement-the-server-scopes-and-bounds-samples-req-bqdm-005
	 */
	public function record(string $applicationUuid, string $versionUuid, mixed $pages): int;
}//end interface
