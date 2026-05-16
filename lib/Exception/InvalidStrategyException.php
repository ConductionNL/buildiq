<?php

/**
 * OpenBuilt InvalidStrategyException
 *
 * Thrown when the promotion endpoint receives a missing or unrecognised
 * `strategy` value (spec REQ-OBVP-001 — 400 / `code: "invalid_strategy"`).
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
 * 400-mapped — strategy is missing or not in the closed enum.
 */
final class InvalidStrategyException extends VersionPromotionException
{
    /**
     * Constructor.
     *
     * @param string         $message  Diagnostic message naming the offending value
     * @param Throwable|null $previous Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        string $message='Unknown or missing promotion strategy.',
        ?Throwable $previous=null
    ) {
        parent::__construct(errorCode: 'invalid_strategy', message: $message, previous: $previous);
    }//end __construct()
}//end class
