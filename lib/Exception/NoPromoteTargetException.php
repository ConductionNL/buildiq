<?php

/**
 * OpenBuilt NoPromoteTargetException
 *
 * Thrown when the source ApplicationVersion has no `promotesTo` neighbour
 * (spec REQ-OBVP-001 — 422 / `code: "no_promote_target"`). Terminal-chain
 * versions cannot be promoted to anywhere; callers must surface this to
 * the admin so they understand the chain has run out.
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
 * 422-mapped — source version's `promotesTo` is null.
 */
final class NoPromoteTargetException extends VersionPromotionException
{
    /**
     * Constructor.
     *
     * @param string         $message  Human-readable diagnostic
     * @param Throwable|null $previous Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        string $message='Source version has no promotesTo target.',
        ?Throwable $previous=null
    ) {
        parent::__construct(errorCode: 'no_promote_target', message: $message, previous: $previous);
    }//end __construct()
}//end class
