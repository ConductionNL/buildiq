<?php

/**
 * OpenBuilt Icon Controller
 *
 * Thin controller that serves per-application SVG icons backed by
 * IconService's fallback chain (ADR-001, design.md Decision 2).
 *
 * Endpoints:
 *   GET /apps/openbuilt/icons/{slug}.svg      → iconLight
 *   GET /apps/openbuilt/icons/{slug}-dark.svg → iconDark
 *
 * Both methods:
 *   - Require any valid NC session (#[NoAdminRequired]).
 *   - Set Content-Type: image/svg+xml.
 *   - Set Cache-Control: public, max-age=60 (design.md Decision 7).
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
use OCA\OpenBuilt\Service\IconService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Serves per-application SVG icons with an OR-backed fallback chain.
 */
class IconController extends Controller
{
    /**
     * Cache-Control header value applied to every successful icon response.
     *
     * 60-second public TTL per design.md Decision 7.
     */
    private const CACHE_CONTROL = 'public, max-age=60';

    /**
     * Constructor.
     *
     * @param IRequest        $request     The incoming HTTP request.
     * @param IconService     $iconService The icon-resolution service.
     * @param IUserSession    $userSession User session for authentication guard.
     * @param LoggerInterface $logger      PSR logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IconService $iconService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Serve the light SVG icon for a virtual app identified by slug.
     *
     * Fallback chain (REQ-OBICON-002 / design.md Decision 2):
     *   icon.ref → /img/app.svg
     *
     * @param string $slug The Application slug.
     *
     * @return Response The SVG response or a 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function iconLight(string $slug): Response
    {
        if ($this->userSession->getUser() === null) {
            $response = new Response();
            $response->setStatus(Http::STATUS_UNAUTHORIZED);
            return $response;
        }

        return $this->buildIconResponse(slug: $slug, dark: false);
    }//end iconLight()

    /**
     * Serve the dark SVG icon for a virtual app identified by slug.
     *
     * Fallback chain (REQ-OBICON-003 / design.md Decision 2):
     *   iconDark.ref → icon.ref → /img/app-dark.svg → /img/app.svg
     *
     * @param string $slug The Application slug.
     *
     * @return Response The SVG response or a 404.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function iconDark(string $slug): Response
    {
        if ($this->userSession->getUser() === null) {
            $response = new Response();
            $response->setStatus(Http::STATUS_UNAUTHORIZED);
            return $response;
        }

        return $this->buildIconResponse(slug: $slug, dark: true);
    }//end iconDark()

    /**
     * Shared icon-response builder.
     *
     * Calls IconService, sets required headers, streams the resource.
     * When the service returns a null stream (no icon at all), returns 404.
     * Session guard is enforced by the calling public method before this is
     * invoked, per ADR-005 / hydra gate-7 (design.md Decision 6).
     *
     * @param string $slug The Application slug.
     * @param bool   $dark True for the dark fallback chain.
     *
     * @return Response
     */
    private function buildIconResponse(string $slug, bool $dark): Response
    {
        try {
            ['stream' => $stream, 'mimeType' => $mimeType] = $this->iconService->getIconStream(
                slug: $slug,
                dark: $dark
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'IconController: unexpected error for slug "'.$slug.'": '.$e->getMessage()
            );
            $response = new Response();
            $response->setStatus(Http::STATUS_INTERNAL_SERVER_ERROR);
            return $response;
        }

        if ($stream === null) {
            $response = new Response();
            $response->setStatus(Http::STATUS_NOT_FOUND);
            return $response;
        }

        $response = new StreamResponse($stream);
        $response->setStatus(Http::STATUS_OK);
        $response->addHeader('Content-Type', $mimeType);
        $response->addHeader('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }//end buildIconResponse()
}//end class
