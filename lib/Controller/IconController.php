<?php

/**
 * OpenBuild Icon Controller
 *
 * Thin controller that serves per-application SVG icons backed by
 * IconService's fallback chain (ADR-001, design.md Decision 2).
 *
 * Endpoints:
 *   GET /apps/openbuild/icons/{slug}.svg      → iconLight
 *   GET /apps/openbuild/icons/{slug}-dark.svg → iconDark
 *
 * Both methods:
 *   - Require any valid NC session (#[NoAdminRequired]).
 *   - Set Content-Type: image/svg+xml.
 *   - Set Content-Security-Policy: default-src 'none' to neutralise
 *     any scripts/resources embedded in user-supplied SVG payloads (#164).
 *   - Set X-Content-Type-Options: nosniff (#164).
 *   - Set Cache-Control: private, max-age=60. NOTE: these endpoints enforce
 *     session-only access (any authenticated user; there is NO per-app viewer
 *     check), so `private` is a conservative no-shared-cache default rather than
 *     a per-caller access-scoping guarantee (harden-rules-authz-and-audit-parity, L4).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\IconService;
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
class IconController extends Controller {
	/**
	 * Cache-Control header value applied to every successful icon response.
	 *
	 * Private (not publicly cacheable) to prevent cross-user icon leakage,
	 * with a 60-second revalidation window (design.md Decision 7, updated
	 * by security fix #164).
	 */
	private const CACHE_CONTROL = 'private, max-age=60';

	/**
	 * Content-Security-Policy applied to SVG responses to neutralise any
	 * scripts or external resource loads embedded in user-supplied SVG
	 * content (issue #164 — SVG XSS mitigation).
	 */
	private const SVG_CSP = "default-src 'none'";

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming HTTP request.
	 * @param IconService $iconService The icon-resolution service.
	 * @param IUserSession $userSession User session for authentication guard.
	 * @param LoggerInterface $logger PSR logger.
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function iconLight(string $slug): Response {
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function iconDark(string $slug): Response {
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
	 * Security headers applied to every SVG response (issue #164):
	 *   - Content-Security-Policy: default-src 'none'  → neutralises embedded
	 *     scripts / external resources in user-supplied SVG payloads.
	 *   - X-Content-Type-Options: nosniff              → prevents MIME-sniffing.
	 *   - Cache-Control: private, max-age=60            → no shared-cache leakage.
	 *
	 * @param string $slug The Application slug.
	 * @param bool $dark True for the dark fallback chain.
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 */
	private function buildIconResponse(string $slug, bool $dark): Response {
		try {
			['stream' => $stream, 'mimeType' => $mimeType] = $this->iconService->getIconStream(
				slug: $slug,
				dark: $dark
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'IconController: unexpected error for slug "' . $slug . '": ' . $e->getMessage()
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
		// XSS mitigation: neutralise scripts/external resources in SVG payloads.
		$response->addHeader('Content-Security-Policy', self::SVG_CSP);
		$response->addHeader('X-Content-Type-Options', 'nosniff');

		return $response;
	}//end buildIconResponse()
}//end class
