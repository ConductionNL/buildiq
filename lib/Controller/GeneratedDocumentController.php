<?php

/**
 * OpenBuild GeneratedDocumentController
 *
 * Serves the `download-link` output mode of the `generateDocument`
 * automation action (automation-document-action design.md Decision 3).
 * `DocumentGenerationService::writeDownloadLink()` writes the generated
 * document's bytes into OpenBuild's OWN app-private storage
 * (`OCP\Files\IAppData` — never the user's Files tree) behind a random
 * token with a metadata sidecar carrying a ~24h expiry. This controller is
 * the ONLY reader of that storage: it resolves the token, rejects (404,
 * uniform — never distinguishes "unknown" from "expired", mirroring
 * `ShareTokenService`'s fail-closed posture) an unknown or expired token,
 * lazily deletes an expired artifact on the access that discovers it (no
 * dedicated cleanup background job in v1 — a documented, non-blocking
 * follow-up for artifacts that expire without ever being fetched, mirrors
 * design.md's Risk/Trade-off note on `attach`-mode storage growth being
 * likewise unrate-limited in v1), and otherwise streams the bytes.
 *
 * `#[PublicPage]` — the whole point of a "download link" surfaced via a
 * notification is that it works without an authenticated NC session; the
 * random 24-byte token IS the authorization (matches `ShareToken`'s own
 * opaque-token-is-the-authorization model).
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
 * @spec openspec/changes/automation-document-action/tasks.md#2.4
 * @spec openspec/changes/automation-document-action/specs/automation-document-action/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\DocumentGenerationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Public (anonymous) download-link resolver for `generateDocument` output.
 *
 * @spec openspec/changes/automation-document-action/tasks.md#2.4
 */
class GeneratedDocumentController extends Controller
{
    /**
     * Appdata folder name (matches {@see DocumentGenerationService::APPDATA_FOLDER}).
     *
     * @spec openspec/changes/automation-document-action/tasks.md#2.4
     */
    private const APPDATA_FOLDER = 'generated-documents';

    /**
     * Constructor.
     *
     * @param IRequest        $request        Current HTTP request.
     * @param IAppDataFactory $appDataFactory Resolves OpenBuild's app-private storage.
     * @param LoggerInterface $logger         PSR logger.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly IAppDataFactory $appDataFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Stream a `download-link`-mode generated document, or a uniform 404 for
     * any unknown/expired/malformed token.
     *
     * @param string $token The random download token.
     *
     * @return DataDownloadResponse|JSONResponse
     *
     * @NoAdminRequired
     * @PublicPage
     * @NoCSRFRequired
     *
     * @spec openspec/changes/automation-document-action/tasks.md#2.4
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[AnonRateLimit(limit: 30, period: 60)]
    public function download(string $token): DataDownloadResponse|JSONResponse
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            return $this->notFound();
        }

        try {
            $folder = $this->appDataFactory->get('openbuild')
                ->getFolder(self::APPDATA_FOLDER)
                ->getFolder($token);

            $meta = json_decode($folder->getFile('meta.json')->getContent(), true);
        } catch (Throwable $e) {
            return $this->notFound();
        }

        if (is_array($meta) === false) {
            return $this->notFound();
        }

        $expiresAt = (int) ($meta['expiresAt'] ?? 0);
        if ($expiresAt <= time()) {
            $this->deleteQuietly(folder: $folder);
            return $this->notFound();
        }

        try {
            $body        = $folder->getFile('document')->getContent();
            $filename    = (string) ($meta['filename'] ?? 'document.pdf');
            $contentType = (string) ($meta['contentType'] ?? 'application/octet-stream');
        } catch (Throwable $e) {
            return $this->notFound();
        }

        return new DataDownloadResponse($body, $filename, $contentType);

    }//end download()

    /**
     * Best-effort delete of an expired artifact — never throws.
     *
     * @param ISimpleFolder $folder The token's folder.
     *
     * @return void
     */
    private function deleteQuietly(ISimpleFolder $folder): void
    {
        try {
            $folder->delete();
        } catch (Throwable $e) {
            $this->logger->warning('OpenBuild: GeneratedDocumentController could not delete an expired artifact: '.$e->getMessage());
        }

    }//end deleteQuietly()

    /**
     * Uniform 404 — never distinguishes "unknown" from "expired" (mirrors
     * `ShareTokenService`'s fail-closed posture).
     *
     * @return JSONResponse
     */
    private function notFound(): JSONResponse
    {
        return new JSONResponse(data: ['error' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);

    }//end notFound()
}//end class
