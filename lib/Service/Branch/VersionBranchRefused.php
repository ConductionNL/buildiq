<?php

/**
 * Buildiq VersionBranchRefused
 *
 * A branch or merge the server refuses, with the error code and HTTP status
 * the controller answers with (lifecycle-git-branches). A refused branch or
 * merge has written nothing.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Branch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-git-branches/tasks.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Branch;

use RuntimeException;

/**
 * A refused branch or merge.
 *
 * @spec openspec/changes/lifecycle-git-branches/specs/app-version-branches/spec.md#requirement-a-merge-only-writes-a-draft-that-has-not-moved-req-bqgb-004
 */
class VersionBranchRefused extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $errorCode The error code of the response.
	 * @param string $detail    What the maker reads.
	 * @param int    $status    The HTTP status.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $errorCode,
		string $detail,
		public readonly int $status=409,
	) {
		parent::__construct(message: $detail);
	}//end __construct()
}//end class
