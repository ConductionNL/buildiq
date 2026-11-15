<?php

/**
 * Buildiq ApplicationDeletionRun
 *
 * One teardown of an Application: whether its data goes too, what could not be removed, and which schemas its deleted registers owned.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * The state of one ApplicationDeletionService::deleteApplication() call.
 * Never leaves ApplicationDeletionService.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */
final class ApplicationDeletionRun {

	/**
	 * The resources this teardown tried to remove and could not.
	 *
	 * @var array<int,string>
	 */
	public array $orphaned = [];

	/**
	 * Schema ids/slugs the deleted registers owned, cleaned up once every
	 * register is gone.
	 *
	 * @var array<int,mixed>
	 */
	public array $ownedSchemaIds = [];

	/**
	 * Constructor.
	 *
	 * @param string $appSlug    The Application slug (for log context).
	 * @param bool   $deleteData Whether the per-version registers, their objects and schemas go too.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $appSlug,
		public readonly bool $deleteData,
	) {
	}//end __construct()
}//end class
