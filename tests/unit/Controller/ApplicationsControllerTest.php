<?php

/**
 * Unit tests for ApplicationsController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Controller
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

namespace OCA\OpenBuilt\Tests\Unit\Controller;

use OCA\OpenBuilt\Controller\ApplicationsController;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationsController::getManifest.
 */
class ApplicationsControllerTest extends TestCase
{
    /**
     * Controller under test.
     *
     * @var ApplicationsController
     */
    private ApplicationsController $controller;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $request             = $this->createMock(IRequest::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);

        // RegisterMapper + SchemaMapper mocks: both expose ->find() returning
        // a Register/Schema entity respectively. The mappers' return types
        // are `: Register` and `: Schema` so we must mock the concrete
        // entity classes (PHPUnit IncompatibleReturnValueException otherwise).
        $registerEntity = $this->createMock(Register::class);
        $registerEntity->method('getId')->willReturn(926);
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->willReturn($registerEntity);

        $schemaEntity = $this->createMock(Schema::class);
        $schemaEntity->method('getId')->willReturn(1635);
        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->willReturn($schemaEntity);

        $this->controller = new ApplicationsController(
            request: $request,
            logger: $this->logger,
            objectService: $this->objectService,
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
        );
    }//end setUp()

    /**
     * Happy path — slug resolves to a published Application; manifest is
     * returned unwrapped (no OR envelope) so useAppManifest can consume it
     * directly.
     *
     * @return void
     */
    public function testGetManifestReturns200WithUnwrappedManifest(): void
    {
        $manifest = [
            'version' => '1.0.0',
            'menu'    => [],
            'pages'   => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
        ];

        $this->objectService->method('searchObjects')
            ->willReturn([['applicationUuid' => 'abc-123']]);

        $this->objectService->method('find')
            ->willReturn(['manifest' => $manifest]);

        $result = $this->controller->getManifest(slug: 'hello-world');

        self::assertInstanceOf(JSONResponse::class, $result);
        self::assertSame(Http::STATUS_OK, $result->getStatus());
        self::assertSame($manifest, $result->getData());
        // Manifest is returned UNWRAPPED — no envelope keys leak.
        self::assertArrayNotHasKey('data', $result->getData());
        self::assertArrayNotHasKey('error', $result->getData());
    }//end testGetManifestReturns200WithUnwrappedManifest()

    /**
     * Unknown slug → 404 with not_found error code.
     *
     * @return void
     */
    public function testGetManifestReturns404WhenSlugUnknown(): void
    {
        $this->objectService->method('searchObjects')->willReturn([]);

        $result = $this->controller->getManifest(slug: 'no-such-app');

        self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $data = $result->getData();
        self::assertSame('not_found', $data['error']);
    }//end testGetManifestReturns404WhenSlugUnknown()

    /**
     * Inconsistent state — route exists but no applicationUuid → 500.
     *
     * @return void
     */
    public function testGetManifestReturns500WhenRouteMissingApplicationUuid(): void
    {
        $this->objectService->method('searchObjects')
            ->willReturn([['slug' => 'hello-world']]);

        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $this->controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        self::assertSame('inconsistent_state', $data['error']);
    }//end testGetManifestReturns500WhenRouteMissingApplicationUuid()

    /**
     * Inconsistent state — BuiltAppRoute points to an applicationUuid that
     * no longer resolves to an Application (the Application was deleted
     * but the route survived). The controller MUST return a 500 with the
     * `inconsistent_state` error code rather than leaking a 404 (which is
     * reserved for unknown slugs).
     *
     * @return void
     */
    public function testGetManifestReturns500OnInconsistentState(): void
    {
        $this->objectService->method('searchObjects')
            ->willReturn([['applicationUuid' => 'dangling-uuid']]);

        // find() returns null — Application no longer exists.
        $this->objectService->method('find')->willReturn(null);

        $this->logger->expects(self::atLeastOnce())->method('warning');

        $result = $this->controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        self::assertSame('inconsistent_state', $data['error']);
    }//end testGetManifestReturns500OnInconsistentState()

    /**
     * Unexpected throwable (e.g. DB exception) → 500 with internal_error
     * AND a correlationId in both the response envelope and the log
     * context, so operators can correlate the client-facing error with
     * the server-side stack trace. Regression guard for MWest review
     * finding #4 on PR #2.
     *
     * @return void
     */
    public function testGetManifestIncludesCorrelationIdOnInternalError(): void
    {
        // Force an unexpected throwable from the OR layer.
        $this->objectService->method('searchObjects')
            ->willThrowException(new \RuntimeException('boom'));

        $loggedContext = null;
        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->willReturnCallback(static function (string $msg, array $context = []) use (&$loggedContext): void {
                $loggedContext = $context;
            });

        $result = $this->controller->getManifest(slug: 'hello-world');

        self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $result->getStatus());
        $data = $result->getData();
        self::assertSame('internal_error', $data['error']);

        // Response envelope carries the correlationId (16 lowercase hex chars).
        self::assertArrayHasKey('correlationId', $data);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['correlationId']);

        // Log context carries the SAME correlationId — operators can grep for it.
        self::assertIsArray($loggedContext);
        self::assertArrayHasKey('correlationId', $loggedContext);
        self::assertSame($data['correlationId'], $loggedContext['correlationId']);
    }//end testGetManifestIncludesCorrelationIdOnInternalError()
}//end class
