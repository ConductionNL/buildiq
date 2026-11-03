<?php

/**
 * Buildiq Published Apps
 *
 * The shared read of published applications and the per-user visibility check order.
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
 * The shared published-application read and visibility check order.
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-4
 */
final class PublishedApps {
	/**
	 * Constructor.
	 *
	 * @param PublishedApplicationProvider|null $applicationProvider Shared published-app read; null where the object service fallback applies.
	 * @param AppVisibilityResolver|null $visibilityResolver Shared visibility check order; null takes the default.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?PublishedApplicationProvider $applicationProvider = null,
		public readonly ?AppVisibilityResolver $visibilityResolver = null,
	) {
	}//end __construct()
}//end class
