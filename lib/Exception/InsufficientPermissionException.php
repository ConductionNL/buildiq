<?php

/**
 * OpenBuilt InsufficientPermissionException
 *
 * Thrown when the caller lacks owner-or-editor role on the parent
 * Application (spec REQ-OBVP-007 — 403 / `code:
 * "insufficient_permission"`). Nextcloud admins are NOT auto-granted; an
 * admin who is not listed in `permissions.owners` or `permissions.editors`
 * receives this exception too — by design.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Exception
 * @package  OCA\OpenBuilt\Exception
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

namespace OCA\OpenBuilt\Exception;

use Throwable;

/**
 * 403-mapped — caller is not an owner or editor on the parent Application.
 */
final class InsufficientPermissionException extends VersionPromotionException
{
    /**
     * Constructor.
     *
     * @param string         $message  Diagnostic message
     * @param Throwable|null $previous Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        string $message='Caller is not an owner or editor on the parent Application.',
        ?Throwable $previous=null
    ) {
        parent::__construct(errorCode: 'insufficient_permission', message: $message, previous: $previous);
    }//end __construct()
}//end class
