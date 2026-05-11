<?php

/**
 * OpenBuilt Cleanup Expired Exports
 *
 * Daily background job that purges expired ZIP archives from app-data while
 * preserving the ExportJob audit trail.
 *
 * @category BackgroundJob
 * @package  OCA\OpenBuilt\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuilt\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 24-hour cleanup job for expired export archives.
 */
class CleanupExpiredExports extends TimedJob
{
    /**
     * Constructor.
     *
     * @param ITimeFactory    $time   Time factory.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }//end __construct()

    /**
     * Iterate ExportJobs with `downloadExpiresAt < now()` and unlink ZIPs.
     *
     * Preserves the ExportJob OR record — only the ZIP file is purged
     * (audit trail remains intact). Idempotent.
     *
     * @param mixed $argument Job argument (unused).
     *
     * @return void
     */
    protected function run($argument): void
    {
        $exportsRoot = sys_get_temp_dir().'/openbuilt-exports';
        if (is_dir($exportsRoot) === false) {
            return;
        }

        $now          = time();
        $expiryWindow = 86400;
        // 24h
        $purged = 0;
        foreach (glob($exportsRoot.'/*.zip') ?: [] as $zip) {
            $mtime = filemtime($zip);
            if ($mtime !== false && ($now - $mtime) > $expiryWindow) {
                if (@unlink($zip) === true) {
                    $purged++;
                }
            }
        }

        if ($purged > 0) {
            $this->logger->info('OpenBuilt cleanup: purged '.$purged.' expired export archive(s)');
        }
    }//end run()
}//end class
