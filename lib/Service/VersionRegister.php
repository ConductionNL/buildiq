<?php

/**
 * Buildiq VersionRegister
 *
 * The per-version register a teardown deletes, with the version schemas the register no longer lists.
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
 * One version's register and its detached schemas.
 *
 * @spec openspec/specs/application-detail-ui/spec.md
 */
final class VersionRegister {

	/**
	 * Constructor.
	 *
	 * @param string           $slug           The register slug.
	 * @param array<int,mixed> $extraSchemaIds Schemas of this version that the register no longer lists
	 *                                         (see {@see VersionSchemaLocator::findDetachedSchemaIds()}).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $extraSchemaIds = [],
	) {
	}//end __construct()
}//end class
