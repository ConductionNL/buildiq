<?php

/**
 * Buildiq Icon Service
 *
 * Resolves per-application SVG icons from OR-attached files with a filesystem
 * fallback chain.  Decision 2 in design.md defines the fallback order:
 *
 *  Light:  icon.ref → /img/app.svg
 *  Dark:   iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
 *
 * ADR-001: icons live on the Application record as OR-attached files.
 * ADR-031 §Exceptions: icon URL resolution crosses OR + filesystem + NC's
 * IURLGenerator; outside OR's calculation vocabulary → imperative.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-app-icon-management-uuid/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;

/**
 * Resolves per-app SVG icons from OR-attached files with a fallback chain.
 *
 * Returns a stream (resource) + MIME type; callers are responsible for
 * closing the stream after it has been consumed.
 */
class IconService {
	/**
	 * Register slug that hosts Application objects.
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slug for Application objects.
	 */
	private const APPLICATION_SCHEMA = 'built-app';

	/**
	 * Built-in fallback icon filenames, resolved against the app's real `img/`
	 * directory by {@see bundledImgDir()} (NOT a hardcoded install path).
	 */
	private const FALLBACK_LIGHT_FILE = 'app.svg';

	private const FALLBACK_DARK_FILE = 'app-dark.svg';

	/**
	 * Filesystem server root, used to locate fallback icon files.
	 *
	 * Injected so unit tests can override without needing \OC::$SERVERROOT at all.
	 * Production: defaults to \OC::$SERVERROOT resolved at construction time.
	 *
	 * @var string
	 */
	private string $serverRoot;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service
	 * @param FileService $fileService OpenRegister file service
	 * @param LoggerInterface $logger PSR logger
	 * @param string|null $serverRoot Server root override (defaults to \OC::$SERVERROOT)
	 * @param IAppManager|null $appManager App manager, used to resolve app paths; optional so
	 *                                     the service stays constructible in unit tests
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly FileService $fileService,
		private readonly LoggerInterface $logger,
		?string $serverRoot = null,
		private readonly ?IAppManager $appManager = null,
	) {
		$this->serverRoot = ($serverRoot ?? (\OC::$SERVERROOT ?? ''));
	}//end __construct()

	/**
	 * Absolute filesystem path to Buildiq's bundled `img/` directory (no
	 * trailing slash), used for the built-in fallback icons.
	 *
	 * Resolved via IAppManager::getAppPath so it is correct wherever the app is
	 * installed (apps/, custom_apps/, apps-shared/, …) — the previous hardcoded
	 * `\OC::$SERVERROOT.'/custom_apps/buildiq/img'` only matched a custom_apps
	 * layout, so on any other layout the fallback file was missing and the icon
	 * endpoint returned 404 for every app without a custom icon. Falls back to
	 * the legacy serverRoot-relative path only when no app manager was injected
	 * (unit tests that pass an explicit serverRoot).
	 *
	 * @return string The absolute path to the app's img directory.
	 */
	private function bundledImgDir(): string {
		if ($this->appManager !== null) {
			try {
				return rtrim($this->appManager->getAppPath('buildiq'), '/') . '/img';
			} catch (\Throwable $e) {
				// App path unavailable — fall through to the legacy location.
				$this->logger->warning(
					'IconService: could not resolve buildiq app path, using legacy fallback: ' . $e->getMessage()
				);
			}
		}

		return $this->serverRoot . '/custom_apps/buildiq/img';
	}//end bundledImgDir()

	/**
	 * Return a stream + MIME type for the icon of a given Application slug.
	 *
	 * Light chain:  icon.ref → /img/app.svg
	 * Dark  chain:  iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
	 *
	 * @param string $slug The Application slug.
	 * @param bool $dark True to apply the dark-icon fallback chain.
	 *
	 * @return array{stream: resource|null, mimeType: string} The stream and MIME type.
	 *                                                        stream is null only when
	 *                                                        no filesystem fallback
	 *                                                        exists (practically never).
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 */
	public function getIconStream(string $slug, bool $dark): array {
		// Keep the entity, not just its serialized form. fetchAttachedFileStream()
		// hands it straight back to OpenRegister, which saves OR from having to work
		// out which magic table the object lives in — see fetchAttachedFileStream().
		$entity = $this->fetchApplicationEntity(slug: $slug);
		$application = null;
		if ($entity !== null) {
			$application = $this->normaliseObject(object: $entity);
		}

		if ($dark === true) {
			return $this->resolveIconDark(application: $application, entity: $entity);
		}

		return $this->resolveIconLight(application: $application, entity: $entity);
	}//end getIconStream()

	/**
	 * Fetch the Application entity by slug, as OpenRegister returned it.
	 *
	 * Returns the entity itself (an ObjectEntity in practice) rather than a decoded
	 * array, so callers can hand it back to OR instead of a bare UUID string.
	 *
	 * @param string $slug The Application slug.
	 *
	 * @return mixed The Application entity, or null when OR is unavailable or the
	 *               slug matches no Application.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-1
	 */
	private function fetchApplicationEntity(string $slug): mixed {
		try {
			$results = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER_SLUG,
						'schema' => self::APPLICATION_SCHEMA,
						'slug' => $slug,
					],
					'limit' => 1,
				]
			);

			if (empty($results) === true) {
				return null;
			}

			return reset($results);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'IconService: failed to fetch Application for slug "' . $slug . '": ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end fetchApplicationEntity()

	/**
	 * Resolve the light-icon fallback chain.
	 *
	 * Chain: icon.ref → /img/app.svg
	 *
	 * @param array<string,mixed>|null $application Application data or null.
	 * @param mixed $entity The Application entity, when known.
	 *
	 * @return array{stream: resource|null, mimeType: string}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 */
	private function resolveIconLight(?array $application, mixed $entity = null): array {
		if ($application !== null) {
			$icon = ($application['icon'] ?? null);
			if (is_array($icon) === true) {
				$ref = ($icon['ref'] ?? null);
				if (is_string($ref) === true && $ref !== '') {
					$stream = $this->fetchAttachedFileStream(
						application: $application,
						filename: $ref,
						entity: $entity
					);
					if ($stream !== null) {
						return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
					}
				}
			}
		}

		return $this->fallbackStream(path: $this->bundledImgDir() . '/' . self::FALLBACK_LIGHT_FILE);
	}//end resolveIconLight()

	/**
	 * Resolve the dark-icon fallback chain.
	 *
	 * Chain: iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
	 *
	 * @param array<string,mixed>|null $application Application data or null.
	 * @param mixed $entity The Application entity, when known.
	 *
	 * @return array{stream: resource|null, mimeType: string}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 */
	private function resolveIconDark(?array $application, mixed $entity = null): array {
		if ($application !== null) {
			// Step 1: iconDark.ref.
			$stream = $this->streamForIconField(application: $application, field: 'iconDark', entity: $entity);
			if ($stream !== null) {
				return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
			}

			// Step 2: icon.ref (light icon as dark fallback).
			$stream = $this->streamForIconField(application: $application, field: 'icon', entity: $entity);
			if ($stream !== null) {
				return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
			}
		}//end if

		// Step 3: /img/app-dark.svg.
		$darkPath = $this->bundledImgDir() . '/' . self::FALLBACK_DARK_FILE;
		if (file_exists($darkPath) === true) {
			return $this->fallbackStream(path: $darkPath);
		}

		// Step 4: /img/app.svg.
		return $this->fallbackStream(path: $this->bundledImgDir() . '/' . self::FALLBACK_LIGHT_FILE);
	}//end resolveIconDark()

	/**
	 * Resolve the attached file stream for a named icon field on an Application.
	 *
	 * Returns null when the field is absent, has no ref, or the file cannot be fetched.
	 *
	 * @param array<string,mixed> $application Application data array.
	 * @param string $field The icon field name (e.g. `iconDark`, `icon`).
	 * @param mixed $entity The Application entity, when known.
	 *
	 * @return resource|null A readable PHP stream, or null on failure.
	 */
	private function streamForIconField(array $application, string $field, mixed $entity = null): mixed {
		$iconField = ($application[$field] ?? null);
		if (is_array($iconField) === false) {
			return null;
		}

		$ref = ($iconField['ref'] ?? null);
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		return $this->fetchAttachedFileStream(application: $application, filename: $ref, entity: $entity);
	}//end streamForIconField()

	/**
	 * Fetch a file attached to an Application record from OR as a PHP stream.
	 *
	 * Returns null when the file cannot be retrieved (OR error, file not
	 * found, etc.) so the caller can step to the next fallback.
	 *
	 * @param array<string,mixed> $application Application data array.
	 * @param string $filename The attached file name.
	 * @param mixed $entity The Application entity, when known. Passing
	 *                      it avoids a scan of every magic table.
	 *
	 * @return resource|null A readable PHP stream, or null on failure.
	 */
	private function fetchAttachedFileStream(array $application, string $filename, mixed $entity = null): mixed {
		try {
			// Hand OR the entity back when we actually have one. FileService::getFile()
			// takes an ObjectEntity or a UUID string — but given a STRING it calls
			// objectMapper->find($uuid), and a bare UUID carries no register/schema, so OR
			// has to search every magic table to work out which one owns the object. On
			// this instance that is ~1,960 tables, and it cost ~2s PER ICON, twice per page
			// load. We already hold the entity; passing it skips the search entirely.
			//
			// Only an ObjectEntity may be passed through: findAll() can also yield plain
			// arrays, and getFile() would reject one with a TypeError. Anything else falls
			// back to the UUID string (correct, just slow).
			$target = null;
			if ($entity instanceof ObjectEntity) {
				$target = $entity;
			}

			if ($target === null) {
				$target = $this->extractUuid(application: $application);
			}

			if ($target === null) {
				return null;
			}

			$file = $this->fileService->getFile(object: $target, file: $filename);
			if ($file === null) {
				return null;
			}

			$content = $file->getContent();
			if ($content === '' || $content === false) {
				return null;
			}

			$stream = fopen(filename: 'php://memory', mode: 'r+');
			if ($stream === false) {
				return null;
			}

			fwrite($stream, $content);
			rewind($stream);

			return $stream;
		} catch (\Throwable $e) {
			$this->logger->warning(
				'IconService: could not fetch attached file "' . $filename . '": ' . $e->getMessage()
			);
			return null;
		}//end try
	}//end fetchAttachedFileStream()

	/**
	 * Open a filesystem file as a stream.
	 *
	 * @param string $path Absolute filesystem path.
	 *
	 * @return array{stream: resource|null, mimeType: string}
	 */
	private function fallbackStream(string $path): array {
		if (file_exists($path) === false) {
			return ['stream' => null, 'mimeType' => 'image/svg+xml'];
		}

		$stream = fopen(filename: $path, mode: 'rb');
		if ($stream === false) {
			return ['stream' => null, 'mimeType' => 'image/svg+xml'];
		}

		return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
	}//end fallbackStream()

	/**
	 * Extract the OR UUID from a normalised Application array.
	 *
	 * @param array<string,mixed> $application Application data array.
	 *
	 * @return string|null The UUID, or null when missing.
	 */
	private function extractUuid(array $application): ?string {
		$self = ($application['@self'] ?? []);
		if (is_array($self) === true) {
			$candidate = ($self['id'] ?? ($self['uuid'] ?? null));
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		$direct = ($application['uuid'] ?? null);
		if (is_string($direct) === true && $direct !== '') {
			return $direct;
		}

		return null;
	}//end extractUuid()

	/**
	 * Coerce an OR result entry (ObjectEntity or array) to an associative array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normaliseObject(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normaliseObject()
}//end class
