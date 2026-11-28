<?php

/**
 * Buildiq BundledManifestReader
 *
 * Reads the manifest a Nextcloud app ships in its own folder
 * (`src/manifest.json`), the file a fleet app's loader starts from. Used when
 * a version extends a fleet app (`baseRef.kind: fleet-app`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads an installed app's bundled `src/manifest.json`.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
 */
class BundledManifestReader {
	private const MANIFEST_PATH = '/src/manifest.json';

	/**
	 * Constructor.
	 *
	 * @param IAppManager     $appManager Locates the app's folder.
	 * @param LoggerInterface $logger     Records why a bundle could not be read.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The app's bundled manifest, or null when the id is not an app id, the app is
	 * not installed, or it ships no readable manifest.
	 *
	 * @param string $appId The app id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function read(string $appId): ?array {
		if (preg_match('/^[a-z0-9_-]+$/', $appId) !== 1) {
			return null;
		}

		try {
			$path = rtrim($this->appManager->getAppPath($appId), '/') . self::MANIFEST_PATH;
		} catch (Throwable $e) {
			$this->logger->debug('BundledManifestReader: {app} is not installed: {message}', ['app' => $appId, 'message' => $e->getMessage()]);
			return null;
		}

		if (is_readable($path) === false) {
			return null;
		}

		$manifest = json_decode((string)file_get_contents($path), true);
		if (is_array($manifest) === false) {
			return null;
		}

		return $manifest;
	}//end read()
}//end class
