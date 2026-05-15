<?php

/**
 * OpenBuilt Icon Service
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
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;

/**
 * Resolves per-app SVG icons from OR-attached files with a fallback chain.
 *
 * Returns a stream (resource) + MIME type; callers are responsible for
 * closing the stream after it has been consumed.
 */
class IconService
{
    /**
     * Register slug that hosts Application objects.
     */
    private const REGISTER_SLUG = 'openbuilt';

    /**
     * Schema slug for Application objects.
     */
    private const APPLICATION_SCHEMA = 'application';

    /**
     * Filesystem path to the built-in light icon, relative to server root.
     */
    private const FALLBACK_LIGHT_PATH = '/custom_apps/openbuilt/img/app.svg';

    /**
     * Filesystem path to the built-in dark icon, relative to server root.
     */
    private const FALLBACK_DARK_PATH = '/custom_apps/openbuilt/img/app-dark.svg';

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
     * @param ObjectService   $objectService OpenRegister object service
     * @param FileService     $fileService   OpenRegister file service
     * @param LoggerInterface $logger        PSR logger
     * @param string|null     $serverRoot    Server root override (defaults to \OC::$SERVERROOT)
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly FileService $fileService,
        private readonly LoggerInterface $logger,
        ?string $serverRoot=null,
    ) {
        $this->serverRoot = ($serverRoot ?? (\OC::$SERVERROOT ?? ''));
    }//end __construct()

    /**
     * Return a stream + MIME type for the icon of a given Application slug.
     *
     * Light chain:  icon.ref → /img/app.svg
     * Dark  chain:  iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
     *
     * @param string $slug The Application slug.
     * @param bool   $dark True to apply the dark-icon fallback chain.
     *
     * @return array{stream: resource|null, mimeType: string} The stream and MIME type.
     *                                                         stream is null only when
     *                                                         no filesystem fallback
     *                                                         exists (practically never).
     */
    public function getIconStream(string $slug, bool $dark): array
    {
        $application = $this->fetchApplication(slug: $slug);

        if ($dark === true) {
            return $this->resolveIconDark(application: $application);
        }

        return $this->resolveIconLight(application: $application);
    }//end getIconStream()

    /**
     * Fetch the Application object by slug.
     *
     * Returns the decoded array on success; null when OR is unavailable or
     * the slug does not match any Application.
     *
     * @param string $slug The Application slug.
     *
     * @return array<string,mixed>|null Application data array, or null.
     */
    private function fetchApplication(string $slug): ?array
    {
        try {
            $results = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::REGISTER_SLUG,
                        'schema'   => self::APPLICATION_SCHEMA,
                        'slug'     => $slug,
                    ],
                    'limit'   => 1,
                ]
            );

            if (empty($results) === true) {
                return null;
            }

            $first = reset($results);

            return $this->normaliseObject(object: $first);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'IconService: failed to fetch Application for slug "'.$slug.'": '.$e->getMessage()
            );
            return null;
        }//end try
    }//end fetchApplication()

    /**
     * Resolve the light-icon fallback chain.
     *
     * Chain: icon.ref → /img/app.svg
     *
     * @param array<string,mixed>|null $application Application data or null.
     *
     * @return array{stream: resource|null, mimeType: string}
     */
    private function resolveIconLight(?array $application): array
    {
        if ($application !== null) {
            $icon = ($application['icon'] ?? null);
            if (is_array($icon) === true) {
                $ref = ($icon['ref'] ?? null);
                if (is_string($ref) === true && $ref !== '') {
                    $stream = $this->fetchAttachedFileStream(
                        application: $application,
                        filename: $ref
                    );
                    if ($stream !== null) {
                        return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
                    }
                }
            }
        }

        return $this->fallbackStream(path: $this->serverRoot.self::FALLBACK_LIGHT_PATH);
    }//end resolveIconLight()

    /**
     * Resolve the dark-icon fallback chain.
     *
     * Chain: iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
     *
     * @param array<string,mixed>|null $application Application data or null.
     *
     * @return array{stream: resource|null, mimeType: string}
     */
    private function resolveIconDark(?array $application): array
    {
        if ($application !== null) {
            // Step 1: iconDark.ref.
            $stream = $this->streamForIconField(application: $application, field: 'iconDark');
            if ($stream !== null) {
                return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
            }

            // Step 2: icon.ref (light icon as dark fallback).
            $stream = $this->streamForIconField(application: $application, field: 'icon');
            if ($stream !== null) {
                return ['stream' => $stream, 'mimeType' => 'image/svg+xml'];
            }
        }//end if

        // Step 3: /img/app-dark.svg.
        $darkPath = $this->serverRoot.self::FALLBACK_DARK_PATH;
        if (file_exists($darkPath) === true) {
            return $this->fallbackStream(path: $darkPath);
        }

        // Step 4: /img/app.svg.
        return $this->fallbackStream(path: $this->serverRoot.self::FALLBACK_LIGHT_PATH);
    }//end resolveIconDark()

    /**
     * Resolve the attached file stream for a named icon field on an Application.
     *
     * Returns null when the field is absent, has no ref, or the file cannot be fetched.
     *
     * @param array<string,mixed> $application Application data array.
     * @param string              $field       The icon field name (e.g. `iconDark`, `icon`).
     *
     * @return resource|null A readable PHP stream, or null on failure.
     */
    private function streamForIconField(array $application, string $field): mixed
    {
        $iconField = ($application[$field] ?? null);
        if (is_array($iconField) === false) {
            return null;
        }

        $ref = ($iconField['ref'] ?? null);
        if (is_string($ref) === false || $ref === '') {
            return null;
        }

        return $this->fetchAttachedFileStream(application: $application, filename: $ref);
    }//end streamForIconField()

    /**
     * Fetch a file attached to an Application record from OR as a PHP stream.
     *
     * Returns null when the file cannot be retrieved (OR error, file not
     * found, etc.) so the caller can step to the next fallback.
     *
     * @param array<string,mixed> $application Application data array.
     * @param string              $filename    The attached file name.
     *
     * @return resource|null A readable PHP stream, or null on failure.
     */
    private function fetchAttachedFileStream(array $application, string $filename): mixed
    {
        try {
            $uuid = $this->extractUuid(application: $application);
            if ($uuid === null) {
                return null;
            }

            $file = $this->fileService->getFile(object: $uuid, file: $filename);
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
                'IconService: could not fetch attached file "'.$filename.'": '.$e->getMessage()
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
    private function fallbackStream(string $path): array
    {
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
    private function extractUuid(array $application): ?string
    {
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
    private function normaliseObject(mixed $object): array
    {
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
