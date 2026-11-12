<?php

/**
 * Buildiq WidgetSource
 *
 * A published Application whose widgets may reach the Nextcloud dashboard, with the principals its permissions name.
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

use OCA\Buildiq\Dashboard\WidgetApplication;

/**
 * The Application a promoted placement comes from, and who may see that Application.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
 */
final class WidgetSource {

	/**
	 * Constructor.
	 *
	 * @param WidgetApplication $application The Application's uuid, slug and name.
	 * @param array<int,mixed>  $principals  The Application's flattened permissions.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly WidgetApplication $application,
		public readonly array $principals,
	) {
	}//end __construct()
}//end class
