<?php

/**
 * Buildiq Virtual App Widget Application
 *
 * The published Application a promoted dashboard widget belongs to: its
 * immutable uuid, its slug and its display name.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Dashboard
 * @package  OCA\Buildiq\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
 */

declare(strict_types=1);

namespace OCA\Buildiq\Dashboard;

/**
 * Uuid, slug and name of the Application a widget is promoted from.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-widget-identity-is-frozen-to-the-application-uuid
 */
final class WidgetApplication {

	/**
	 * Constructor.
	 *
	 * @param string $uuid Immutable Application UUID the widget id is keyed on.
	 * @param string $slug Application slug, for links and icons only.
	 * @param string $name Application display name.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly string $slug,
		public readonly string $name,
	) {
	}//end __construct()
}//end class
