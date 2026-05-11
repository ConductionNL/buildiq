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
        parent::__construct(time: $time);
        $this->setInterval(seconds: 86400);
    }//end __construct()

    /**
     * Iterate ExportJobs with `downloadExpiresAt < now()` and unlink ZIPs.
     *
     * Preserves the ExportJob OR record — only the ZIP file is purged
     * (audit trail remains intact). Idempotent.
     *
     * @param mixed $argument Job argument injected by Nextcloud. Unused —
     *                        we always scan the same fixed location.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function run($argument): void
    {
        unset($argument);

        $exportsRoot = sys_get_temp_dir().'/openbuilt-exports';
        if (is_dir($exportsRoot) === false) {
            return;
        }

        $now          = time();
        $expiryWindow = 86400;
        // 24h
        $purged   = 0;
        $zipPaths = glob($exportsRoot.'/*.zip');
        if ($zipPaths === false) {
            $zipPaths = [];
        }

        foreach ($zipPaths as $zip) {
            $mtime = filemtime($zip);
            if ($mtime !== false && ($now - $mtime) > $expiryWindow) {
                // Suppress unlink warnings — concurrent cleanup of the same
                // ZIP from a sibling worker is harmless and need not be logged.
                if (unlink($zip) === true) {
                    $purged++;
                }
            }
        }

        if ($purged > 0) {
            $this->logger->info('OpenBuilt cleanup: purged '.$purged.' expired export archive(s)');
        }
    }//end run()
}//end class
