<?php

/**
 * Buildiq Cleanup Expired Exports
 *
 * Daily background job that purges expired ZIP archives from app-data while
 * preserving the ExportJob audit trail.
 *
 * @category BackgroundJob
 * @package  OCA\Buildiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-36
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\BackgroundJob;

use OCA\Buildiq\Service\ExportArchiveStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * 24-hour cleanup job for expired export archives.
 *
 * @spec openspec/changes/archive/2026-06-14-openbuild-exporter/tasks.md#task-5.3
 */
class CleanupExpiredExports extends TimedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory.
	 * @param LoggerInterface $logger Logger.
	 * @param ExportArchiveStore|null $archives The archives kept in app data (REQ-PLAT-010).
	 */
	public function __construct(
		ITimeFactory $time,
		private LoggerInterface $logger,
		private ?ExportArchiveStore $archives = null,
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
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-36
	 */
	protected function run($argument): void {
		unset($argument);

		$now = time();
		$expiryWindow = 86400;
		// 24h
		// The copies kept in app data for the other web servers go with the
		// local ones (REQ-PLAT-010).
		$purged = ($this->archives?->purgeOlderThan(now: $now, window: $expiryWindow) ?? 0);

		$exportsRoot = sys_get_temp_dir() . '/buildiq-exports';
		$zipPaths = [];
		if (is_dir($exportsRoot) === true) {
			$zipPaths = glob($exportsRoot . '/*.zip');
		}

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
			$this->logger->info('Buildiq cleanup: purged ' . $purged . ' expired export archive(s)');
		}
	}//end run()
}//end class
