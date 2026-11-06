<?php

/**
 * Buildiq LocalApplicationRef
 *
 * The application on this instance that an applied repo's channels bind to.
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
 * @spec openspec/changes/app-repo-format-flow-agent-export/specs/app-channel-application/spec.md#requirement-published-flows-are-created-and-rebound-onto-the-local-application
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Uuid and slug of the local application the flows and agents channels bind to.
 *
 * @spec openspec/specs/app-channel-application/spec.md#requirement-published-flows-are-created-and-rebound-onto-the-local-application
 */
final class LocalApplicationRef {

	/**
	 * Constructor.
	 *
	 * @param string|null $uuid The local application's uuid, or null when there is none yet.
	 * @param string|null $slug The local application's slug, or null when there is none yet.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ?string $uuid = null,
		public readonly ?string $slug = null,
	) {
	}//end __construct()
}//end class
