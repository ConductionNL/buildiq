<?php

/**
 * Buildiq RegisterRowReadFailedException
 *
 * Thrown by RegisterRowReader when one schema's rows cannot be read.
 *
 * It exists so that "this register is empty" and "this register could not
 * be read" stop being the same answer. The three callers this covers act
 * on the row list by deleting it, copying it, or writing it to an export
 * fixture, and each of them read a failed search as an empty register and
 * reported the resulting no-op as a success.
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
 * @spec openspec/changes/register-row-reads-name-a-schema/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;
use Throwable;

/**
 * A register's rows could not be read for one of its schemas.
 *
 * @spec openspec/changes/register-row-reads-name-a-schema/tasks.md#task-1
 */
final class RegisterRowReadFailedException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string         $registerSlug The register whose rows were being read.
	 * @param string         $schemaId     The schema the read failed on.
	 * @param Throwable|null $previous     The underlying read failure.
	 *
	 * @return void
	 */
	public function __construct(
		string $registerSlug,
		string $schemaId,
		?Throwable $previous=null,
	) {
		parent::__construct(
			message: 'Could not read rows of register "'.$registerSlug.'" for schema '.$schemaId,
			code: 0,
			previous: $previous
		);
	}//end __construct()
}//end class
