<?php

/**
 * Buildiq WidgetHostPage
 *
 * The manifest page a promoted placement sits on.
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
 * The page a placement sits on: its Application and the route it opens.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-manifest-declares-which-widgets-reach-the-nextcloud-dashboard
 */
final class WidgetHostPage {

	/**
	 * Constructor.
	 *
	 * @param WidgetSource $source The Application the page belongs to.
	 * @param string       $route  The page route, `/` when the page declares none.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly WidgetSource $source,
		public readonly string $route,
	) {
	}//end __construct()
}//end class
