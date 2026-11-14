<?php

/**
 * Buildiq PromotedPlacement
 *
 * A widget placement that is promoted to the Nextcloud dashboard and carries a stable id and well-formed roles.
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
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * A placement accepted for the Nextcloud dashboard.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
 */
final class PromotedPlacement {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $entry   The `widgets[]` entry.
	 * @param string              $entryId The placement's stable id.
	 * @param array<int,string>   $roles   The placement's own roles.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $entry,
		public readonly string $entryId,
		public readonly array $roles,
	) {
	}//end __construct()
}//end class
