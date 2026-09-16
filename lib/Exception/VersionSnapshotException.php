<?php

/**
 * Buildiq VersionSnapshotException
 *
 * Thrown by VersionSnapshotService with the HTTP status the controller answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\Buildiq\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A snapshot request that cannot be served, with its error code and status.
 *
 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
 */
final class VersionSnapshotException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $errorCode Machine-readable error code
	 * @param int $status HTTP status to answer
	 * @param string $message Human-readable diagnostic
	 * @param Throwable|null $previous Wrapped causal exception
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $errorCode,
		private readonly int $status,
		string $message = '',
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, previous: $previous);
	}//end __construct()

	/**
	 * The machine-readable error code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	public function getErrorCode(): string {
		return $this->errorCode;
	}//end getErrorCode()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
