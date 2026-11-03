<?php

/**
 * Buildiq Setup Services
 *
 * Demo data, settings and template seeding. SetupController runs demo data, settings and template seeding through these three services; they come as one object.
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
 * Demo data, settings and template seeding.
 */
final class SetupServices {
	/**
	 * Constructor.
	 *
	 * @param DemoDataService $demoDataService Loads demo data.
	 * @param SettingsService $settings App settings.
	 * @param TemplateSeedService $seedService Seeds templates.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly DemoDataService $demoDataService,
		public readonly SettingsService $settings,
		public readonly TemplateSeedService $seedService,
	) {
	}//end __construct()
}//end class
