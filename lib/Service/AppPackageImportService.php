<?php

/**
 * AppPackageImportService
 *
 * Imports an app archive exported by buildiq as a new app. The archive is read
 * as data and never run: only the package files are read, into memory, and
 * every entry is checked before anything is parsed or created. The package is
 * then parsed with the same rules as a GitHub install and installed through the
 * same seam, so an imported app is the same as one installed from a repository.
 * The upload route and `occ buildiq:app:import` both call this one service.
 *
 * @category  Service
 * @package   OCA\Buildiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Exception\AppRepoParseException;
use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AppRepoParserInterface;
use OCA\Buildiq\Kernel\Contracts\TemplateInstallSeamInterface;
use OCP\AppFramework\Http;
use ZipArchive;

/**
 * Reads an exported archive safely and installs it as a new app.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
 */
class AppPackageImportService implements AppPackageImportServiceInterface {

	/**
	 * The largest archive accepted, in bytes (50 MB).
	 *
	 * @var int
	 */
	public const MAX_ARCHIVE_BYTES = 52428800;

	/**
	 * The most entries an archive may hold.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 5000;

	/**
	 * The most bytes the read entries may unpack to (200 MB), the zip bomb stop.
	 *
	 * @var int
	 */
	public const MAX_UNPACKED_BYTES = 209715200;

	/**
	 * The records folder of an exported archive.
	 *
	 * @var string
	 */
	public const DATA_PREFIX = 'data/';

	/**
	 * The Unix file type bits of a symlink, in the high half of a zip entry's external attributes.
	 *
	 * @var int
	 */
	private const UNIX_SYMLINK = 0120000;

	/**
	 * The Unix file type mask.
	 *
	 * @var int
	 */
	private const UNIX_TYPE_MASK = 0170000;

	/**
	 * Constructor.
	 *
	 * @param AppRepoParserInterface       $parser  Parses the package with the GitHub install's rules.
	 * @param TemplateInstallSeamInterface $install The install seam GitHub installs and templates share.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppRepoParserInterface $parser,
		private readonly TemplateInstallSeamInterface $install,
	) {
	}//end __construct()

	/**
	 * Import an exported app archive as a new app owned by the given user.
	 *
	 * Nothing is created until the archive has passed every check and the
	 * package has parsed; a taken slug is refused by the install seam before it
	 * writes anything.
	 *
	 * @param string $archivePath Path of the uploaded or given archive.
	 * @param string $name        The new app's name; empty for the archive's own name.
	 * @param string $slug        The new app's slug; empty for the archive's own slug.
	 * @param string $ownerUid    The user who will own the new app.
	 *
	 * @return array{status:int,data:array<string,mixed>} 201 with the app; 400, 409, 413 or 422 with an error code.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-a-maker-imports-an-exported-archive-as-a-new-app-req-bqic-001
	 */
	public function import(string $archivePath, string $name, string $slug, string $ownerUid): array {
		try {
			$files = $this->readPackage(archivePath: $archivePath);
			$template = $this->parser->parse(files: $this->packageFiles(files: $files));
		} catch (AppRepoParseException $exception) {
			return self::refusal(exception: $exception);
		}

		$slug = self::orDefault(value: $slug, default: (string)($template['slug'] ?? ''));
		if (preg_match(AppRepoParser::SLUG_PATTERN, $slug) !== 1) {
			return [
				'status' => Http::STATUS_BAD_REQUEST,
				'data' => ['error' => 'invalid_slug', 'detail' => 'A slug is lowercase letters, digits and hyphens.'],
			];
		}

		return $this->install->installFromTemplateArray(
			template: $template,
			name: self::orDefault(value: $name, default: (string)($template['title'] ?? $slug)),
			newSlug: $slug,
			ownerUid: $ownerUid
		);
	}//end import()

	/**
	 * The package entries of an archive, read into memory after every entry is checked.
	 *
	 * @param string $archivePath The archive.
	 *
	 * @return array<string,string> The read entries, by path.
	 *
	 * @throws AppRepoParseException When the archive is too large, unreadable or holds an unsafe entry.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
	 */
	public function readPackage(string $archivePath): array {
		$size = @filesize($archivePath);
		if ($size === false) {
			throw new AppRepoParseException(errorCode: 'archive_unreadable', message: 'The archive could not be read.');
		}

		if ($size > self::MAX_ARCHIVE_BYTES) {
			throw new AppRepoParseException(errorCode: 'archive_too_large', message: 'The archive is larger than 50 MB.');
		}

		$zip = new ZipArchive();
		if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
			throw new AppRepoParseException(errorCode: 'archive_unreadable', message: 'The archive is not a readable zip file.');
		}

