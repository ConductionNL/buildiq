<?php

/**
 * The built offline worker script a running app registers.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Offline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Offline;

/**
 * Reads the worker bundle webpack writes, and stamps it with its version.
 *
 * The bundle is the library's shell worker (`src/offlineWorker.js`): it caches
 * the app shell and the bundle and never an object read. The version is a
 * hash of the bundle, so a new build replaces the cached shell on the device.
 *
 * @spec openspec/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
 */
class OfflineWorkerScript {

	/**
	 * The built file, relative to the app root.
	 *
	 * @var string
	 */
	public const BUNDLE = 'js/buildiq-offline-worker.js';

	/**
	 * Constructor.
	 *
	 * @param string $path The bundle's path; the app's own build when empty.
	 *
	 * @spec openspec/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
	 */
	public function __construct(
		private readonly string $path='',
	) {
	}//end __construct()

	/**
	 * The script to serve, version first, or null when the bundle was not built.
	 *
	 * @return string|null The script.
	 *
	 * @spec openspec/specs/installable-offline-apps/spec.md#requirement-a-maker-declares-what-goes-offline-req-bqom-003
	 */
	public function script(): ?string {
		$path = $this->path;
		if ($path === '') {
			$path = dirname(__DIR__, 3) . '/' . self::BUNDLE;
		}

		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		$bundle = (string)file_get_contents($path);
		if ($bundle === '') {
			return null;
		}

		$version = substr(hash('sha256', $bundle), 0, 16);

		return 'self.CN_OFFLINE_BUNDLE_VERSION = ' . json_encode($version) . ";\n" . $bundle;
	}//end script()
}//end class
