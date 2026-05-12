<?php

/**
 * OpenBuilt Applications Controller
 *
 * Serves the per-virtual-app manifest endpoint. Per design.md Decision 6
 * this is the ONLY app-local controller surface — all CRUD on Application
 * and BuiltAppRoute objects is delegated to OpenRegister's REST API
 * directly (ADR-022).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuilt\Controller
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

namespace OCA\OpenBuilt\Controller;

use OCA\OpenBuilt\AppInfo\Application;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for the OpenBuilt manifest endpoint.
 */
class ApplicationsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request        The current HTTP request
     * @param LoggerInterface $logger         PSR logger for diagnostics
     * @param ObjectService   $objectService  OpenRegister object service (hard dep via info.xml)
     * @param RegisterMapper  $registerMapper Resolves slugs/UUIDs to numeric register IDs
     * @param SchemaMapper    $schemaMapper   Resolves slugs/UUIDs to numeric schema IDs
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the stored manifest JSON blob for a given virtual-app slug.
     *
     * Lookup path: slug → BuiltAppRoute → applicationUuid → Application →
     * manifest. The manifest is returned UNWRAPPED (no OR envelope) so
     * useAppManifest in @conduction/nextcloud-vue consumes it directly.
     *
     * Visibility model
     * ----------------
     * Manifests are publicly readable to every authenticated user in the
     * org. `#[NoAdminRequired]` is intentional: the hello-world seed app
     * (and any future "always-on" virtual app) is publicly mountable as
     * soon as a route exists. The manifest body contains the structural
     * description of the UI (routes, widgets, endpoints) but no row-level
     * data — that is fetched separately through OpenRegister and goes
     * through OR's own authorisation layer.
     *
     * Future role-scoped manifests (admin-only apps, group-restricted
     * apps) must NOT be implemented by hardening this endpoint. The
     * canonical extension point is the BuiltAppRoute schema itself: add
     * a `restrictToGroup` (or similar) property and filter the route
     * lookup above on the current user's groups. That keeps the visibility
     * model declarative and avoids scattering per-endpoint ACL logic.
     * Tracked for the RBAC spec (PR #6 / feature/spec-openbuilt-rbac).
     *
     * @param string $slug The virtual-app slug from the URL
     *
     * @return JSONResponse The manifest blob, or a 404 envelope when not found
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getManifest(string $slug): JSONResponse
    {
        try {
            // Resolve register + schema slugs to numeric IDs. OR's searchObjects
            // expects numeric IDs in @self; the slug-resolution shortcut isn't
            // applied at this layer (verified during smoke-test 2026-05-11).
            // _multitenancy=false bypasses the org filter on the LOOKUP only —
            // object-level multitenancy is still enforced via searchObjects below.
            $registerId  = $this->registerMapper->find('openbuilt', _multitenancy: false)->getId();
            $routeSchema = $this->schemaMapper->find('built-app-route', _multitenancy: false)->getId();

            // Step 1 — resolve slug → applicationUuid via the BuiltAppRoute index.
            // Per OR's ObjectService::searchObjects: query shape is
            // { '@self': { register, schema, ... }, <field>: <value>, ... }
            // where @self holds metadata filters and direct keys filter JSON-payload fields.
            $routeResults = $this->objectService->searchObjects(
                query: [
                    '@self' => [
                        'register' => $registerId,
                        'schema'   => $routeSchema,
                    ],
                    'slug'  => $slug,
                ]
            );

            if (empty($routeResults) === true) {
                $this->logger->debug('OpenBuilt: no BuiltAppRoute found for slug='.$slug);
                return new JSONResponse(
                    data: ['error' => 'not_found', 'message' => 'No published virtual app found for slug '.$slug],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // FindAll renders entities; result entries may be ObjectEntity or arrays.
            $route           = $this->normaliseObject(object: $routeResults[0]);
            $applicationUuid = ($route['applicationUuid'] ?? null);

            if ($applicationUuid === null) {
                $this->logger->warning('OpenBuilt: BuiltAppRoute for slug '.$slug.' is missing applicationUuid');
                return new JSONResponse(
                    data: ['error' => 'inconsistent_state', 'message' => 'Route exists but has no applicationUuid'],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            // Step 2 — load the Application object.
            $application = $this->objectService->find(
                id: $applicationUuid,
                register: 'openbuilt',
                schema: 'application'
            );

            if ($application === null) {
                $this->logger->warning('OpenBuilt: Application '.$applicationUuid.' (for slug '.$slug.') not found');
                return new JSONResponse(
                    data: ['error' => 'inconsistent_state', 'message' => 'Route points to an Application that does not exist'],
                    statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
                );
            }

            $applicationArray = $this->normaliseObject(object: $application);
            $manifest         = ($applicationArray['manifest'] ?? null);

            if ($manifest === null) {
                $this->logger->warning('OpenBuilt: Application '.$applicationUuid.' has no manifest property');
                return new JSONResponse(
                    data: ['error' => 'no_manifest', 'message' => 'Application has no manifest'],
                    statusCode: Http::STATUS_NOT_FOUND
                );
            }

            // Return the manifest UNWRAPPED — useAppManifest expects the bare object.
            return new JSONResponse(data: $manifest, statusCode: Http::STATUS_OK);
        } catch (\Throwable $e) {
            // Generate a correlation ID so the client and server logs share an
            // identifier — operators can grep `correlationId=<id>` in app.log
            // without needing the request timestamp. Per MWest review on PR #2.
            $correlationId = bin2hex(random_bytes(8));
            $this->logger->error(
                'OpenBuilt: getManifest failed for slug '.$slug.': '.$e->getMessage(),
                ['exception' => $e, 'correlationId' => $correlationId, 'slug' => $slug]
            );
            return new JSONResponse(
                data: [
                    'error'         => 'internal_error',
                    'message'       => 'Failed to resolve manifest',
                    'correlationId' => $correlationId,
                ],
                statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end getManifest()

    /**
     * Coerce an OR result entry (ObjectEntity or array) to a plain associative array.
     *
     * FindAll() and find() may return ObjectEntity instances; we normalise to an
     * array so the caller can use array access uniformly. Uses jsonSerialize()
     * when present (the canonical ObjectEntity surface).
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
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
