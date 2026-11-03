<?php

/**
 * Buildiq App Repo Codec
 *
 * The app repository serializer and parser. GitHubAppSyncService turns an application into repository files and back with this serializer and parser pair.
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
 * The app repository serializer and parser.
 */
final class AppRepoCodec {
	/**
	 * Constructor.
	 *
	 * @param AppRepoSerializer $serializer Writes an application as repository files.
	 * @param AppRepoParser $parser Reads repository files back into an application.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly AppRepoSerializer $serializer,
		public readonly AppRepoParser $parser,
	) {
	}//end __construct()
}//end class
