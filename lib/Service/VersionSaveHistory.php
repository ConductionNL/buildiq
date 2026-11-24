<?php

/**
 * VersionSaveHistory
 *
 * Keeps the manifest of every save of an app version, so a maker can roll a
 * version back to an earlier save (REQ-BQCV-015). OpenRegister's audit trail
 * lists the saves but cuts large values such as a manifest, so it cannot give
 * an earlier manifest back; this store keeps them whole, in the app's own
 * storage, at most MAX_SAVES per version.
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git_id>
 *
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;

/**
 * The saved manifests of app versions.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSaveHistory {

	/**
	 * The app-data folder the saves live in, one subfolder per version.
	 *
	 * @var string
	 */
	public const FOLDER = 'version-saves';

	/**
	 * The most saves kept per version; older ones are removed.
	 *
	 * @var int
	 */
	public const MAX_SAVES = 50;

	/**
	 * The shape of a version id and of a save id, so neither can reach outside its folder.
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/';

	/**
	 * Constructor.
	 *
	 * @param IAppData     $appData The app's own storage.
	 * @param ITimeFactory $time    The clock a save is stamped with.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Keep a save of a version's manifest; a manifest equal to the newest save is not kept again.
	 *
	 * @param string       $versionUuid The version.
	 * @param array<mixed> $manifest    The manifest as saved.
	 * @param string       $user        Who saved it ('' when unknown).
	 *
	 * @return string|null The save's id, or null when nothing was kept.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	public function record(string $versionUuid, array $manifest, string $user): ?string {
		if (preg_match(self::ID_PATTERN, $versionUuid) !== 1) {
			return null;
		}

		$folder = $this->folder(versionUuid: $versionUuid, create: true);
		$files  = $this->newestFirst(folder: $folder);
		if ($files !== [] && ($this->decode(file: $files[0])['manifest'] ?? null) === $manifest) {
			return null;
		}

		$moment = $this->time->getDateTime();
		$id     = $moment->format('YmdHisv') . '-' . bin2hex(random_bytes(4));
		$folder->newFile(
			$id . '.json',
			(string)json_encode(['savedAt' => $moment->format(DATE_ATOM), 'user' => $user, 'manifest' => $manifest])
		);

		foreach (array_slice($files, (self::MAX_SAVES - 1)) as $old) {
			$old->delete();
		}

		return $id;
	}//end record()

	/**
	 * The saves of a version, newest first: id, time, who, and the ids of its pages.
	 *
	 * @param string $versionUuid The version.
	 *
	 * @return list<array{id:string,savedAt:string,user:string,pages:list<string>}>
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	public function saves(string $versionUuid): array {
		if (preg_match(self::ID_PATTERN, $versionUuid) !== 1) {
			return [];
		}

		$saves = [];
		foreach ($this->newestFirst(folder: $this->folder(versionUuid: $versionUuid, create: false)) as $file) {
			$save    = $this->decode(file: $file);
			$saves[] = [
				'id'      => substr($file->getName(), 0, -5),
				'savedAt' => (string)($save['savedAt'] ?? ''),
				'user'    => (string)($save['user'] ?? ''),
				'pages'   => self::pageIds(manifest: ($save['manifest'] ?? null)),
			];
		}

		return $saves;
	}//end saves()

	/**
	 * The manifest of one save of a version.
	 *
	 * @param string $versionUuid The version.
	 * @param string $saveId      The save.
	 *
	 * @return array<mixed>|null The manifest, or null when there is no such save.
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	public function manifest(string $versionUuid, string $saveId): ?array {
		if (preg_match(self::ID_PATTERN, $versionUuid) !== 1 || preg_match(self::ID_PATTERN, $saveId) !== 1) {
			return null;
		}

		$folder = $this->folder(versionUuid: $versionUuid, create: false);
		if ($folder === null || $folder->fileExists($saveId . '.json') === false) {
			return null;
		}

		$manifest = ($this->decode(file: $folder->getFile($saveId . '.json'))['manifest'] ?? null);
		if (is_array($manifest) === false) {
			return null;
		}

		return $manifest;
	}//end manifest()

	/**
	 * The ids of a manifest's pages, in order.
	 *
	 * @param mixed $manifest The manifest.
	 *
	 * @return list<string>
	 */
	private static function pageIds(mixed $manifest): array {
		$ids = [];
		foreach ((array)($manifest['pages'] ?? []) as $page) {
			if (is_array($page) === true && is_scalar($page['id'] ?? null) === true) {
				$ids[] = (string)$page['id'];
			}
		}

		return $ids;
	}//end pageIds()

	/**
	 * A version's folder; null when it has none and none is to be made.
	 *
	 * @param string $versionUuid The version.
	 * @param bool   $create      Whether to make the folders that are missing.
	 *
	 * @return ISimpleFolder|null
	 */
	private function folder(string $versionUuid, bool $create): ?ISimpleFolder {
		try {
			$root = $this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException $e) {
			if ($create === false) {
				return null;
			}

			$root = $this->appData->newFolder(self::FOLDER);
		}

		try {
			return $root->getFolder($versionUuid);
		} catch (NotFoundException $e) {
			if ($create === false) {
				return null;
			}

			return $root->newFolder($versionUuid);
		}
	}//end folder()

	/**
	 * The save files of a folder, newest first (ids start with the time they were saved).
	 *
	 * @param ISimpleFolder|null $folder The version's folder.
	 *
	 * @return list<ISimpleFile>
	 */
	private function newestFirst(?ISimpleFolder $folder): array {
		if ($folder === null) {
			return [];
		}

		$files = array_values(
			array_filter($folder->getDirectoryListing(), static fn (ISimpleFile $file): bool => str_ends_with($file->getName(), '.json'))
		);
		usort($files, static fn (ISimpleFile $left, ISimpleFile $right): int => strcmp($right->getName(), $left->getName()));

		return $files;
	}//end newestFirst()

	/**
	 * A save file's contents.
	 *
	 * @param ISimpleFile $file The save file.
	 *
	 * @return array<mixed>
	 */
	private function decode(ISimpleFile $file): array {
		$data = json_decode($file->getContent(), true);
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end decode()
}//end class
