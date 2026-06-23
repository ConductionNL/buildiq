<?php

/**
 * OpenBuild Application Deletion Service
 *
 * Tears down a virtual Application and everything it owns: its
 * ApplicationVersions, each version's per-version OpenRegister register, the
 * BuiltAppRoute slug-index entries, and finally the Application object itself.
 *
 * Best-effort: a per-resource failure is logged and collected into the returned
 * `orphaned` list rather than aborting the whole teardown (mirrors the wizard's
 * rollback semantics so a partial create can still be cleaned up).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
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

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Imperative teardown of an Application and its owned resources.
 */
class ApplicationDeletionService
{
    /**
     * Constructor.
     *
     * @param ObjectService   $objectService   OR object surface (find + delete)
     * @param RegisterService $registerService OR register-level service (delete)
     * @param RegisterMapper  $registerMapper  OR register lookup (by slug)
     * @param LoggerInterface $logger          PSR logger
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
        private readonly RegisterService $registerService,
        private readonly RegisterMapper $registerMapper,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Delete an Application plus its versions, per-version registers and routes.
     *
     * @param string $appUuid The Application UUID.
     * @param string $appSlug The Application slug (for log context).
     *
     * @return array<int,string> Resources that could not be removed (orphaned).
     */
    public function deleteApplication(string $appUuid, string $appSlug): array
    {
        $orphaned = [];

        // 1. Versions + their per-version registers.
        foreach ($this->findChildren(schema: ApplicationVersionService::APPLICATION_VERSION_SCHEMA, field: 'application', value: $appUuid) as $version) {
            $versionUuid  = (string) ($version['id'] ?? ($version['@self']['id'] ?? ''));
            $registerSlug = (string) ($version['register'] ?? '');
            if ($registerSlug !== '') {
                $this->deleteRegister(registerSlug: $registerSlug, orphaned: $orphaned);
            }

            if ($versionUuid !== '') {
                $this->deleteObject(uuid: $versionUuid, label: 'version', orphaned: $orphaned);
            }
        }

        // 2. BuiltAppRoute slug-index entries pointing at this app.
        foreach ($this->findChildren(schema: 'built-app-route', field: 'applicationUuid', value: $appUuid) as $route) {
            $routeUuid = (string) ($route['id'] ?? ($route['@self']['id'] ?? ''));
            if ($routeUuid !== '') {
                $this->deleteObject(uuid: $routeUuid, label: 'route', orphaned: $orphaned);
            }
        }

        // 3. The Application object itself.
        $this->deleteObject(uuid: $appUuid, label: 'application', orphaned: $orphaned);

        if ($orphaned !== []) {
            $this->logger->warning(
                'OpenBuild: deleteApplication({slug}) left orphaned resources: {orphaned}',
                ['slug' => $appSlug, 'orphaned' => implode(', ', $orphaned)]
            );
        }

        return $orphaned;
    }//end deleteApplication()

    /**
     * Find child objects of the application by a filter field.
     *
     * @param string $schema The schema slug to query.
     * @param string $field  The filter field name.
     * @param string $value  The filter value (the app UUID).
     *
     * @return array<int,array<string,mixed>> Normalised child object arrays.
     */
    private function findChildren(string $schema, string $field, string $value): array
    {
        try {
            $results = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => ApplicationVersionService::REGISTER_SLUG,
                        'schema'   => $schema,
                        $field     => $value,
                    ],
                    'limit'   => 1000,
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: deleteApplication failed to query {schema}: {message}',
                ['schema' => $schema, 'message' => $e->getMessage()]
            );
            return [];
        }

        $out = [];
        foreach ($results as $item) {
            if (is_array($item) === true) {
                $out[] = $item;
                continue;
            }

            if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
                $serialised = $item->jsonSerialize();
                if (is_array($serialised) === true) {
                    $out[] = $serialised;
                }
            }
        }//end foreach

        return $out;
    }//end findChildren()

    /**
     * Delete a per-version register by slug (best-effort).
     *
     * @param string             $registerSlug The register slug.
     * @param array<int,string> &$orphaned     Collector for failures.
     *
     * @return void
     */
    private function deleteRegister(string $registerSlug, array &$orphaned): void
    {
        try {
            $register = $this->registerMapper->find($registerSlug, _multitenancy: false);
            $this->registerService->delete(register: $register);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: deleteApplication failed to delete register {slug}: {message}',
                ['slug' => $registerSlug, 'message' => $e->getMessage()]
            );
            $orphaned[] = 'register:'.$registerSlug;
        }
    }//end deleteRegister()

    /**
     * Delete an object by UUID (best-effort).
     *
     * @param string             $uuid      The object UUID.
     * @param string             $label     A short label for the orphaned list.
     * @param array<int,string> &$orphaned  Collector for failures.
     *
     * @return void
     */
    private function deleteObject(string $uuid, string $label, array &$orphaned): void
    {
        try {
            $this->objectService->deleteObject(uuid: $uuid);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: deleteApplication failed to delete {label} {uuid}: {message}',
                ['label' => $label, 'uuid' => $uuid, 'message' => $e->getMessage()]
            );
            $orphaned[] = $label.':'.$uuid;
        }
    }//end deleteObject()
}//end class
