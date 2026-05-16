<?php

/**
 * Unit tests for IconService.
 *
 * Covers REQ-OBICON-002 (light fallback chain) and REQ-OBICON-003 (dark
 * fallback chain) including OR-failure fallback and unknown-slug 404 case.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Service
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

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\IconService;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see IconService}.
 */
class IconServiceTest extends TestCase
{
    /**
     * Mock ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock FileService.
     *
     * @var FileService&MockObject
     */
    private FileService&MockObject $fileService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Service under test.
     */
    private IconService $service;

    /**
     * Build the dependency mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService = $this->createMock(ObjectService::class);
        $this->fileService   = $this->createMock(FileService::class);
        $this->logger        = $this->createMock(LoggerInterface::class);

        // Pass a temp dir as serverRoot so tests don't depend on \OC::$SERVERROOT.
        $this->service = new IconService(
            $this->objectService,
            $this->fileService,
            $this->logger,
            sys_get_temp_dir()
        );
    }//end setUp()

    // -------------------------------------------------------------------------
    // Happy path — light icon
    // -------------------------------------------------------------------------

    /**
     * Light icon: when icon.ref resolves to an OR-attached file, return its stream.
     *
     * @return void
     */
    public function testGetIconStreamLightHappyPath(): void
    {
        $svgContent = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';

        $application = [
            'slug' => 'hello-world',
            'uuid' => 'app-uuid-1',
            'icon' => ['ref' => 'app-icon.svg'],
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$application]);

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getContent')->willReturn($svgContent);

        $this->fileService
            ->expects($this->once())
            ->method('getFile')
            ->with('app-uuid-1', 'app-icon.svg')
            ->willReturn($fileMock);

        $result = $this->service->getIconStream(slug: 'hello-world', dark: false);

        $this->assertSame('image/svg+xml', $result['mimeType']);
        $this->assertIsResource($result['stream']);

        $body = stream_get_contents($result['stream']);
        $this->assertSame($svgContent, $body);

        fclose($result['stream']);
    }//end testGetIconStreamLightHappyPath()

    // -------------------------------------------------------------------------
    // Happy path — dark icon (iconDark.ref present)
    // -------------------------------------------------------------------------

    /**
     * Dark icon: when iconDark.ref resolves to an attached file, return it.
     *
     * @return void
     */
    public function testGetIconStreamDarkHappyPath(): void
    {
        $darkSvg = '<svg fill="#fff"></svg>';

        $application = [
            'slug'     => 'hello-world',
            'uuid'     => 'app-uuid-1',
            'icon'     => ['ref' => 'app-icon.svg'],
            'iconDark' => ['ref' => 'app-icon-dark.svg'],
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn([$application]);

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getContent')->willReturn($darkSvg);

        $this->fileService
            ->expects($this->once())
            ->method('getFile')
            ->with('app-uuid-1', 'app-icon-dark.svg')
            ->willReturn($fileMock);

        $result = $this->service->getIconStream(slug: 'hello-world', dark: true);

        $this->assertSame('image/svg+xml', $result['mimeType']);
        $this->assertIsResource($result['stream']);

        $body = stream_get_contents($result['stream']);
        $this->assertSame($darkSvg, $body);

        fclose($result['stream']);
    }//end testGetIconStreamDarkHappyPath()

    // -------------------------------------------------------------------------
    // Dark chain: iconDark absent → falls through to icon.ref
    // -------------------------------------------------------------------------

    /**
     * Dark icon: no iconDark field → falls back to icon.ref.
     *
     * @return void
     */
    public function testGetIconStreamDarkFallsBackToLightRef(): void
    {
        $lightSvg = '<svg fill="#4376FC"></svg>';

        $application = [
            'slug' => 'hello-world',
            'uuid' => 'app-uuid-1',
            'icon' => ['ref' => 'app-icon.svg'],
            // intentionally no iconDark
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn([$application]);

        $fileMock = $this->createMock(File::class);
        $fileMock->method('getContent')->willReturn($lightSvg);

        $this->fileService
            ->expects($this->once())
            ->method('getFile')
            ->with('app-uuid-1', 'app-icon.svg')
            ->willReturn($fileMock);

        $result = $this->service->getIconStream(slug: 'hello-world', dark: true);

        $this->assertIsResource($result['stream']);
        $body = stream_get_contents($result['stream']);
        $this->assertSame($lightSvg, $body);

        fclose($result['stream']);
    }//end testGetIconStreamDarkFallsBackToLightRef()

    // -------------------------------------------------------------------------
    // OR failure → filesystem fallback (stream may be null if no /img file)
    // -------------------------------------------------------------------------

    /**
     * When ObjectService throws, the method falls back gracefully (returns
     * null stream and the correct MIME type rather than propagating the error).
     *
     * @return void
     */
    public function testGetIconStreamFallsBackWhenOrThrows(): void
    {
        $this->objectService
            ->method('findAll')
            ->willThrowException(new \RuntimeException('OR unavailable'));

        $this->logger->expects($this->once())->method('warning');

        // The FileService should not be called when OR fails.
        $this->fileService->expects($this->never())->method('getFile');

        $result = $this->service->getIconStream(slug: 'hello-world', dark: false);

        // Stream may be null when no /img/app.svg exists in the test context.
        $this->assertSame('image/svg+xml', $result['mimeType']);
        if ($result['stream'] !== null) {
            fclose($result['stream']);
        }
    }//end testGetIconStreamFallsBackWhenOrThrows()

    // -------------------------------------------------------------------------
    // Unknown slug — no Application found → filesystem fallback
    // -------------------------------------------------------------------------

    /**
     * Unknown slug: ObjectService returns empty list → fallback stream path.
     *
     * @return void
     */
    public function testGetIconStreamUnknownSlugFallsBack(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $this->fileService->expects($this->never())->method('getFile');

        $result = $this->service->getIconStream(slug: 'no-such-app', dark: false);

        $this->assertSame('image/svg+xml', $result['mimeType']);
        // Stream may be null in the test context (no /img/ dir present).
        if ($result['stream'] !== null) {
            fclose($result['stream']);
        }
    }//end testGetIconStreamUnknownSlugFallsBack()

    // -------------------------------------------------------------------------
    // FileService throws → falls back to filesystem
    // -------------------------------------------------------------------------

    /**
     * When getFile throws (e.g. OR file not accessible), fall through to
     * the next step in the fallback chain.
     *
     * @return void
     */
    public function testGetIconStreamFallsBackWhenFileServiceThrows(): void
    {
        $application = [
            'slug' => 'hello-world',
            'uuid' => 'app-uuid-1',
            'icon' => ['ref' => 'app-icon.svg'],
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn([$application]);

        $this->fileService
            ->method('getFile')
            ->willThrowException(new \RuntimeException('file not found'));

        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->getIconStream(slug: 'hello-world', dark: false);

        $this->assertSame('image/svg+xml', $result['mimeType']);
        if ($result['stream'] !== null) {
            fclose($result['stream']);
        }
    }//end testGetIconStreamFallsBackWhenFileServiceThrows()
}//end class
