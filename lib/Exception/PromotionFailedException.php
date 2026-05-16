<?php

/**
 * OpenBuilt PromotionFailedException
 *
 * Thrown after the on-failure flow has flipped the target to `archived` and
 * stamped `_self.promotionFailedAt` (spec REQ-OBVP-009 — 500 / `code:
 * "promotion_failed"`). Carries the chosen strategy and the captured
 * underlying message so the client sees both.
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
 * 500-mapped — promotion partially or wholly failed; target is archived.
 */
final class PromotionFailedException extends VersionPromotionException
{

    /**
     * The strategy the admin chose when the promotion failed.
     *
     * @var string
     */
    private string $strategy;

    /**
     * Constructor.
     *
     * @param string         $strategy The strategy value that was being run
     * @param string         $message  Captured OR/PHP error message
     * @param Throwable|null $previous Wrapped causal exception
     *
     * @return void
     */
    public function __construct(
        string $strategy,
        string $message='Promotion failed; target was archived.',
        ?Throwable $previous=null
    ) {
        parent::__construct(errorCode: 'promotion_failed', message: $message, previous: $previous);
        $this->strategy = $strategy;
    }//end __construct()

    /**
     * Get the strategy that was being run at failure time.
     *
     * @return string
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }//end getStrategy()
}//end class
