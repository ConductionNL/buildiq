<?php

/**
 * OpenBuilt VersionPromotionException
 *
 * Base exception for the version-promotion flow (spec
 * openbuilt-version-promotion). Each subclass carries the spec-defined
 * machine-readable `code` string and any associated context (locked-by,
 * strategy, etc.) so the controller can map a single catch arm onto the
 * correct HTTP status + error envelope without reflecting on message text.
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

use RuntimeException;
use Throwable;

/**
 * Base type for promotion-flow failures.
 *
 * Subclasses carry the machine-readable error `code` and any error-specific
 * context (lockedBy/expiresAt, chosen strategy, …) so the controller can
 * map exceptions to HTTP responses without parsing message strings.
 */
abstract class VersionPromotionException extends RuntimeException
{

    /**
     * Machine-readable error code (the spec's `code` value).
     *
     * @var string
     */
    protected string $errorCode;

    /**
     * Constructor.
     *
     * @param string         $errorCode Machine-readable error code
     * @param string         $message   Human-readable diagnostic message
     * @param Throwable|null $previous  Wrapped causal exception, if any
     *
     * @return void
     */
    public function __construct(string $errorCode, string $message='', ?Throwable $previous=null)
    {
        parent::__construct(message: $message, code: 0, previous: $previous);
        $this->errorCode = $errorCode;
    }//end __construct()

    /**
     * Get the machine-readable error code.
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }//end getErrorCode()
}//end class
