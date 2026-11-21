<?php

/**
 * Buildiq Icon Controller
 *
 * Thin controller that serves per-application SVG icons backed by
 * IconService's fallback chain (ADR-001, design.md Decision 2).
 *
 * Endpoints:
 *   GET /apps/buildiq/icons/{slug}.svg      → iconLight
 *   GET /apps/buildiq/icons/{slug}-dark.svg → iconDark
 *   GET /apps/buildiq/icons/{slug}-touch.png → touchIcon (a PNG of the light icon, when the server can rasterise SVG)
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
 * @package  OCA\Buildiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\IconServiceInterface;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\Icon\SvgRasteriser;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
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
	 * @param IconServiceInterface $iconService The icon-resolution service.
	 * @param CallerContext $caller The caller's session (the authentication guard) and the organisation guard.
	 * @param LoggerInterface $logger PSR logger.
	 * @param SvgRasteriser $rasteriser Turns the SVG icon into the PNG touch icon.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IconServiceInterface $iconService,
		private readonly CallerContext $caller,
		private readonly LoggerInterface $logger,
		private readonly SvgRasteriser $rasteriser=new SvgRasteriser(),
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 * @no-admin-idor-exempt The caller-supplied slug only picks an icon: the service gets the caller's
	 *                        organisation check (`seesApplication`) and answers an app the caller may not
	 *                        see with the built-in icon, as it answers an unknown slug (REQ-BQOS-002).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function iconLight(string $slug): Response {
		if ($this->caller->userSession->getUser() === null) {
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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 * @no-admin-idor-exempt The caller-supplied slug only picks an icon: the service gets the caller's
	 *                        organisation check (`seesApplication`) and answers an app the caller may not
	 *                        see with the built-in icon, as it answers an unknown slug (REQ-BQOS-002).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function iconDark(string $slug): Response {
		if ($this->caller->userSession->getUser() === null) {
			$response = new Response();
			$response->setStatus(Http::STATUS_UNAUTHORIZED);
			return $response;
		}

		return $this->buildIconResponse(slug: $slug, dark: true);
	}//end iconDark()

	/**
	 * GET /icons/{slug}-touch.png — the installed app's touch icon: the light
	 * icon as a PNG of 180 by 180 pixels. 404 when the server cannot
	 * rasterise SVG or the app has no icon, so the page and the web app
	 * manifest keep the SVG (pages-installable-offline-and-mobile T02).
	 *
	 * @param string $slug The app slug (path param).
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
	 * @no-admin-idor-exempt The caller-supplied slug only picks an icon: the service gets the caller's
	 *                        organisation check (`seesApplication`) and answers an app the caller may not
	 *                        see with the built-in icon, as it answers an unknown slug (REQ-BQOS-002).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function touchIcon(string $slug): Response {
		if ($this->caller->userSession->getUser() === null) {
			$response = new Response();
			$response->setStatus(Http::STATUS_UNAUTHORIZED);
			return $response;
		}

		$png = null;
		try {
			['stream' => $stream, 'mimeType' => $mimeType] = $this->iconService->getIconStream(
				slug: $slug,
				dark: false,
				mayServe: fn (array $application): bool => $this->caller->seesApplication(application: $application)
			);
			$png = $this->pngOf(stream: $stream, mimeType: (string)$mimeType);
		} catch (\Throwable $e) {
			$this->logger->warning('IconController: no touch icon for slug "' . $slug . '": ' . $e->getMessage());
		}

		if ($png === null) {
			$response = new Response();
			$response->setStatus(Http::STATUS_NOT_FOUND);
			return $response;
		}

		$response = new DataDisplayResponse($png, Http::STATUS_OK, ['Content-Type' => 'image/png']);
		$response->addHeader('Cache-Control', self::CACHE_CONTROL);
		$response->addHeader('X-Content-Type-Options', 'nosniff');

		return $response;
	}//end touchIcon()

	/**
	 * The icon as PNG bytes: a PNG as it is, an SVG rasterised, else null.
	 *
	 * @param mixed  $stream   The icon stream, or null.
	 * @param string $mimeType Its media type.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/pages-installable-offline-and-mobile/tasks.md#T02
	 */
	private function pngOf(mixed $stream, string $mimeType): ?string {
		if (is_resource($stream) === false) {
			return null;
		}

		$bytes = stream_get_contents($stream);
		if (is_string($bytes) === false || $bytes === '') {
			return null;
		}

		if ($mimeType === 'image/png') {
			return $bytes;
		}

		if ($mimeType !== 'image/svg+xml') {
			return null;
		}

		return $this->rasteriser->toPng(svg: $bytes);
	}//end pngOf()

	/**
	 * Shared icon-response builder.
	 *
	 * Calls IconService, sets required headers, streams the resource. Another
	 * organisation's scoped app gets the built-in icon, as an unknown slug does
	 * (operate-organisation-scoped-apps REQ-BQOS-002).
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
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-2
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-3
	 */
	private function buildIconResponse(string $slug, bool $dark): Response {
		try {
			['stream' => $stream, 'mimeType' => $mimeType] = $this->iconService->getIconStream(
				slug: $slug,
				dark: $dark,
				mayServe: fn (array $application): bool => $this->caller->seesApplication(application: $application)
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
