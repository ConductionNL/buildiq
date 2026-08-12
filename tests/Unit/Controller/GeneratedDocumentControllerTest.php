<?php

/**
 * Unit tests for GeneratedDocumentController.
 *
 * Covers the `download-link` output mode's public resolver: a valid,
 * unexpired token streams the artifact; a malformed token, an unknown
 * token, and an expired token all resolve to a uniform 404 (never
 * distinguishing "unknown" from "expired" — mirrors ShareTokenService).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-document-action/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\GeneratedDocumentController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see GeneratedDocumentController}.
 */
final class GeneratedDocumentControllerTest extends TestCase {
	/**
	 * @var IAppDataFactory&MockObject
	 */
	private IAppDataFactory&MockObject $appDataFactory;

	/**
	 * Controller under test.
	 *
	 * @var GeneratedDocumentController
	 */
	private GeneratedDocumentController $controller;

	/**
	 * Valid-shaped 48-hex-char token (matches `bin2hex(random_bytes(24))`).
	 */
	private const TOKEN = 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4';

	/**
	 * Set up mocks + SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->appDataFactory = $this->createMock(IAppDataFactory::class);

		$this->controller = new GeneratedDocumentController(
			$request,
			$this->appDataFactory,
			new NullLogger()
		);

	}//end setUp()

	/**
	 * Build the appdata → generated-documents → {token} folder chain,
	 * carrying the given metadata + document bytes.
	 *
	 * @param array<string,mixed> $meta The `meta.json` payload.
	 * @param string $body The `document` file's bytes.
	 *
	 * @return void
	 */
	private function wireToken(array $meta, string $body = '%PDF-bytes%'): void {
		$metaFile = $this->createMock(ISimpleFile::class);
		$metaFile->method('getContent')->willReturn(json_encode($meta));

		$docFile = $this->createMock(ISimpleFile::class);
		$docFile->method('getContent')->willReturn($body);

		$tokenFolder = $this->createMock(ISimpleFolder::class);
		$tokenFolder->method('getFile')->willReturnMap([
			['meta.json', $metaFile],
			['document', $docFile],
		]);
		$tokenFolder->method('delete');

		$rootFolder = $this->createMock(ISimpleFolder::class);
		$rootFolder->method('getFolder')->with(self::TOKEN)->willReturn($tokenFolder);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('generated-documents')->willReturn($rootFolder);
		$this->appDataFactory->method('get')->with('openbuild')->willReturn($appData);

	}//end wireToken()

	/**
	 * A valid, unexpired token streams the document.
	 *
	 * @return void
	 */
	public function testValidTokenStreamsDocument(): void {
		$this->wireToken(['filename' => 'letter.pdf', 'contentType' => 'application/pdf', 'expiresAt' => (time() + 3600)]);

		$response = $this->controller->download(self::TOKEN);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);

	}//end testValidTokenStreamsDocument()

	/**
	 * A malformed token (wrong shape) never touches storage — uniform 404.
	 *
	 * @return void
	 */
	public function testMalformedTokenIsNotFound(): void {
		$this->appDataFactory->expects($this->never())->method('get');

		$response = $this->controller->download('not-a-valid-token');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMalformedTokenIsNotFound()

	/**
	 * An unknown (never-issued) token resolves to 404.
	 *
	 * @return void
	 */
	public function testUnknownTokenIsNotFound(): void {
		$appData = $this->createMock(IAppData::class);
		$rootFolder = $this->createMock(ISimpleFolder::class);
		$rootFolder->method('getFolder')->willThrowException(new NotFoundException());
		$appData->method('getFolder')->willReturn($rootFolder);
		$this->appDataFactory->method('get')->willReturn($appData);

		$response = $this->controller->download(self::TOKEN);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUnknownTokenIsNotFound()

	/**
	 * An expired token is deleted and resolves to 404 — same uniform status
	 * as "unknown" (never leaks which case it was).
	 *
	 * @return void
	 */
	public function testExpiredTokenIsDeletedAndNotFound(): void {
		$metaFile = $this->createMock(ISimpleFile::class);
		$metaFile->method('getContent')->willReturn(json_encode(['filename' => 'x.pdf', 'contentType' => 'application/pdf', 'expiresAt' => (time() - 10)]));

		$tokenFolder = $this->createMock(ISimpleFolder::class);
		$tokenFolder->method('getFile')->willReturn($metaFile);
		$tokenFolder->expects($this->once())->method('delete');

		$rootFolder = $this->createMock(ISimpleFolder::class);
		$rootFolder->method('getFolder')->willReturn($tokenFolder);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($rootFolder);
		$this->appDataFactory->method('get')->willReturn($appData);

		$response = $this->controller->download(self::TOKEN);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testExpiredTokenIsDeletedAndNotFound()
}//end class
