<?php

/**
 * OpenBuilt VersionLockedException
 *
 * Thrown when OR's object-lock acquisition on the target ApplicationVersion
 * row fails because another caller already holds the lock (spec
 * REQ-OBVP-006 — 409 / `code: "version_locked"` with `lockedBy` and
 * `expiresAt`). The carried metadata is forwarded straight to the client
 * so the UI can communicate the contention.
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
 * 409-mapped — target version row is locked by another caller.
 *
 * Carries `lockedBy` (UID) and `expiresAt` (ISO-8601) from OR's lock metadata.
 */
final class VersionLockedException extends VersionPromotionException
{

    /**
     * UID of the lock holder, or null when OR did not surface the field.
     *
     * @var string|null
     */
    private ?string $lockedBy;

    /**
     * ISO-8601 expiry timestamp, or null when OR did not surface the field.
     *
     * @var string|null
     */
    private ?string $expiresAt;

    /**
     * Constructor.
     *
     * @param string|null    $lockedBy  UID of the existing lock holder
     * @param string|null    $expiresAt ISO-8601 timestamp of lock expiry
     * @param string         $message   Diagnostic message
     * @param Throwable|null $previous  Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        ?string $lockedBy=null,
        ?string $expiresAt=null,
        string $message='Target ApplicationVersion row is locked.',
        ?Throwable $previous=null
    ) {
        parent::__construct(errorCode: 'version_locked', message: $message, previous: $previous);
        $this->lockedBy  = $lockedBy;
        $this->expiresAt = $expiresAt;
    }//end __construct()

    /**
     * Get the UID of the lock holder.
     *
     * @return string|null
     */
    public function getLockedBy(): ?string
    {
        return $this->lockedBy;
    }//end getLockedBy()

    /**
     * Get the ISO-8601 expiry timestamp.
     *
     * @return string|null
     */
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }//end getExpiresAt()
}//end class