		try {
			return $this->readEntries(zip: $zip);
		} finally {
			$zip->close();
		}
	}//end readPackage()

	/**
	 * Check every entry, then read the package entries within the unpacked-size limit.
	 *
	 * @param ZipArchive $zip The open archive.
	 *
	 * @return array<string,string> The read entries, by path.
	 *
	 * @throws AppRepoParseException When an entry is unsafe or the limits are passed.
	 */
	private function readEntries(ZipArchive $zip): array {
		if ($zip->numFiles > self::MAX_ENTRIES) {
			throw new AppRepoParseException(errorCode: 'archive_too_many_entries', message: 'The archive holds more than 5,000 entries.');
		}

		$paths = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$path = (string)$zip->getNameIndex($index);
			self::assertSafeEntry(zip: $zip, index: $index, path: $path);
			$paths[$index] = $path;
		}

		$files = [];
		$remaining = self::MAX_UNPACKED_BYTES;
		foreach ($paths as $index => $path) {
			if (self::isPackagePath(path: $path) === false) {
				continue;
			}

			// Read one byte past what is left, so a bomb is caught without unpacking it whole.
			$contents = (string)$zip->getFromIndex($index, ($remaining + 1));
			$remaining -= strlen($contents);
			if ($remaining < 0) {
				throw new AppRepoParseException(errorCode: 'archive_too_large_unpacked', message: 'The archive unpacks to more than 200 MB.', filePath: $path);
			}

			$files[$path] = $contents;
		}

		return $files;
	}//end readEntries()

	/**
	 * Refuse an entry with an absolute path, a `..` segment or a symlink.
	 *
	 * @param ZipArchive $zip   The open archive.
	 * @param int        $index The entry index.
	 * @param string     $path  The entry path.
	 *
	 * @return void
	 *
	 * @throws AppRepoParseException When the entry is unsafe.
	 */
	private static function assertSafeEntry(ZipArchive $zip, int $index, string $path): void {
		$normalised = str_replace('\\', '/', $path);
		$absolute = (str_starts_with($normalised, '/') === true || preg_match('/^[A-Za-z]:/', $normalised) === 1);
		if ($absolute === true || in_array('..', explode('/', $normalised), true) === true || $path === '') {
			throw new AppRepoParseException(errorCode: 'archive_unsafe_path', message: 'The archive holds an entry with an unsafe path.', filePath: $path);
		}

		$opsys = 0;
		$attributes = 0;
		if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) === true
			&& $opsys === ZipArchive::OPSYS_UNIX
			&& ((($attributes >> 16) & self::UNIX_TYPE_MASK) === self::UNIX_SYMLINK)
		) {
			throw new AppRepoParseException(errorCode: 'archive_symlink', message: 'The archive holds a symbolic link.', filePath: $path);
		}
	}//end assertSafeEntry()

	/**
	 * Whether an entry is part of the package: the descriptor, the manifest, a package folder or `data/`.
	 *
	 * Everything else in an export (the Nextcloud app's `lib/`, `src/`, `appinfo/`) is ignored.
	 *
	 * @param string $path The entry path.
	 *
	 * @return bool True for a package file.
	 *
	 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
	 */
	public static function isPackagePath(string $path): bool {
		if (str_ends_with($path, '/') === true) {
			return false;
		}

		if ($path === AppRepoParser::DESCRIPTOR_FILE || $path === AppRepoParser::MANIFEST_FILE) {
			return true;
		}

		foreach (self::packagePrefixes() as $prefix) {
			if (str_starts_with($path, $prefix) === true) {
				return true;
			}
		}

		return false;
	}//end isPackagePath()

	/**
	 * The folders the package format defines, plus `data/`.
	 *
	 * @return array<int,string> The prefixes.
	 */
	private static function packagePrefixes(): array {
		return [
			AppRepoParser::SCHEMAS_PREFIX,
			AppRepoParser::DATA_REGISTERS_PREFIX,
			AppRepoParser::CONNECTORS_PREFIX,
			AppRepoParser::AUTOMATIONS_PREFIX,
			AppRepoParser::SKILLS_PREFIX,
			AppRepoParser::FLOWS_PREFIX,
			AppRepoParser::AGENTS_PREFIX,
			self::DATA_PREFIX,
		];
	}//end packagePrefixes()

	/**
	 * The entries the parser reads: everything but the records.
	 *
	 * @param array<string,string> $files The read entries.
	 *
	 * @return array<string,string> The package files.
	 */
	private function packageFiles(array $files): array {
		return array_filter(
			$files,
			static fn (string $path): bool => str_starts_with($path, self::DATA_PREFIX) === false,
			ARRAY_FILTER_USE_KEY
		);
	}//end packageFiles()

	/**
	 * The refusal for a bad archive or package: its code, message and the file at fault.
	 *
	 * @param AppRepoParseException $exception The refusal.
	 *
	 * @return array{status:int,data:array<string,mixed>} 413 for a too-large archive, else 422.
	 */
	private static function refusal(AppRepoParseException $exception): array {
		$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		if (in_array($exception->getErrorCode(), ['archive_too_large', 'archive_too_large_unpacked', 'archive_too_many_entries'], true) === true) {
			$status = Http::STATUS_REQUEST_ENTITY_TOO_LARGE;
		}

		return [
			'status' => $status,
			'data' => ['error' => $exception->getErrorCode(), 'detail' => $exception->getMessage(), 'path' => $exception->getFilePath()],
		];
	}//end refusal()

	/**
	 * The value, or the default when it is empty.
	 *
	 * @param string $value   The given value.
	 * @param string $default The default.
	 *
	 * @return string The value to use.
	 */
	private static function orDefault(string $value, string $default): string {
		$value = trim($value);
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end orDefault()
}//end class
