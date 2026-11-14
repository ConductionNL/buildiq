<?php

/**
 * Buildiq ManifestResolutionException
 *
 * A delta-mode version whose merged manifest is not renderable: the manifest
 * endpoint answers it through its correlation-id error path instead of
 * serving a broken manifest.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use RuntimeException;

/**
 * Thrown when a merged manifest lacks a string `version` or a `pages` array.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-4.3
 */
class ManifestResolutionException extends RuntimeException {
}//end class
