<?php

/**
 * Buildiq RegisterConfigUnavailableException
 *
 * Thrown while assembling the register configuration when the base
 * openbuild_register.json is missing, unreadable or not valid JSON. The
 * message is the user-facing failure message of the configuration import.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;

/**
 * The base register configuration could not be loaded.
 *
 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
 */
final class RegisterConfigUnavailableException extends RuntimeException {
}//end class
