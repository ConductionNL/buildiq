<?php

/**
 * Contract of VersionPromotionService
 *
 * The public surface of VersionPromotionService that other components call, so they depend
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

use OCA\Buildiq\Exception\InvalidStrategyException;
use OCA\Buildiq\Exception\NoPromoteTargetException;
use OCA\Buildiq\Exception\PromotionFailedException;
use OCA\Buildiq\Exception\VersionLockedException;

/**
 * Contract of VersionPromotionService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VersionPromotionServiceInterface {

	/**
	 * Shared register hosting Application + ApplicationVersion rows.
	 */
	public const REGISTER_SLUG = ApplicationVersionServiceInterface::REGISTER_SLUG;

	/**
	 * Schema slug of the parent Application object.
	 */
	public const APPLICATION_SCHEMA = ApplicationVersionServiceInterface::APPLICATION_SCHEMA;

	/**
	 * Schema slug of the ApplicationVersion object.
	 */
	public const APPLICATION_VERSION_SCHEMA = ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA;

	/**
	 * Orchestrate promotion from source to its `promotesTo` target (spec REQ-OBVP-001..-009).
	 *
	 * Acquires the OR object lock on the target, runs the strategy branch
	 * inside a `try { … }` block, and releases the lock in a `finally`
	 * regardless of outcome. On failure the target is archived and
	 * a {@see PromotionFailedException} is re-thrown so the controller can
	 * map it to a 500 response.
	 *
	 * @param array<string,mixed> $source Source ApplicationVersion object data
	 * @param string $strategy One of {@see self::VALID_STRATEGIES}
	 *
	 * @return array<string,mixed> The updated target ApplicationVersion data
	 *
	 * @throws NoPromoteTargetException When the source has no `promotesTo`
	 * @throws InvalidStrategyException When the strategy is missing or unknown
	 * @throws VersionLockedException When OR's lock is held by another caller
	 * @throws PromotionFailedException When the strategy branch fails midway
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-59
	 */
	public function promote(array $source, string $strategy): array;
}//end interface
