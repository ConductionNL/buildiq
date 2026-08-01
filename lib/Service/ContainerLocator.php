<?php

/**
 * OpenBuild ContainerLocator
 *
 * Resolves a service class from the server container by name, returning null when
 * it cannot be resolved.
 *
 * This exists so that OPTIONAL cross-app dependencies stay optional. OpenBuild
 * declares only `openregister`; `hermiq` and `openconnector` may be absent, so
 * their services cannot be constructor-injected — an unresolvable type hint would
 * break OpenBuild's own container wiring on an instance that simply does not have
 * the other app installed.
 *
 * Keeping the lookup behind this one seam also keeps it stubbable in tests: an
 * applier that reached into the global container directly could only be tested on
 * an instance that really had the other app enabled.
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
 *
 * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-absent-optional-dependency-degrades-with-a-stated-reason
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCP\IServerContainer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Lazily resolves optional cross-app services.
 */
class ContainerLocator
{
    /**
     * Constructor.
     *
     * @param IServerContainer $container The server container.
     * @param LoggerInterface  $logger    PSR logger.
     *
     * @return void
     */
    public function __construct(
        private readonly IServerContainer $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve a service by class name.
     *
     * @param string $className Fully-qualified class name.
     *
     * @return object|null The service, or null when it cannot be resolved.
     *
     * @spec openspec/changes/apply-v2-channels/specs/app-channel-application/spec.md#requirement-an-absent-optional-dependency-degrades-with-a-stated-reason
     */
    public function get(string $className): ?object
    {
        $name = ltrim($className, '\\');
        if (class_exists($name) === false && interface_exists($name) === false) {
            return null;
        }

        try {
            $service = $this->container->get($name);
        } catch (Throwable $e) {
            $this->logger->debug(
                'OpenBuild: optional service "'.$name.'" could not be resolved: '.$e->getMessage()
            );

            return null;
        }

        if (is_object($service) === false) {
            return null;
        }

        return $service;

    }//end get()
}//end class
