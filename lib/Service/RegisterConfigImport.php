<?php

/**
 * Buildiq RegisterConfigImport
 *
 * The register configuration as it is handed to OpenRegister's importFromApp:
 * the base openbuild_register.json merged with every register.d fragment,
 * and the version signed with those fragments.
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
 * Assembled register configuration plus its import version.
 *
 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
 */
final class RegisterConfigImport {

	/**
	 * Build the import.
	 *
	 * @param array<string,mixed> $data    The merged register configuration.
	 * @param string              $version The fragment-signed version.
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $version,
	) {

	}//end __construct()
}//end class
