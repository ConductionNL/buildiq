<?php

/**
 * Buildiq InvalidServedManifestException
 *
 * Thrown when the manifest a delta-mode version would serve (its delta merged
 * over its base) lacks a part the renderer needs. The manifest endpoint turns
 * it into its correlation-id error envelope instead of handing the renderer a
 * manifest it cannot draw.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Exception;

use RuntimeException;

/**
 * A merged manifest that is not fit to serve.
 */
final class InvalidServedManifestException extends RuntimeException {
}//end class
