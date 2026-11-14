<?php

/**
 * Buildiq Export Archive Store
 *
 * Keeps each finished export ZIP in Nextcloud's app data (`exports/{jobId}.zip`)
 * beside the local staging copy, so a download served by another web server
 * finds the file (REQ-PLAT-010). The exporter still builds the archive on a
 * local path, because recursive copies and deterministic ZIP packaging need
 * one; the shared copy is what a second server reads.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads, writes and purges export archives in app data.
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
 */
class ExportArchiveStore {
	/**
	 * The app-data folder the archives live in.
	 */
	public const FOLDER = 'exports';

	/**
	 * Constructor.
	 *
	 * @param IAppData        $appData The app's shared storage.
	 * @param LoggerInterface $logger  Where a failed copy or purge is noted.
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The archive file name of a job. Only a UUID-shaped id names a file, so
	 * an id can never reach outside the folder.
	 *
	 * @param string $jobUuid The ExportJob UUID.
	 *
	 * @return string|null The file name, or null for an id that is not one.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public static function fileName(string $jobUuid): ?string {
		if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $jobUuid) !== 1) {
			return null;
		}

		return $jobUuid . '.zip';
	}//end fileName()

	/**
	 * Copy a finished local ZIP into app data. A failure is logged and
	 * otherwise ignored: the local copy still serves this server.
	 *
	 * @param string $jobUuid  The ExportJob UUID.
	 * @param string $localZip The finished archive on the local disk.
	 *
	 * @return bool Whether the shared copy was written.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public function store(string $jobUuid, string $localZip): bool {
		$name = self::fileName(jobUuid: $jobUuid);
		if ($name === null || is_readable($localZip) === false) {
			return false;
		}

		$bytes = file_get_contents($localZip);
		if ($bytes === false) {
			return false;
		}

		try {
			$folder = $this->folder();
			if ($folder->fileExists($name) === true) {
				$folder->getFile($name)->putContent($bytes);
				return true;
			}

			$folder->newFile($name, $bytes);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq export: could not keep the archive in app data; only this server can serve it',
				['jobUuid' => $jobUuid, 'reason' => $e->getMessage()]
			);
			return false;
		}
	}//end store()

	/**
	 * The bytes of a job's archive in app data.
	 *
	 * @param string $jobUuid The ExportJob UUID.
	 *
	 * @return string|null The archive, or null when app data holds none.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public function read(string $jobUuid): ?string {
		$name = self::fileName(jobUuid: $jobUuid);
		if ($name === null) {
			return null;
		}

		try {
			return $this->appData->getFolder(self::FOLDER)->getFile($name)->getContent();
		} catch (NotFoundException) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq export: could not read the archive from app data', ['jobUuid' => $jobUuid, 'reason' => $e->getMessage()]);
			return null;
		}
	}//end read()

	/**
	 * Delete every archive in app data older than the window.
	 *
	 * @param int $now    The current Unix time.
	 * @param int $window The age in seconds after which an archive goes.
	 *
	 * @return int How many archives were deleted.
	 *
	 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
	 */
	public function purgeOlderThan(int $now, int $window): int {
		try {
			$files = $this->appData->getFolder(self::FOLDER)->getDirectoryListing();
		} catch (NotFoundException) {
			return 0;
		}

		$purged = 0;
		foreach ($files as $file) {
			if (str_ends_with($file->getName(), '.zip') === false || ($now - $file->getMTime()) <= $window) {
				continue;
			}

			try {
				$file->delete();
				$purged++;
			} catch (Throwable $e) {
				// A sibling worker deleting the same archive is harmless.
				$this->logger->debug('Buildiq cleanup: an app-data archive could not be deleted', ['name' => $file->getName(), 'reason' => $e->getMessage()]);
			}
		}

		return $purged;
	}//end purgeOlderThan()

	/**
	 * The exports folder, created on first use.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(): ISimpleFolder {
		try {
			return $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			return $this->appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end class
